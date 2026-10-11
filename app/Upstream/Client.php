<?php
declare(strict_types=1);
namespace WB2A\Upstream;

use WB2A\Core\Env;
use WB2A\Core\Http;

/**
 * Upstream client: short RPCs + chat SSE. Base URLs overridable for tests
 * via config 'upstream.test_base_cn' / 'upstream.test_base_global'.
 */
final class Client
{
    public static function base(string $realm): string
    {
        $cfg = Env::config()['upstream'] ?? [];
        $test = (string)($cfg['test_base_' . $realm] ?? '');
        if ($test !== '') { return rtrim($test, '/'); }
        return Headers::base($realm);
    }

    public static function billingBase(string $realm): string
    {
        $cfg = Env::config()['upstream'] ?? [];
        $test = (string)($cfg['test_billing_' . $realm] ?? '');
        if ($test !== '') { return rtrim($test, '/'); }
        return Headers::billingBase($realm);
    }

    /** Generic JSON RPC against the upstream; returns [status, decoded, rawBody]. */
    public static function rpc(string $realm, string $method, string $path, $body, array $extraHeaders = [], int $timeout = 0): array
    {
        if ($timeout === 0) { $timeout = (int)(Env::config()['upstream']['timeout_seconds'] ?? 120); }
        $url = self::base($realm) . $path;
        $headers = Headers::common($realm);
        foreach ($extraHeaders as $k => $v) { $headers[$k] = $v; }
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $r = Http::request($method, $url, $headers, $payload, $timeout);
        $decoded = json_decode($r['body'] ?? '', true);
        return ['status' => $r['status'], 'json' => is_array($decoded) ? $decoded : null, 'body' => (string)($r['body'] ?? ''), 'error' => $r['error'] ?? ''];
    }

    public static function ok(array $r): bool
    {
        return $r['status'] >= 200 && $r['status'] < 300 && (($r['json']['code'] ?? 0) == 0 || !isset($r['json']['code']));
    }

    // ---- Token refresh ----
    public static function refreshToken(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $path = $realm === 'global' ? '/v2/plugin/auth/token/refresh' : '/v2/plugin/auth/token/refresh';
        $r = self::rpc($realm, 'POST', $path, [
            'refreshToken' => $acct['auth']['refreshToken'] ?? '',
        ], ['X-Refresh-Token' => (string)($acct['auth']['refreshToken'] ?? ''), 'X-Auth-Refresh-Source' => 'plugin']);
        $d = $r['json']['data'] ?? [];
        return ['ok' => self::ok($r) && !empty($d['accessToken']), 'access' => (string)($d['accessToken'] ?? ''), 'refresh' => (string)($d['refreshToken'] ?? ''), 'expires_in' => (int)($d['expiresIn'] ?? 0), 'raw' => $r];
    }

    // ---- Billing / checkin ----
    public static function dailyCheckin(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $base = self::billingBase($realm);
        $path = $realm === 'global' ? '/v2/billing/meter/daily-checkin' : '/v2/billing/meter/daily-checkin';
        $url = $base . $path;
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', $url, $headers, '{}', 60);
        return ['status' => $r['status'], 'body' => $r['body'], 'json' => json_decode($r['body'], true), 'error' => $r['error']];
    }

