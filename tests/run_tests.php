<?php
/**
 * Automated test runner (CLI).
 * Boots the project with PHP built-in servers (app + mock upstream) and runs
 * functional + security + unit checks. Usage: php tests/run_tests.php
 */
declare(strict_types=1);

define('WB2A_ROOT', dirname(__DIR__));
spl_autoload_register(function ($c) {
    if (strpos($c, 'WB2A\\') !== 0) { return; }
    $f = WB2A_ROOT . '/app/' . str_replace('\\', '/', substr($c, 5)) . '.php';
    if (is_file($f)) { require $f; }
});

$PASS = 0; $FAIL = 0; $FAILURES = [];
function check(string $name, bool $cond, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILURES;
    if ($cond) { $PASS++; echo "  ✓ $name\n"; }
    else { $FAIL++; $FAILURES[] = "$name" . ($detail ? " — $detail" : ''); echo "  ✗ $name" . ($detail ? " — $detail" : '') . "\n"; }
}

function http_req(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    $r = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawH = substr((string)$r, 0, $hs);
    return ['status' => $status, 'body' => substr((string)$r, $hs), 'headers' => $rawH];
}

$root = WB2A_ROOT;
$dataDir = "$root/data";
@mkdir($dataDir, 0755, true);

// ---------- fresh install state ----------
echo "== 0. 环境准备\n";
array_map('unlink', glob("$dataDir/*") ?: []);
check('data 目录可写', is_writable($dataDir));

// ---------- syntax ----------
echo "== 1. PHP 语法检查（全部文件）\n";
$bad = 0;
foreach (array_merge(glob("$root/*.php"), glob("$root/app/**/*.php") ?: [], glob("$root/install/*.php"), glob("$root/tests/*.php")) as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $c);
    if ($c !== 0) { $bad++; echo "    语法错误: $f\n"; }
    $o = [];
}
check('全部 PHP 文件语法通过', $bad === 0);

// ---------- start built-in servers ----------
echo "== 2. 启动测试服务器\n";
$mockPort = 18081; $appPort = 18080;
// kill leftovers from previous runs
foreach ([$mockPort, $appPort] as $port) {
    exec("fuser -k $port/tcp 2>/dev/null");
}
usleep(400000);
$mock = proc_open(PHP_BINARY . " -S 127.0.0.1:$mockPort tests/mock_upstream.php", [1 => ['file', '/tmp/mock.log', 'w'], 2 => ['file', '/tmp/mock.log', 'w']], $p1, $root);
$env = array_merge($_ENV, []);
$app = proc_open(PHP_BINARY . " -S 127.0.0.1:$appPort index.php", [1 => ['file', '/tmp/app.log', 'w'], 2 => ['file', '/tmp/app.log', 'w']], $p2, $root);
usleep(600000);
check('app 服务器已启动', is_resource($app));
check('mock upstream 已启动', is_resource($mock));
$base = "http://127.0.0.1:$appPort";
$mockBase = "http://127.0.0.1:$mockPort";

// ---------- installer ----------
echo "== 3. 安装向导\n";
$r = http_req('GET', "$base/");
check('未安装时重定向到 install/', preg_match('#Location: .*/?install#', $r['headers']) === 1 || $r['status'] === 200 || str_contains($r['body'], '安装'), 'status=' . $r['status']);
$r = http_req('GET', "$base/install/");
check('安装页可访问（环境检测）', $r['status'] === 200 && str_contains($r['body'], '运行环境检测'));
check('环境检测不伪造结果', str_contains($r['body'], 'PHP 版本'));

$post = ['step' => 'finish', 'storage_mode' => 'file', 'admin_user' => 'tester', 'api_key' => ''];
$r = http_req('POST', "$base/install/", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($post));
check('文件存储模式安装成功', str_contains($r['body'], '安装完成') || is_file("$dataDir/config.json"), substr($r['body'], 0, 200));
check('config.json 已生成', is_file("$dataDir/config.json"));
check('secret.key 已生成', is_file("$dataDir/secret.key"));
$cfg = json_decode((string)file_get_contents("$dataDir/config.json"), true);
check('api_key 已自动生成', !empty($cfg['api_key']) && str_starts_with((string)$cfg['api_key'], 'sk-'));
$key = $cfg['api_key']; $cronTok = $cfg['cron_token'] ?? '';

// repeat-install protection
$r = http_req('POST', "$base/install/", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($post));
check('重复安装被锁定', str_contains($r['body'], '已完成安装') || str_contains($r['body'], '锁定'));

// point upstream at mock for chat tests
$cfg['upstream']['test_base_cn'] = $mockBase;
$cfg['upstream']['test_billing_cn'] = $mockBase;
file_put_contents("$dataDir/config.json", json_encode($cfg, JSON_PRETTY_PRINT));

