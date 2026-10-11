<?php
declare(strict_types=1);
namespace WB2A\Api;

use WB2A\Core\{Env, Util, Crypto, RingLog, Usage, Store, Config};
use WB2A\Pool;
use WB2A\Upstream\{Client, Classify, Headers};

/**
 * Panel backend: same-origin /panel/api/* endpoints consumed by the original
 * single-page panel (app.js). Auth = Bearer api_key (same as gateway).
 */
final class PanelApi
{
    public static function indexPage(string $rest = ''): void
    {
        self::securityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        readfile(WB2A_ROOT . '/panel/index.html');
    }

    public static function appScript(string $rest = ''): void
    {
        self::securityHeaders();
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(WB2A_ROOT . '/panel/app.js');
    }

    private static function securityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
    }

    private static function auth(): bool
    {
        if (!Util::requireAuth()) {
            Util::jsonOut(['ok' => false, 'error' => 'invalid_api_key'], 401);
            return false;
        }
        return true;
    }

    /** Entry point from Router: args[0] = api subpath. */
    public static function dispatch(string $sub = ''): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!self::auth()) { return; }
        $sub = trim($sub, '/');
        $parts = $sub === '' ? [] : explode('/', $sub);
        $pool = new Pool();

        // ---- public(share api_key auth) read endpoints ----
        if ($method === 'GET') {
            switch ($parts[0] ?? '') {
                case 'overview': self::overview($pool); return;
                case 'usage': self::usage(); return;
                case 'packages': self::packages($pool); return;
                case 'models': self::modelsLive(); return;
                case 'model_probes': self::modelProbes(); return;
                case 'logs': Util::jsonOut(['entries' => RingLog::tail()]); return;
                case 'request_metrics': self::requestMetrics(); return;
                case 'request_logs': self::requestLogs(); return;
                case 'config': self::getConfig(); return;
                case 'cron': self::cronInfo(); return;
                case 'tasks': if (($parts[1] ?? '') === 'queue') { Util::jsonOut(['running' => false, 'total' => 0, 'items' => []]); return; } break;
                case 'school/vouchers': Util::jsonOut(['accounts' => []]); return;
                case 'accounts': self::accountTasks($pool, $parts[1] ?? ''); return;
                case 'login':
                    if (($parts[1] ?? '') === 'poll') { self::loginPoll(); return; }
                    if (($parts[1] ?? '') === 'regions') { Util::jsonOut(['ok' => true, 'regions' => self::REGIONS]); return; }
                    break;
            }
        }
        if ($method === 'POST') {
            switch ($parts[0] ?? '') {
                case 'login': if (($parts[1] ?? '') === 'start') { self::loginStart(); return; } break;
                case 'import': if (($parts[1] ?? '') === 'cockpit') { self::importCockpit($pool); return; } break;
                case 'config': self::saveConfig(); return;
                case 'cron': if (($parts[1] ?? '') === 'clear') { self::cronClear(); return; } break;
                case 'usage': if (($parts[1] ?? '') === 'save') { Util::jsonOut(['ok' => true]); return; } break;
                case 'checkin_all': self::batch($pool, 'checkin'); return;
                case 'travel_all': self::batch($pool, 'travel'); return;
                case 'activity_all': self::batch($pool, 'activity'); return;
                case 'keepalive_all': self::batch($pool, 'keepalive'); return;
                case 'balance_all': self::balanceAll($pool); return;
                case 'tasks': self::tasksBulk($pool, $parts); return;
                case 'accounts': self::accountOp($pool, $parts); return;
            }
        }
        Util::jsonOut(['ok' => false, 'error' => 'not found'], 404);
    }

    private const REGIONS = [
        ['code' => 'HK', 'name' => 'Hong Kong'], ['code' => 'MO', 'name' => 'Macao'],
        ['code' => 'SG', 'name' => 'Singapore'], ['code' => 'TH', 'name' => 'Thailand'],
        ['code' => 'PH', 'name' => 'Philippines'], ['code' => 'MY', 'name' => 'Malaysia'], ['code' => 'ID', 'name' => 'Indonesia'],
    ];

    // ---------- overview ----------
    private static function overview(Pool $pool): void
    {
        $s = $pool->status();
        Util::jsonOut([
            'version' => WB2A_VERSION, 'uptime_sec' => time() - (int)Env::store()->kvGet('boot_time', time()),
            'auth_required' => Util::authKey() !== '', 'redis_mode' => 'noop',
            'sticky_sessions' => $s['sticky_sessions'],
            'total' => $s['total'], 'healthy' => $s['healthy'], 'cooling' => $s['cooling'], 'disabled' => $s['disabled'], 'paused' => $s['paused'],
            'in_flight_full' => $s['in_flight_full'], 'accounts' => $s['accounts'], 'model_locks' => null,
        ]);
    }

    private static function usage(): void
    {
        $from = isset($_GET['from']) ? (int)$_GET['from'] : null;
        $to = isset($_GET['to']) ? (int)$_GET['to'] : null;
        if ($from === null && isset($_GET['hours'])) {
            $h = max(1, min(1440, (int)$_GET['hours']));
            $from = time() - $h * 3600;
        }
        Util::jsonOut(Usage::snapshot($from, $to));
    }

    private static function packages(Pool $pool): void
    {
        $out = [];
        foreach ($pool->allUIDs() as $uid) {
            $acct = $pool->account($uid);
            if (!$acct) { continue; }
            $r = Client::userResource($acct);
            // end_time 统一转 ISO8601（与 Go 原版一致，前端按字符串解析）
            $r['packages'] = array_map(static function (array $pk): array {
                if (!empty($pk['end_time']) && is_numeric($pk['end_time'])) { $pk['end_time'] = gmdate('c', (int)$pk['end_time']); }
                return $pk;
            }, is_array($r['packages'] ?? null) ? $r['packages'] : []);
            $out[] = ['uid' => $uid, 'nickname' => (string)($acct['account']['nickname'] ?? ''), 'realm' => $acct['auth']['realm'] ?? 'cn', 'remain' => $r['credits'], 'size' => $r['credits_total'], 'packages' => $r['packages']];
        }
        Util::jsonOut(['accounts' => $out]);
    }

    private static function modelsLive(): void
    {
        $pool = new Pool();
        $models = [];
        foreach (['cn', 'global'] as $realm) {
            $uid = $pool->pick([], null, $realm);
            if ($uid === null) { continue; }
            $acct = $pool->account($uid);
            if (!$acct) { continue; }
            $r = Client::models($realm, (string)$acct['auth']['accessToken'], $uid);
            foreach (($r['models'] ?? []) as $m) { $models[] = $m; }
        }
        Util::jsonOut(['ok' => true, 'models' => $models]);
    }

    private static function modelProbes(): void
    {
        $f = Env::$dataDir . '/output_probes.json';
        if (!is_file($f)) { Util::jsonOut(['probes' => [], 'exists' => false]); return; }
        $j = json_decode((string)file_get_contents($f), true);
        Util::jsonOut(['probes' => is_array($j) ? $j : [], 'exists' => true, 'updated_at' => date('c', (int)filemtime($f))]);
    }

    private static function requestMetrics(): void
    {
        $entries = Env::store()->reqlogQuery(50);
        $n = count($entries); $ok = 0; $sum = 0;
        foreach ($entries as $e) { if (!empty($e['ok'])) { $ok++; } $sum += (float)($e['duration_ms'] ?? 0); }
        Util::jsonOut([
            'started_at' => date('c', (int)Env::store()->kvGet('boot_time', time())),
            'completed' => $n, 'in_flight' => 0, 'succeeded' => $ok, 'failed' => $n - $ok,
            'success_rate' => $n > 0 ? round($ok / $n, 4) : 0, 'http_success_rate' => $n > 0 ? round($ok / $n, 4) : 0,
            'avg_duration_ms' => $n > 0 ? round($sum / $n, 1) : 0,
            'recent' => $entries,
            'archive' => ['enabled' => (bool)(Env::config()['logging']['request_archive_enabled'] ?? true), 'files' => 1, 'bytes' => 0, 'dropped_writes' => 0],
        ]);
    }

    private static function requestLogs(): void
    {
        $limit = (int)($_GET['limit'] ?? 200);
        $f = [];
        foreach (['outcome', 'account', 'model', 'client_ip', 'user_agent'] as $k) {
            if (!empty($_GET[$k])) { $f[$k] = (string)$_GET[$k]; }
        }
        foreach (['from', 'to'] as $k) {
            if (!empty($_GET[$k])) { $f[$k] = self::parseTime((string)$_GET[$k]); }
        }
        $entries = Env::store()->reqlogQuery($limit, $f);
        Util::jsonOut(['entries' => $entries, 'limit' => $limit]);
    }

    private static function parseTime(string $v): int
    {
        if (preg_match('/^\d+$/', $v)) { return (int)($v > 1e12 ? intdiv((int)$v, 1000) : (int)$v); }
        $ts = strtotime($v);
        return $ts ?: 0;
    }

    // ---------- config ----------
    /** Cron trigger URL + access log (who/when hit /cron). */
    private static function cronInfo(): void
    {
        $cfg = Env::config();
        $token = (string)($cfg['cron_token'] ?? '');
        $scheme = (!empty($_SERVER['HTTPS']) || ($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'your-domain');
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/panel/')), '/');
        if (substr($base, -6) === '/panel') { $base = substr($base, 0, -6); }
        $url = $scheme . '://' . $host . $base . '/cron?token=' . $token;
        $hits = Env::store()->kvGet('cron_hits', []);
        if (!is_array($hits)) { $hits = []; }
        $today = Env::store()->kvGet('cron_today', []);
        if (!is_array($today) || ($today['date'] ?? '') !== date('Y-m-d')) { $today = ['date' => date('Y-m-d'), 'count' => 0]; }
        Util::jsonOut([
            'ok' => true,
            'url' => $url,
            'today_count' => (int)$today['count'],
            'last_hit' => isset($hits[0]['ts']) ? (int)$hits[0]['ts'] : null,
            'hits' => array_slice($hits, 0, 20),
        ]);
    }

    /** Clear cron access log + today counter. */
    private static function cronClear(): void
    {
        Env::store()->kvSet('cron_hits', []);
        Env::store()->kvSet('cron_today', ['date' => date('Y-m-d'), 'count' => 0]);
        Util::jsonOut(['ok' => true]);
    }

    private static function getConfig(): void
    {
        Util::jsonOut(['ok' => true, 'path' => Env::$dataDir . '/config.json', 'config' => Env::config()]);
    }

    private static function saveConfig(): void
    {
        $incoming = Util::inputJson();
        if ($incoming === []) { Util::jsonOut(['ok' => false, 'error' => 'empty config'], 400); return; }
        $cur = json_decode((string)file_get_contents(Env::$dataDir . '/config.json'), true) ?: [];
        $merged = Config::mergeDeep($cur, $incoming);
        if (isset($incoming['api_key']) && trim((string)$incoming['api_key']) !== '') { $merged['api_key'] = (string)$incoming['api_key']; }
        Env::saveConfig(Config::withDefaults($merged));
        Util::jsonOut(['ok' => true, 'restart_required' => []]);
    }

    // ---------- login ----------
    private static function loginStart(): void
    {
        $in = Util::inputJson();
        $realm = ($in['realm'] ?? 'cn') === 'global' ? 'global' : 'cn';
        $r = Client::loginStart($realm);
        if (!$r['ok']) { Util::jsonOut(['ok' => false, 'error' => $r['error']], 502); return; }
        $sessions = Env::store()->kvGet('login_sessions', []);
        $sessions[$r['state']] = ['created' => time(), 'realm' => $realm];
        Env::store()->kvSet('login_sessions', $sessions);
        Util::jsonOut(['ok' => true] + $r);
    }

    private static function loginPoll(): void
    {
        $state = (string)($_GET['state'] ?? '');
        if ($state === '') { Util::jsonOut(['ok' => false, 'error' => 'missing state'], 400); return; }
        $sessions = Env::store()->kvGet('login_sessions', []);
        if (!isset($sessions[$state])) {
            Util::jsonOut(['ok' => false, 'error' => 'unknown or expired state（请重新发起添加账号）'], 404);
            return;
        }
        if (time() - (int)$sessions[$state]['created'] > 900) {
            unset($sessions[$state]); Env::store()->kvSet('login_sessions', $sessions);
            Util::jsonOut(['ok' => false, 'error' => 'unknown or expired state（请重新发起添加账号）'], 404);
            return;
        }
        $realm = (string)$sessions[$state]['realm'];
        $r = Client::loginPoll($state, $realm);
        if (empty($r['done'])) { Util::jsonOut(['done' => false, 'message' => (string)($r['message'] ?? 'waiting for login')]); return; }
        // persist credential + hot load
        $acct = ['auth' => ['accessToken' => $r['accessToken'], 'refreshToken' => $r['refreshToken'], 'expiresAt' => time() + max($r['expiresIn'], 300), 'realm' => $realm], 'account' => ['uid' => $r['uid'], 'nickname' => $r['nickname'], 'enterpriseId' => $r['enterpriseId'] ?? '']];
        $pool = new Pool();
        $pool->addAccount($acct);
        unset($sessions[$state]); Env::store()->kvSet('login_sessions', $sessions);
        RingLog::task('login: account ' . $r['uid'] . ' added via OAuth');
        Util::jsonOut(['done' => true, 'uid' => $r['uid'], 'nickname' => $r['nickname'], 'realm' => $realm, 'credits' => -1, 'credits_total' => -1, 'checkin_message' => '']);
    }

    private static function importCockpit(Pool $pool): void
    {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Util::jsonOut(['ok' => false, 'error' => 'missing file field'], 400);
            return;
        }
        if ((int)($_FILES['file']['size'] ?? 0) > 32 * 1024 * 1024) { Util::jsonOut(['ok' => false, 'error' => 'file too large'], 400); return; }
        $j = json_decode((string)file_get_contents($_FILES['file']['tmp_name']), true);
        if (!is_array($j)) { Util::jsonOut(['ok' => false, 'error' => 'invalid JSON'], 400); return; }
        $rows = isset($j[0]) ? $j : ($j['accounts'] ?? []);
        $imported = 0; $skipped = 0; $errors = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { $skipped++; continue; }
            $uid = (string)($row['uid'] ?? '');
            if (!Util::validUID($uid) || trim((string)($row['access_token'] ?? '')) === '') { $skipped++; continue; }
            $realm = (stripos((string)($row['domain'] ?? ''), 'workbuddy.ai') !== false) ? 'global' : 'cn';
            try {
                $pool->importPlain($uid, [
                    'access_token' => (string)$row['access_token'],
                    'refresh_token' => (string)($row['refresh_token'] ?? ''),
                    'expires_at' => (int)($row['expires_at'] ?? 0) > 1e12 ? intdiv((int)$row['expires_at'], 1000) : (int)($row['expires_at'] ?? 0),
                    'nickname' => (string)($row['nickname'] ?? ($row['email'] ?? '')),
                    'enterprise_id' => (string)($row['enterprise_id'] ?? ''),
                    'realm' => $realm,
                ]);
                $imported++;
            } catch (\Throwable $e) { $errors[] = $uid . ': ' . $e->getMessage(); }
        }
        Util::jsonOut(['ok' => true, 'total' => count($rows), 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // ---------- account ops ----------
    private static function accountOp(Pool $pool, array $parts): void
    {
        $uid = (string)($parts[1] ?? '');
        $op = (string)($parts[2] ?? '');
        if (!Util::validUID($uid) || !$pool->has($uid)) { Util::jsonOut(['ok' => false, 'error' => 'account not found'], 404); return; }
        switch ($op) {
            case 'revive': $pool->revive($uid); Util::jsonOut(['ok' => true]); return;
            case 'disable': $pool->disable($uid, 'manual disable (panel)'); Util::jsonOut(['ok' => true]); return;
            case 'pause': $pool->setPaused($uid, true); Util::jsonOut(['ok' => true]); return;
            case 'resume': $pool->setPaused($uid, false); Util::jsonOut(['ok' => true]); return;
            case 'remove': $pool->remove($uid); Util::jsonOut(['ok' => true]); return;
            case 'checkin': self::accountCheckin($pool, $uid); return;
            case 'balance': self::accountBalance($pool, $uid); return;
            case 'tasks':
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { self::accountTasks($pool, $uid); return; }
                self::accountTaskOp($pool, $uid, $parts); return;
        }
        Util::jsonOut(['ok' => false, 'error' => 'not found'], 404);
    }

    private static function accountCheckin(Pool $pool, string $uid): void
    {
        $acct = $pool->account($uid);
        if (!$acct) { Util::jsonOut(['ok' => false, 'error' => 'not found'], 404); return; }
        if (trim((string)($acct['account']['enterpriseId'] ?? '')) !== '') {
            Util::jsonOut(['ok' => true, 'enterprise' => true, 'checkin_message' => '企业账号不支持签到', 'credits' => $pool->stateFor($uid)['credits'] ?? 0, 'credits_total' => 0]);
            return;
        }
        $r = Client::dailyCheckin($acct);
        $body = (string)$r['body'];
        $already = strpos($body, '10001') !== false || strpos($body, '14001') !== false || strpos($body, '已签') !== false;
        $bal = Client::userResource($acct);
        $pool->setCredits($uid, $bal['credits'], $bal['credits_total'], $bal['packages']);
        $msg = (string)(($r['json'] ?? [])['msg'] ?? '');
        RingLog::task("checkin $uid: " . ($r['status'] === 200 ? 'ok' : 'http ' . $r['status']) . ' ' . $msg);
        Util::jsonOut(['ok' => true, 'checkin_done' => $r['status'] >= 200 && $r['status'] < 300, 'checkin_message' => $msg, 'balance_error' => $bal['ok'] ? null : 'balance query failed', 'credits' => $bal['credits'], 'credits_total' => $bal['credits_total']]);
    }

    private static function accountBalance(Pool $pool, string $uid): void
    {
        $acct = $pool->account($uid);
        if (!$acct) { Util::jsonOut(['ok' => false, 'error' => 'not found'], 404); return; }
        $bal = Client::userResource($acct);
        if (!$bal['ok']) { Util::jsonOut(['ok' => false, 'error' => 'balance query failed (HTTP ' . $bal['raw']['status'] . ')'], 502); return; }
        $pool->setCredits($uid, $bal['credits'], $bal['credits_total'], $bal['packages']);
        Util::jsonOut(['ok' => true, 'credits' => $bal['credits'], 'credits_total' => $bal['credits_total']]);
    }

    private static function accountTasks(Pool $pool, string $uid): void
    {
        $acct = $pool->account($uid);
        if (!$acct) { Util::jsonOut(['ok' => false, 'error' => 'account not found'], 404); return; }
        $tasks = Client::growthTasks($acct);
        $seen = [];
        foreach ($tasks as $t) { $seen[$t['task_code']] = true; }
        foreach (Client::growthTasks($acct, true) as $t) { if (!isset($seen[$t['task_code']])) { $tasks[] = $t; } }
        Util::jsonOut(['ok' => true, 'tasks' => $tasks]);
    }

    private static function accountTaskOp(Pool $pool, string $uid, array $parts): void
    {
        $op = (string)($parts[4] ?? ($parts[3] ?? ''));
        // route: accounts/{uid}/tasks/{accept|accept_all|claim|auto|auto_all}
        $op = (string)($parts[3] ?? '');
        $acct = $pool->account($uid);
        if (!$acct) { Util::jsonOut(['ok' => false, 'error' => 'account not found'], 404); return; }
        $in = Util::inputJson();
        switch ($op) {
            case 'accept':
                $codes = (array)($in['task_codes'] ?? []);
                if ($codes === []) { Util::jsonOut(['ok' => false, 'error' => 'task_codes required'], 400); return; }
                $r = Client::acceptTasks($acct, $codes);
                Util::jsonOut(['ok' => $r['ok']], $r['ok'] ? 200 : 502);
                return;
            case 'accept_all':
                $tasks = Client::growthTasks($acct);
                $codes = [];
                foreach ($tasks as $t) {
                    if (!empty($t['claimed']) || !empty($t['locked']) || ($t['accept_status'] ?? '') === 'accepted' || ($t['status'] ?? '') === 'completed') { continue; }
                    $codes[] = (string)$t['task_code'];
                }
                if ($codes === []) { Util::jsonOut(['ok' => true, 'accepted' => 0, 'message' => '所有任务均已接受']); return; }
                $r = Client::acceptTasks($acct, $codes);
                Util::jsonOut(['ok' => true, 'accepted' => $r['ok'] ? count($codes) : 0, 'failed' => $r['ok'] ? [] : $codes]);
                return;
            case 'claim':
                $code = (string)($in['task_code'] ?? '');
                if ($code === '') { Util::jsonOut(['ok' => false, 'error' => 'task_code required'], 400); return; }
                $r = Client::claimReward($acct, $code);
                if ($r['ok']) { Util::jsonOut(['ok' => true, 'credit' => $r['credit'], 'energy' => $r['energy']]); }
                else { Util::jsonOut(['ok' => false, 'error' => mb_substr((string)($r['json']['msg'] ?? 'claim failed'), 0, 200)], 502); }
                return;
            case 'auto':
            case 'auto_all':
                $code = (string)($in['task_code'] ?? '');
                $results = TaskRunner::runAuto($pool, $acct, $uid, $op === 'auto_all', $code !== '' ? $code : null);
                Util::jsonOut(['ok' => true, 'results' => $results]);
                return;
        }
        Util::jsonOut(['ok' => false, 'error' => 'not found'], 404);
    }

    private static function tasksBulk(Pool $pool, array $parts): void
    {
        $op = (string)($parts[1] ?? '');
        if ($op === 'scan_all') {
            $accounts = []; $pending = 0;
            foreach ($pool->allUIDs() as $uid) {
                $acct = $pool->account($uid);
                if (!$acct) { continue; }
                if (($acct['auth']['realm'] ?? 'cn') === 'global') { continue; }
                if (trim((string)($acct['account']['enterpriseId'] ?? '')) !== '') { continue; }
                $tasks = [];
                foreach (Client::growthTasks($acct) as $t) {
                    if (!empty($t['claimed']) || !empty($t['locked'])) { continue; }
                    $tasks[] = $t;
                }
                $pending += count($tasks);
                $accounts[] = ['uid' => $uid, 'nickname' => (string)($acct['account']['nickname'] ?? ''), 'growth' => $tasks];
            }
            Util::jsonOut(['ok' => true, 'accounts' => $accounts, 'pending_count' => $pending]);
            return;
        }
        if ($op === 'run_queue') {
            // 共享主机 PHP 30s 上限：不在这里执行，返回待办清单由前端逐任务驱动
            $items = [];
            foreach ($pool->allUIDs() as $uid) {
                $acct = $pool->account($uid);
                if (!$acct) { continue; }
                if (($acct['auth']['realm'] ?? 'cn') === 'global') { continue; }
                if (trim((string)($acct['account']['enterpriseId'] ?? '')) !== '') { continue; }
                foreach (Client::growthTasks($acct) as $t) {
                    if (!empty($t['claimed']) || !empty($t['locked'])) { continue; }
                    $items[] = ['uid' => $uid, 'nickname' => (string)($acct['account']['nickname'] ?? ''), 'kind' => 'growth', 'code' => (string)$t['task_code'], 'title' => (string)($t['title'] ?? $t['task_code'])];
                }
            }
            Util::jsonOut(['ok' => true, 'started' => count($items) > 0, 'total' => count($items), 'seq' => 1, 'items' => $items, 'message' => count($items) === 0 ? '全部账号没有待办任务' : null]);
            return;
        }
        Util::jsonOut(['ok' => false, 'error' => 'not found'], 404);
    }

    private static function batch(Pool $pool, string $kind): void
    {
        CronApi::runKind($pool, $kind);
        Util::jsonOut(['ok' => true, 'started' => true]);
    }

    private static function balanceAll(Pool $pool): void
    {
        $accounts = [];
        foreach ($pool->allUIDs() as $uid) {
            $acct = $pool->account($uid);
            if (!$acct) { continue; }
            $bal = Client::userResource($acct);
            $pool->setCredits($uid, $bal['credits'], $bal['credits_total'], $bal['packages']);
            $accounts[] = ['uid' => $uid, 'nickname' => (string)($acct['account']['nickname'] ?? ''), 'credits' => $bal['credits'], 'credits_total' => $bal['credits_total']];
        }
        Util::jsonOut(['ok' => true, 'accounts' => $accounts]);
    }
}
