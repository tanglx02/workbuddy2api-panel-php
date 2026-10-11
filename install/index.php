<?php
/**
 * Browser installer: environment check -> storage setup -> finalize & lock.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
if (!defined('WB2A_ROOT')) { define('WB2A_ROOT', $ROOT); }
define('WB2A_VERSION', '1.0.0');
define('WB2A_INSTALLER', 1);
spl_autoload_register(function ($c) {
    if (strpos($c, 'WB2A\\') !== 0) { return; }
    $f = WB2A_ROOT . '/app/' . str_replace('\\', '/', substr($c, 5)) . '.php';
    if (is_file($f)) { require $f; }
});
WB2A\Core\Env::bootstrap();

$installed = WB2A\Core\Env::installed();
$step = $_POST['step'] ?? ($_GET['step'] ?? 'check');
$err = ''; $done = false; $doneNote = '';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function envChecks(): array
{
    $dataDir = \WB2A\Core\Env::$dataDir !== '' ? \WB2A\Core\Env::$dataDir : WB2A_ROOT . '/data';
    // Real write test: is_writable() often false-negatives on shared hosting
    // (web-server user != FTP user, open_basedir, stat cache). Try an actual write.
    $dataOk = false; $dataMsg = '';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }
    clearstatcache(true, $dataDir);
    if (is_dir($dataDir)) {
        $probe = $dataDir . '/.write_probe_' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') !== false) {
            @unlink($probe);
            $dataOk = true;
            $dataMsg = '可写（实测写入成功）';
            if ($dataDir !== WB2A_ROOT . '/data') {
                $dataMsg .= '——注意：data/ 本身对 Web 运行账号不可写，已自动回退到系统临时目录存储（重启可能丢失，建议在主机面板为 data/ 授予 Web 运行账号「修改」权限后重装）';
            }
        } else {
            $lastErr = error_get_last();
            $dataMsg = '实测写入失败' . (!empty($lastErr['message']) ? '：' . h($lastErr['message']) : '') . '；请将 data/ 目录权限设为 755/775 并确认属主与 Web 运行用户一致';
        }
    } else {
        $dataMsg = 'data/ 目录不存在且自动创建失败，请在网站根目录手动创建 data/ 目录（权限 755 或 775）';
    }
    $drivers = \PDO::getAvailableDrivers();
    return [
        ['PHP 版本 >= 8.1（推荐 8.2+）', version_compare(PHP_VERSION, '8.1.0', '>='), '当前 ' . PHP_VERSION],
        ['PDO 扩展', extension_loaded('pdo'), extension_loaded('pdo') ? '已启用' : '缺失（无法连接数据库）'],
        ['pdo_mysql（MySQL 数据库支持）', in_array('mysql', $drivers, true), in_array('mysql', $drivers, true) ? '已启用' : '未启用：将只能使用受限文件存储模式'],
        ['cURL 扩展（上游 HTTPS 请求）', extension_loaded('curl'), extension_loaded('curl') ? '已启用' : '缺失（网关核心功能不可用）'],
        ['OpenSSL 扩展（凭证加密）', extension_loaded('openssl'), extension_loaded('openssl') ? '已启用' : '未启用：凭证将明文存储（不推荐）'],
        ['JSON 扩展', extension_loaded('json'), extension_loaded('json') ? '已启用' : '缺失'],
        ['mbstring 扩展（中文处理）', extension_loaded('mbstring'), extension_loaded('mbstring') ? '已启用' : '未启用：中文截断可能不准'],
        ['data/ 目录可写', $dataOk, $dataMsg],
        ['PHP 最大执行时间', (int)ini_get('max_execution_time') === 0 || (int)ini_get('max_execution_time') >= 60, '当前 ' . ini_get('max_execution_time') . 's；低于 60s 会限制长对话，需在主机面板调整'],
        ['输出缓冲可关闭（SSE 流式）', (bool)ini_get('output_buffering') === false || (int)ini_get('output_buffering') === 0 || strtolower((string)ini_get('output_buffering')) === 'off', '当前 output_buffering=' . ini_get('output_buffering') . '；流式响应可能被缓冲'],
    ];
}

function outboundHttpsTest(): array
{
    if (!extension_loaded('curl')) { return ['curl 未安装', false]; }
    $ch = curl_init('https://copilot.tencent.com/');
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_RETURNTRANSFER => true]);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [$err === '' ? '出站 HTTPS 正常' : ('出站 HTTPS 失败：' . $err), $err === ''];
}

/** Probe a storage backend: real connect + create + insert + select + drop. */
function storageTest(string $mode, array $db): array
{
    if ($mode === 'file') {
        $dir = \WB2A\Core\Env::$dataDir !== '' ? \WB2A\Core\Env::$dataDir : WB2A_ROOT . '/data';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $probe = $dir . '/.store_probe_' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) { return [false, 'data 目录写入失败（实测）']; }
        @unlink($probe);
        return [true, '文件存储可用：data 目录实测写入成功'];
    }
    if (!in_array($mode, \PDO::getAvailableDrivers(), true)) { return [false, "PDO 驱动 {$mode} 不可用（主机未启用）"]; }
    try {
        if ($mode === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'] ?: 'localhost', (int)($db['port'] ?: 3306), $db['name']);
            $pdo = new \PDO($dsn, $db['user'], $db['pass'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]);
        } else {
            $dir = \WB2A\Core\Env::$dataDir !== '' ? \WB2A\Core\Env::$dataDir : WB2A_ROOT . '/data';
            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
            $probe = $dir . '/.sqlite_probe_' . bin2hex(random_bytes(4));
            $pdo = new \PDO('sqlite:' . $probe, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS wb2a_install_probe (id INT PRIMARY KEY, v VARCHAR(32) NOT NULL)');
        $pdo->exec('DELETE FROM wb2a_install_probe');
        $pdo->prepare('INSERT INTO wb2a_install_probe (id, v) VALUES (1, ?)')->execute([bin2hex(random_bytes(4))]);
        $ok = (string)$pdo->query('SELECT v FROM wb2a_install_probe WHERE id = 1')->fetchColumn() !== '';
        $pdo->exec('DROP TABLE wb2a_install_probe');
        if ($mode === 'sqlite' && isset($probe)) { @unlink($probe); }
        if (!$ok) { return [false, '写入/读回验证失败']; }
        return [true, $mode === 'mysql' ? 'MySQL 连接成功，建表/写入/读回全部通过' : 'SQLite 可用：建表/写入/读回全部通过'];
    } catch (Throwable $e) {
        if ($mode === 'sqlite' && isset($probe)) { @unlink($probe); }
        return [false, $e->getMessage()];
    }
}

// AJAX：安装前实测所选存储（不通过则不允许提交）
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'test') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    [$ok, $msg] = storageTest(
        (string)($_POST['storage_mode'] ?? 'file'),
        ['host' => trim((string)($_POST['db_host'] ?? 'localhost')), 'port' => (int)($_POST['db_port'] ?? 3306),
         'name' => trim((string)($_POST['db_name'] ?? '')), 'user' => trim((string)($_POST['db_user'] ?? '')),
         'pass' => (string)($_POST['db_pass'] ?? '')]
    );
    echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'finish') {
    $mode = in_array($_POST['storage_mode'] ?? 'file', ['mysql', 'sqlite'], true) ? ($_POST['storage_mode'] === 'sqlite' ? 'sqlite' : 'mysql') : 'file';
    $cfg = WB2A\Core\Config::withDefaults([]);
    $cfg['api_key'] = trim((string)($_POST['api_key'] ?? ''));
    if ($cfg['api_key'] === '') { $cfg['api_key'] = 'sk-' . WB2A\Core\Util::randomHex(24); }
    $cfg['cron_token'] = WB2A\Core\Util::randomHex(16);
    $cfg['panel']['admin_user'] = trim((string)($_POST['admin_user'] ?? 'admin')) ?: 'admin';
    if ($mode === 'mysql') {
        $cfg['storage']['mode'] = 'mysql';
        $cfg['storage']['mysql'] = [
            'host' => trim((string)($_POST['db_host'] ?? 'localhost')), 'port' => (int)($_POST['db_port'] ?? 3306),
            'name' => trim((string)($_POST['db_name'] ?? '')), 'user' => trim((string)($_POST['db_user'] ?? '')),
            'pass' => (string)($_POST['db_pass'] ?? ''), 'prefix' => 'wb2a_',
        ];
        // validate connection before writing config
        try {
            $test = WB2A\Core\Store::open($cfg);
            if ($test->mode() !== 'mysql') { throw new RuntimeException('驱动不可用'); }
        } catch (Throwable $e) {
            $err = 'MySQL 连接失败：' . $e->getMessage() . '（可点「测试存储连接」提前排查）';
            $mode = 'none';
        }
    } elseif ($mode === 'sqlite') {
        $cfg['storage']['mode'] = 'sqlite';
        [$sqliteOk, $sqliteMsg] = storageTest('sqlite', []);
        if (!$sqliteOk) { $err = 'SQLite 检测失败：' . $sqliteMsg; $mode = 'none'; }
    } else {
        $cfg['storage']['mode'] = 'file';
        [$fileOk, $fileMsg] = storageTest('file', []);
        if (!$fileOk) { $err = '文件存储检测失败：' . $fileMsg; $mode = 'none'; }
    }
    if ($mode !== 'none') {
        $dataDir = \WB2A\Core\Env::$dataDir;
        $ok1 = WB2A\Core\Util::atomicWrite($dataDir . '/secret.key', WB2A\Core\Util::randomHex(32));
        $ok2 = WB2A\Core\Util::atomicWrite($dataDir . '/config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($dataDir . '/config.json', 0600);
        @chmod($dataDir . '/secret.key', 0600);
        if (!$ok1 || !$ok2 || !is_file($dataDir . '/config.json')) {
            $err = '配置写入失败：数据目录不可写（' . ($dataDir === WB2A_ROOT . '/data' ? 'data/' : '已回退到系统临时目录，请尽快在主机面板为 data/ 目录授予 Web 运行账号写入权限后重装') . '）';
        } else {
            // 旧文件存储数据自动迁移到新后端（账号/配置/用量/日志不丢失）
            $importNote = '';
            $legacy = $dataDir . '/store.json';
            if (is_file($legacy)) {
                try {
                    $imp = WB2A\Core\Store::open(WB2A\Core\Env::loadConfig());
                    if ($imp->mode() !== 'file') {
                        $r = $imp->importFromFile($legacy);
                        $importNote = $r['ok'] ? '；已自动迁移旧数据：账号 ' . $r['counts']['accounts'] . ' 个、KV ' . $r['counts']['kv'] . ' 条、用量桶 ' . $r['counts']['usage'] . ' 个' : '';
                        if ($r['ok']) { @rename($legacy, $legacy . '.migrated'); }
                    }
                } catch (Throwable $e) { $importNote = '；旧数据迁移失败：' . $e->getMessage(); }
            }
            $done = true;
            $step = 'done';
            $doneNote = $importNote;
        }
    }
}

$checks = envChecks();
[$netMsg, $netOk] = outboundHttpsTest();
$criticalFail = false;
foreach ($checks as $c) { if (in_array($c[0], ['PHP 版本 >= 8.1（推荐 8.2+）', 'PDO 扩展', 'cURL 扩展（上游 HTTPS 请求）', 'JSON 扩展', 'data/ 目录可写'], true) && !$c[1]) { $criticalFail = true; } }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WorkBuddy2API Panel · 安装向导</title>
<style>
:root{--bg:#0f1420;--card:#1a2130;--fg:#e8ecf3;--mut:#8b95a7;--ok:#34c98e;--bad:#ff5c5c;--warn:#f0b429;--acc:#4c8dff}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg);color:var(--fg);padding:24px}
.wrap{max-width:860px;margin:0 auto}h1{font-size:22px}.card{background:var(--card);border-radius:12px;padding:20px;margin:14px 0}
table{width:100%;border-collapse:collapse}td{padding:7px 8px;border-bottom:1px solid #26304a;font-size:14px}
.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}
label{display:block;margin:12px 0 4px;font-size:14px;color:var(--mut)}
input{width:100%;padding:10px;border-radius:8px;border:1px solid #2c3852;background:#121826;color:var(--fg);font-size:14px}
button{margin-top:18px;background:var(--acc);color:#fff;border:0;border-radius:8px;padding:11px 22px;font-size:15px;cursor:pointer}
.muted{color:var(--mut);font-size:13px}
.err{background:#3a1d1d;border:1px solid var(--bad);border-radius:8px;padding:12px;margin:10px 0;color:#ffb3b3;font-size:14px}
.done{background:#12301f;border:1px solid var(--ok);border-radius:8px;padding:16px;margin:10px 0;font-size:14px;line-height:1.8}
code{background:#121826;padding:2px 6px;border-radius:4px;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
<h1>WorkBuddy2API Panel · PHP 版安装向导 <span class="muted">v<?= WB2A_VERSION ?></span></h1>

<?php if ($installed && !$done): ?>
  <div class="card">
    <p class="warn">⚠ 本系统已完成安装（检测到 data/config.json）。安装向导已锁定，请返回管理面板使用。</p>
    <p class="muted">如需重新安装，请先通过主机文件管理器删除 <code>data/config.json</code> 与 <code>data/secret.key</code>（将同时清空全部配置）。</p>
  </div>
<?php elseif ($done): ?>
  <?php $cfgNow = WB2A\Core\Env::loadConfig(); ?>
  <div class="done">
    ✅ <b>安装完成！</b>配置已写入并锁定。<br>
    管理密钥（api_key）：<code><?= h($cfgNow['api_key']) ?></code> —— 请立即复制保存，面板与 API 均使用它鉴权。<br>
    Cron 任务令牌：<code><?= h($cfgNow['cron_token']) ?></code><br>
    <?php if ($doneNote): ?><span style="color:var(--ok)"><?= h($doneNote) ?></span><br><?php endif; ?>
    Cron 接口（供主机面板定时任务调用，建议每 10 分钟）：<br>
    <code><?= h(((!empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain') . rtrim(str_replace('\\','/',dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/'))),'/') . '/cron?token=' . $cfgNow['cron_token']) ?></code><br><br>
    <a href="../panel/" style="color:var(--acc)">→ 打开管理面板</a>
  </div>
<?php else: ?>
  <div class="card">
    <b>① 运行环境检测</b>
    <table>
      <?php foreach ($checks as $c): ?>
      <tr><td style="width:46%"><?= h($c[0]) ?></td>
          <td class="<?= $c[1] ? 'ok' : (in_array($c[0], ['pdo_mysql（MySQL 数据库支持）', 'OpenSSL 扩展（凭证加密）', 'PHP 最大执行时间', '输出缓冲可关闭（SSE 流式）', 'mbstring 扩展（中文处理）'], true) ? 'warn' : 'bad') ?>"><?= $c[1] ? '✓' : '✗' ?> <?= h($c[2]) ?></td></tr>
      <?php endforeach; ?>
      <tr><td>出站网络（copilot.tencent.com）</td><td class="<?= $netOk ? 'ok' : 'warn' ?>"><?= $netOk ? '✓' : '✗' ?> <?= h($netMsg) ?></td></tr>
    </table>
    <?php if ($criticalFail): ?><p class="bad" style="font-size:14px">存在必须修复的环境问题（见上表红色项），安装无法继续。请先通过主机控制面板解决。</p>
    <?php else: ?><p class="muted">黄色项为可降级能力，不影响安装，但会在运行中受限（详见交付文档）。</p><?php endif; ?>
  </div>

  <?php if (!$criticalFail): ?>
  <div class="card">
    <b>② 存储与管理员</b>
    <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="step" value="finish">
      <label>存储模式</label>
      <select name="storage_mode" id="sm" onchange="smChange()" style="width:100%;padding:10px;border-radius:8px;border:1px solid #2c3852;background:#121826;color:#e8ecf3">
        <option value="mysql">MySQL 数据库（推荐；数据存空间自带的 MySQL，重启不丢）</option>
        <?php if (in_array('sqlite', PDO::getAvailableDrivers(), true)): ?><option value="sqlite">SQLite 单文件数据库（存于 data/，需 data 目录可写）</option><?php endif; ?>
        <option value="file">本地文件存储（data/store.json；高并发下性能受限）</option>
      </select>
      <script>
      function smChange(){
        var v=document.getElementById('sm').value;
        document.getElementById('dbsec').style.display=(v==='mysql'?'block':'none');
        var sq=document.getElementById('sqliteNote');
        if(sq) sq.style.display=(v==='sqlite'?'block':'none');
      }
      async function testStorage(){
        var f=document.forms[0], b=document.getElementById('btnTest'), o=document.getElementById('testOut');
        b.disabled=true; o.textContent='检测中…'; o.className='muted';
        var fd=new FormData(f); fd.set('step','test');
        try{
          var r=await fetch(location.pathname,{method:'POST',body:fd});
          var j=await r.json();
          o.textContent=(j.ok?'✓ 检测通过：':'✗ 检测失败：')+j.message;
          o.className=j.ok?'ok':'bad';
        }catch(e){ o.textContent='✗ 检测请求失败：'+e.message; o.className='bad'; }
        b.disabled=false;
      }
      </script>
      <div id="sqliteNote" style="display:none" class="muted">SQLite 数据库文件位于 data/ 目录，要求 data 目录对 Web 运行账号可写；否则请改用 MySQL。</div>
      <div id="dbsec">
        <label>数据库主机</label><input name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>">
        <label>端口</label><input name="db_port" value="<?= h($_POST['db_port'] ?? '3306') ?>">
        <label>数据库名</label><input name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>" required>
        <label>用户名</label><input name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" required>
        <label>密码</label><input name="db_pass" type="password" value="">
      </div>
      <label>管理员显示用户名（记录用，登录凭 api_key）</label><input name="admin_user" value="<?= h($_POST['admin_user'] ?? 'admin') ?>">
      <label>API 管理密钥 api_key（留空自动生成强随机密钥；公网部署务必设置）</label>
      <input name="api_key" value="<?= h($_POST['api_key'] ?? '') ?>" placeholder="留空自动生成 sk-...">
      <div class="inline" style="display:flex;gap:10px;align-items:center">
        <button type="submit">完成安装</button>
        <button type="button" id="btnTest" onclick="testStorage()" style="background:#2c3852;margin-top:0">测试存储连接</button>
        <span id="testOut" class="muted"></span>
      </div>
      <p class="muted">安装会自动生成 AES-256 加密密钥（data/secret.key）、创建数据库表（MySQL 模式）并锁定安装向导。重复访问本页面不会再覆盖配置。</p>
    </form>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