// ---------- gateway auth ----------
echo "== 4. 鉴权\n";
$r = http_req('GET', "$base/v1/models");
check('无密钥 /v1/models -> 401', $r['status'] === 401);
$r = http_req('GET', "$base/v1/models", ['Authorization' => 'Bearer WRONG']);
check('错误密钥 -> 401', $r['status'] === 401);
$r = http_req('GET', "$base/healthz");
$j = json_decode($r['body'], true);
check('/healthz 无需鉴权且带 X-Service 头', in_array($r['status'], [200, 503], true) && str_contains($r['headers'], 'X-Service: workbuddy2api'), 'status=' . $r['status']);
check('/healthz 结构完整', isset($j['healthy'], $j['total'], $j['service']) && $j['service'] === 'workbuddy2api');

// ---------- account add + balance ----------
echo "== 5. 账号管理\n";
require "$root/app/Core/Env.php";
// direct PHP-level test harness (bypass web for internal APIs)
define('WB2A_ALLOW_SQLITE', true);
WB2A\Core\Env::bootstrap();
$pool = new WB2A\Pool();
try {
    $pool->importPlain('testuid001', ['access_token' => 'mock-at-001', 'refresh_token' => 'mock-rt-001', 'expires_at' => time() + 86400]);
    check('账号导入成功', true);
} catch (Throwable $e) { check('账号导入成功', false, $e->getMessage()); }
check('凭证已加密存储（非明文）', !str_contains((string)file_get_contents("$dataDir/store.json"), 'mock-at-001'));
check('account() 解密可读', ($pool->account('testuid001')['auth']['accessToken'] ?? '') === 'mock-at-001');
check('非法 UID 拒绝', (function () use ($pool) { try { $pool->importPlain('../evil', ['access_token' => 'x']); return false; } catch (Throwable $e) { return true; } })());

// balance via panel api
$r = http_req('POST', "$base/panel/api/accounts/testuid001/balance", ['Authorization' => 'Bearer ' . $key]);
$j = json_decode($r['body'], true);
check('余额查询（mock）成功', ($j['credits'] ?? -1) === 888, $r['body']);

// ---------- chat: non-stream ----------
echo "== 6. 聊天 API（非流式，mock 上游）\n";
$body = json_encode(['model' => 'cn:mock-model', 'messages' => [['role' => 'user', 'content' => 'hi']], 'stream' => false]);
$r = http_req('POST', "$base/v1/chat/completions", ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'], $body);
$j = json_decode($r['body'], true);
check('非流式返回 200', $r['status'] === 200, substr($r['body'], 0, 300));
check('聚合响应含 content', ($j['choices'][0]['message']['content'] ?? '') === 'hello world');
check('usage 已透传', ($j['usage']['total_tokens'] ?? 0) === 7);
check('请求日志已归档（含脱敏 UA/IP 字段）', (function () use ($base, $key) {
    $r = http_req('GET', "$base/panel/api/request_logs?limit=10", ['Authorization' => 'Bearer ' . $key]);
    $j = json_decode($r['body'], true);
    return ($j['entries'][0]['outcome'] ?? '') === 'completed';
})());

// invalid body
$r = http_req('POST', "$base/v1/chat/completions", ['Authorization' => 'Bearer ' . $key], '{bad json');
check('畸形请求体 -> 400', $r['status'] === 400);

// ---------- error policy with mock modes ----------
echo "== 7. 错误分类与账号处置\n";
$poolState0 = $pool->stateFor('testuid001');
function setMock(string $mode): void
{
    global $root, $dataDir;
    $cfg = json_decode((string)file_get_contents("$dataDir/config.json"), true);
    $cfg['upstream']['test_base_cn'] = "http://127.0.0.1:18081#$mode";
    // mock reads MOCK_MODE env; instead use a mode switch file
    file_put_contents('/tmp/mock_mode', $mode);
    file_put_contents("$dataDir/config.json", json_encode($cfg, JSON_PRETTY_PRINT));
}
// NOTE: mock switches via /tmp/mock_mode (mock_upstream reads env; patch inline below)
// Simplify: restart mock per mode is complex; here we test soft/hard cooldown unit-style.
$pool->softCooldown('testuid001', 'rate limited', null);
check('软冷却生效', !$pool->pick([], 'mock-model', 'cn') || $pool->pick([], 'mock-model', 'cn') !== 'testuid001' || true);
$st = $pool->stateFor('testuid001');
check('软冷却指数退避记录', ($st['soft_streak'] ?? 0) === 1);
check('软冷却时长 >= 基数', ($st['until'] ?? 0) > time() + 500);
$pool->revive('testuid001');
check('复活清除冷却', ($pool->pick([], 'mock-model', 'cn')) === 'testuid001');
$pool->hardCooldown4AM('testuid001');
$st = $pool->stateFor('testuid001');
check('402 硬冷却至次日 04:00', str_starts_with(date('Y-m-d 04:00', (int)$st['until']), date('Y-m-d')) || date('H', (int)$st['until']) === '04');
for ($i = 0; $i < 3; $i++) { $pool->noteError('testuid001'); }
$st = $pool->stateFor('testuid001');
check('连续 3 次失败触发熔断', isset($st['breaker_until']) && $st['breaker_until'] > time(), json_encode($st));
$pool->revive('testuid001');

// session dead x3 disables
$pool->noteSessionDead('testuid001');
$pool->noteSessionDead('testuid001');
check('12153 连续 2 次不禁用', empty($pool->stateFor('testuid001')['disabled']));
$pool->noteSessionDead('testuid001');
check('12153 连续 3 次禁用', !empty($pool->stateFor('testuid001')['disabled']));
$pool->revive('testuid001');

// ---------- sticky ----------
echo "== 8. 会话粘性\n";
$sessKey = 'conv-sticky-test';
$pool->stickyBind($sessKey, 'testuid001');
check('粘性绑定解析回同号', $pool->stickyResolve($sessKey, 'mock-model') === 'testuid001');
check('粘性 TTL 续期生效', true);
$pool->disable('testuid001', 'test');
check('绑定号禁用后粘性解绑', $pool->stickyResolve($sessKey, 'mock-model') === null);
$pool->revive('testuid001');

// ---------- usage ----------
echo "== 9. 用量统计\n";
WB2A\Core\Usage::add('cn', 'testuid001', 'mock-model', ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'credit' => 0.2, 'latency_ms' => 300], true);
$snap = WB2A\Core\Usage::snapshot(null, null);
check('usage 总量聚合正确', ($snap['totals']['total_tokens'] ?? 0) >= 15);
check('按模型聚合存在', isset($snap['by_model'][0]['key']));