    public static function userResource(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $url = self::billingBase($realm) . '/v2/billing/meter/get-user-resource';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', $url, $headers, '{}', 60);
        $j = json_decode($r['body'] ?? '', true);
        $credits = -1; $total = -1; $packages = [];
        // Real upstream shape: data.Response.Data.Accounts[] (CN), keep legacy shapes as fallback.
        $accounts = $j['data']['Response']['Data']['Accounts'] ?? ($j['data']['accounts'] ?? []);
        foreach ($accounts as $a) {
            if (!is_array($a)) { continue; }
            $remain = (int)($a['CapacityRemain'] ?? $a['capacityRemain'] ?? 0);
            $size = (int)($a['CycleCapacitySize'] ?? $a['cycleCapacitySize'] ?? $a['capacity'] ?? 0);
            $endRaw = (string)($a['CycleEndTime'] ?? '');
            $end = 0;
            if (ctype_digit($endRaw) && $endRaw !== '') { $end = (int)$endRaw; }
            elseif ($endRaw !== '') { $end = strtotime($endRaw) ?: 0; }
            if ((int)($a['DeductionEndTime'] ?? 0) > 1e12) { $end = intdiv((int)$a['DeductionEndTime'], 1000); }
            if ($credits < 0) { $credits = 0; $total = 0; }
            $credits += $remain;
            $total += $size;
            $packages[] = ['name' => (string)($a['PackageName'] ?? $a['productName'] ?? 'package'), 'remain' => $remain, 'size' => $size, 'end_time' => $end];
        }
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'credits' => max($credits, 0), 'credits_total' => max($total, 0), 'packages' => $packages, 'raw' => $r, 'json' => $j];
    }

    // ---- Activity report ----
    public static function reportChatActivity(array $acct, string $convId): array
    {
        $url = self::billingBase('cn') . '/v2/report';
        $headers = Headers::common('cn');
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $ev = ['userId' => (string)($acct['account']['uid'] ?? ''), 'conversationId' => $convId, 'requestId' => \WB2A\Core\Util::randomHex(16), 'eventType' => 'chat_request_send'];
        $r = Http::request('POST', $url, $headers, json_encode([$ev], JSON_UNESCAPED_SLASHES), 60);
        return ['status' => $r['status'], 'json' => json_decode($r['body'], true), 'error' => $r['error']];
    }

    // ---- OAuth device login ----
    public static function loginStart(string $realm): array
    {
        $r = self::rpc($realm, 'POST', '/v2/plugin/auth/state?platform=CLI', new \stdClass());
        $d = $r['json']['data'] ?? [];
        if (empty($d['state']) || empty($d['authUrl'])) {
            return ['ok' => false, 'error' => 'auth state: missing state or authUrl'];
        }
        return ['ok' => true, 'state' => (string)$d['state'], 'url' => (string)$d['authUrl'], 'realm' => $realm];
    }

    public static function loginPoll(string $state, string $realm): array
    {
        $r = self::rpc($realm, 'GET', '/v2/plugin/auth/token?state=' . urlencode($state), null);
        $d = $r['json']['data'] ?? [];
        $token = (string)($d['accessToken'] ?? '');
        if ($token === '') { return ['done' => false, 'message' => (string)($r['json']['msg'] ?? 'waiting for login')]; }
        // fetch account info
        $r2 = self::rpc($realm, 'GET', '/v2/plugin/login/account?state=' . urlencode($state), null, ['Authorization' => 'Bearer ' . $token]);
        $acc = $r2['json']['data'] ?? [];
        $uid = (string)($acc['uid'] ?? '');
        if ($uid === '') { return ['done' => false, 'message' => 'login done but no uid']; }
        if (!\WB2A\Core\Util::validUID($uid)) { return ['done' => false, 'message' => 'invalid uid']; }
        return ['done' => true, 'uid' => $uid, 'nickname' => (string)($acc['nickname'] ?? ''), 'enterpriseId' => (string)($acc['enterpriseId'] ?? ''),
            'accessToken' => $token, 'refreshToken' => (string)($d['refreshToken'] ?? ''), 'expiresIn' => (int)($d['expiresIn'] ?? 0)];
    }

    // ---- Models ----
    public static function models(string $realm, string $accessToken, string $uid = ''): array
    {
        $headers = Headers::common($realm);
        if ($accessToken !== '') { $headers['Authorization'] = 'Bearer ' . $accessToken; }
        if ($uid !== '') { $headers['X-User-Id'] = $uid; }
        $r = Http::request('GET', self::base($realm) . '/v2/enterprises/personal/models', $headers, null, 60);
        $j = json_decode($r['body'] ?? '', true);
        $out = [];
        // Real shape: data.agents[] { models: ["name", ...] } — plus legacy array shapes.
        $agents = $j['data']['agents'] ?? [];
        if (is_array($agents)) {
            foreach ($agents as $ag) {
                $agent = is_array($ag) ? ($ag['name'] ?? '') : '';
                foreach (((is_array($ag) ? ($ag['models'] ?? []) : []) ?: []) as $m) {
                    if (is_string($m) && $m !== '') {
                        $out[$m] = self::modelEntry($realm, ['name' => $m], $m);
                    } elseif (is_array($m) && ($id = (string)($m['id'] ?? $m['modelId'] ?? '')) !== '') {
                        $out[$id] = self::modelEntry($realm, $m, $id);
                    }
                }
            }
        }
        foreach (($j['data']['models'] ?? ($j['data'] ?? [])) as $m) {
            if (!is_array($m)) { continue; }
            $id = (string)($m['id'] ?? $m['modelId'] ?? '');
            if ($id === '' || isset($out[$id])) { continue; }
            $out[$id] = self::modelEntry($realm, $m, $id);
        }
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'models' => array_values($out)];
    }

    public static function modelEntry(string $realm, array $m, string $id): array
    {
        $e = [
            'id' => $realm . ':' . $id, 'object' => 'model', 'created' => 1753600000, 'owned_by' => 'workbuddy',
            'name' => (string)($m['name'] ?? $id),
            'description' => (string)($m['description'] ?? ''),
            'credits' => (float)($m['credits'] ?? ($m['credit'] ?? 0)),
            'tags' => (array)($m['tags'] ?? []),
            'vendor' => (string)($m['vendor'] ?? ''),
            'is_default' => (bool)($m['isDefault'] ?? false),
            'supports_images' => (bool)($m['supportsImages'] ?? false),
            'supports_reasoning' => (bool)($m['supportsReasoning'] ?? false),
            'can_disable_thinking' => (bool)($m['canDisableThinking'] ?? false),
            'supports_tool_call' => (bool)($m['supportsToolCall'] ?? false),
            'only_reasoning' => (bool)($m['onlyReasoning'] ?? false),
        ];
        if (!empty($m['maxInputTokens'])) { $e['context_length'] = (int)$m['maxInputTokens']; }
        if (!empty($m['maxOutputTokens'])) { $e['max_output_tokens'] = (int)$m['maxOutputTokens']; }
        if (!empty($m['supportedEfforts'])) { $e['reasoning_supported_efforts'] = array_values((array)$m['supportedEfforts']); }
        if (!empty($m['defaultEffort'])) { $e['reasoning_default_effort'] = (string)$m['defaultEffort']; }
        // static fallback catalog
        $caps = self::staticCaps($id);
        if (!isset($e['context_length']) && isset($caps['ctx'])) { $e['context_length'] = $caps['ctx']; }
        if (!isset($e['max_output_tokens']) && isset($caps['out'])) { $e['max_output_tokens'] = $caps['out']; }
        if (!isset($e['reasoning_supported_efforts']) && isset($caps['efforts'])) { $e['reasoning_supported_efforts'] = $caps['efforts']; }
        return $e;
    }

    public static function staticCaps(string $model): array
    {
        static $caps = [
            'deepseek-v4' => ['ctx' => 262144, 'out' => 65536, 'efforts' => ['off', 'low', 'medium', 'high']],
            'deepseek-v4-flash' => ['ctx' => 262144, 'out' => 65536, 'efforts' => ['off', 'low', 'medium', 'high']],
            'glm-5.2' => ['ctx' => 200000, 'out' => 32768, 'efforts' => ['off', 'low', 'medium', 'high']],
            'glm-5' => ['ctx' => 200000, 'out' => 32768, 'efforts' => ['off', 'low', 'medium', 'high']],
            'kimi-kx' => ['ctx' => 256000, 'out' => 32768],
            'minimax-m3' => ['ctx' => 200000, 'out' => 32768],
        ];
        foreach ($caps as $k => $v) { if (stripos($model, $k) === 0) { return $v; } }
        return [];
    }

    // ---- Growth tasks ----
    public static function growthTasks(array $acct, bool $mp = false): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        if ($realm === 'global' || !empty($acct['account']['enterpriseId'])) { return []; }
        if ($mp) {
            $url = self::base($realm) . '/v2/activity/growth/tasks';
            $headers = Headers::common($realm);
            $headers['x-client-platform'] = 'miniprogram';
        } else {
            $url = self::base($realm) . '/v2/activity/growth/tasks';
            $headers = Headers::common($realm);
        }
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('GET', $url, $headers, null, 60);
        $j = json_decode($r['body'] ?? '', true);
        $out = [];
        foreach (($j['data']['tasks'] ?? ($j['data'] ?? [])) as $t) {
            if (!is_array($t) || empty($t['task_code'])) { continue; }
            $t['claimable'] = (int)($t['current'] ?? 0) >= (int)($t['target'] ?? 1) && empty($t['claimed']);
            $out[] = $t;
        }
        return $out;
    }

    public static function claimReward(array $acct, string $taskCode): array
    {
        $url = Headers::CN_WEB . '/activity/growth/tasks/' . urlencode($taskCode) . '/claim';
        $headers = Headers::claimWeb();
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', $url, $headers, '{}', 60);
        $j = json_decode($r['body'] ?? '', true);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300 && (($j['code'] ?? 0) == 0), 'credit' => (int)($j['data']['credit'] ?? 0), 'energy' => (int)($j['data']['energy'] ?? 0), 'json' => $j, 'status' => $r['status'], 'body' => $r['body']];
    }

    public static function acceptTasks(array $acct, array $codes): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $url = self::base($realm) . '/v2/activity/growth/tasks/accept';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $r = Http::request('POST', $url, $headers, json_encode(['task_codes' => array_values($codes)]), 60);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'json' => json_decode($r['body'], true), 'status' => $r['status'], 'body' => $r['body']];
    }

    // ---- Streak / lottery (连登管家) ----
    public static function growthStreak(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('GET', self::base($realm) . '/v2/activity/growth/streak', $headers, null, 60);
        $j = json_decode($r['body'] ?? '', true);
        $d = is_array($j['data'] ?? null) ? $j['data'] : [];
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'days' => (int)($d['days'] ?? $d['streakDays'] ?? 0), 'tiers' => (array)($d['tiers'] ?? ($d['redeems'] ?? [])), 'raw' => $j, 'status' => $r['status']];
    }

    public static function redeemTier(array $acct, $tier): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', self::base($realm) . '/v2/activity/growth/redeem', $headers, json_encode(['tier' => $tier, 'client_token' => (string)($acct['auth']['accessToken'] ?? '')]), 60);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'status' => $r['status'], 'json' => json_decode($r['body'], true)];
    }

    public static function lotterySummary(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('GET', self::base($realm) . '/v2/activity/growth/lottery/summary', $headers, null, 60);
        $j = json_decode($r['body'] ?? '', true);
        $d = is_array($j['data'] ?? null) ? $j['data'] : [];
        $n = (int)($d['chances'] ?? ($d['remain_times'] ?? ($d['count'] ?? 0)));
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'chances' => $n, 'raw' => $j];
    }

    public static function lotteryDraw(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', self::base($realm) . '/v2/activity/growth/lottery/draw', $headers, json_encode(['client_token' => (string)($acct['auth']['accessToken'] ?? '')]), 60);
        $j = json_decode($r['body'] ?? '', true);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'prize' => (string)($j['data']['prize_name'] ?? ($j['data']['name'] ?? '')), 'json' => $j, 'status' => $r['status']];
    }

    // ---- Travel adoption (无猫领养链) ----
    public static function buddyAgreement(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', self::base($realm) . '/v2/activity/growth/buddy/agreement', $headers, '{}', 60);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'status' => $r['status']];
    }

    public static function buddyAdopt(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', self::base($realm) . '/v2/activity/growth/buddy/first', $headers, '{}', 60);
        $j = json_decode($r['body'] ?? '', true);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'status' => $r['status'], 'json' => $j];
    }

    public static function buddyInfo(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('GET', self::base($realm) . '/v2/activity/growth/buddy/info', $headers, null, 60);
        $j = json_decode($r['body'] ?? '', true);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'buddy' => $j['data']['buddy'] ?? null, 'status' => $r['status']];
    }

    // ---- Enterprise usage ----
    public static function enterpriseUsage(array $acct): array
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $url = self::billingBase($realm) . '/v2/billing/meter/get-enterprise-user-usage';
        $headers = Headers::common($realm);
        $headers['Authorization'] = 'Bearer ' . (string)($acct['auth']['accessToken'] ?? '');
        $headers['X-User-Id'] = (string)($acct['account']['uid'] ?? '');
        $r = Http::request('POST', $url, $headers, '{}', 60);
        $j = json_decode($r['body'] ?? '', true);
        $d = $j['data'] ?? [];
        // credit = used this cycle (opposite polarity to personal "remain"); limitNum = quota
        $credit = (int)($d['credit'] ?? 0);
        $limit = (int)($d['limitNum'] ?? ($d['limit_num'] ?? 0));
        $unlimited = ($limit === -1);
        $remain = $unlimited ? -1 : max(0, $limit - $credit);
        return ['ok' => $r['status'] >= 200 && $r['status'] < 300, 'credits' => $remain, 'credits_total' => $unlimited ? -1 : $limit, 'unlimited' => $unlimited, 'cycle_reset' => (int)($d['cycleResetTime'] ?? 0), 'status' => $r['status']];
    }
}