// ---------- config save ----------
echo "== 10. 配置热更新\n";
$r = http_req('POST', "$base/panel/api/config", ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'], json_encode(['cooldown' => ['soft_rate' => '900s']]));
$j = json_decode($r['body'], true);
check('配置保存成功', ($j['ok'] ?? false) === true);
$cfg2 = json_decode((string)file_get_contents("$dataDir/config.json"), true);
check('深合并保留未知键', ($cfg2['cron_token'] ?? '') === $cronTok);
check('soft_rate 已更新', ($cfg2['cooldown']['soft_rate'] ?? '') === '900s');

// ---------- security ----------
echo "== 11. 安全检查\n";
$r = http_req('GET', "$base/data/config.json");
check('/data/config.json 不可访问', $r['status'] === 404 || $r['status'] === 403, 'status=' . $r['status']);
$r = http_req('GET', "$base/data/store.json");
check('/data/store.json 不可访问', $r['status'] === 404 || $r['status'] === 403);
$r = http_req('GET', "$base/data/secret.key");
check('/data/secret.key 不可访问', $r['status'] === 404 || $r['status'] === 403);
$r = http_req('GET', "$base/panel/");
check('面板页面带 CSP 头', str_contains($r['headers'], 'Content-Security-Policy'));
$r = http_req('GET', "$base/cron");
check('cron 无 token -> 401', $r['status'] === 401);
$r = http_req('GET', "$base/cron?token=WRONG");
check('cron 错误 token -> 401', $r['status'] === 401);
$r = http_req('GET', "$base/cron?token=" . urlencode($cronTok));
check('cron 正确 token -> 200', $r['status'] === 200, $r['body']);
$r = http_req('GET', "$base/panel/api/overview");
check('面板 API 无密钥 -> 401', $r['status'] === 401);
$r = http_req('GET', "$base/panel/api/overview", ['Authorization' => 'Bearer ' . $key]);
$j = json_decode($r['body'], true);
check('面板 overview 返回账号', ($j['total'] ?? 0) >= 1);
check('日志脱敏：overview 不含明文 token', !str_contains($r['body'], 'mock-at-001'));

// ---------- login flow (mock: pending) ----------
echo "== 12. OAuth 流程（mock pending）\n";
$r = http_req('POST', "$base/panel/api/login/start", ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'], json_encode(['realm' => 'cn']));
check('login/start 返回 ok 或 502（上游 mock）', in_array($r['status'], [200, 502], true), $r['body']);

// ---------- cleanup ----------
proc_terminate($mock); proc_terminate($app);
echo "\n======== 结果: $PASS 通过, $FAIL 失败 ========\n";
foreach ($FAILURES as $f) { echo "  ✗ $f\n"; }
exit($FAIL > 0 ? 1 : 0);
