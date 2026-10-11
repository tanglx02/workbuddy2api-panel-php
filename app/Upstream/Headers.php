<?php
declare(strict_types=1);
namespace WB2A\Upstream;

/** Outbound header construction (mirrors Go internal/upstream/headers.go). */
final class Headers
{
    public const CN_BASE = 'https://copilot.tencent.com';
    public const GLOBAL_BASE = 'https://www.workbuddy.ai';
    public const CN_BILLING = 'https://www.codebuddy.cn';
    public const GLOBAL_BILLING = 'https://www.workbuddy.ai';
    public const CN_WEB = 'https://www.workbuddy.cn';

    public static function base(string $realm): string
    {
        return $realm === 'global' ? self::GLOBAL_BASE : self::CN_BASE;
    }

    public static function billingBase(string $realm): string
    {
        return $realm === 'global' ? self::GLOBAL_BILLING : self::CN_BILLING;
    }

    public static function ua(string $realm): string
    {
        $cfg = \WB2A\Core\Env::config()['upstream'] ?? [];
        if (!empty($cfg['user_agent'])) { return (string)$cfg['user_agent']; }
        $ver = (string)($cfg['client_version'] ?? '5.5.4');
        $cli = (string)($cfg['cli_version'] ?? '2.137.1');
        $platform = $realm === 'global' ? 'WorkBuddy AI' : 'WorkBuddy';
        return "WorkBuddy/{$ver} {$platform}/{$ver} CLI/{$cli}";
    }

    private static function stableID(string $uid, string $purpose): string
    {
        $h = hash('sha256', 'wb2a:' . $purpose . ':' . $uid);
        return substr($h, 0, 36);
    }

    /** Device risk-control token: config > file data/device_token.txt > stable per-uid fallback. */
    private static function deviceToken(string $uid): string
    {
        $cfg = \WB2A\Core\Env::config()['upstream'] ?? [];
        if (!empty($cfg['device_token'])) { return (string)$cfg['device_token']; }
        $f = WB2A_ROOT . '/data/device_token.txt';
        if (is_readable($f)) {
            $t = trim((string)file_get_contents($f));
            if ($t !== '') { return $t; }
        }
        return self::stableID($uid, 'device');
    }

    public static function common(string $realm): array
    {
        $origin = $realm === 'global' ? self::GLOBAL_BASE : 'https://www.codebuddy.cn';
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/plain, */*',
            'X-Requested-With' => 'XMLHttpRequest',
            'Origin' => $origin,
            'Referer' => $origin . '/',
            'User-Agent' => self::ua($realm),
            'X-CodeBuddy-Request' => '1',
            'Accept-Language' => $realm === 'global' ? 'en-US' : 'zh-CN',
        ];
    }

    /** Chat request headers. NEVER includes X-Refresh-Token. */
    public static function chat(string $realm, string $uid, string $accessToken, string $enterpriseId = ''): array
    {
        $h = self::common($realm);
        $h['Accept'] = 'application/json, text/event-stream';
        $h['X-Machine-ID'] = self::stableID($uid, 'machine');
        $h['X-Session-ID'] = self::stableID($uid, 'session');
        $h['User-Agent'] = self::ua($realm);
        if ($accessToken !== '') { $h['Authorization'] = 'Bearer ' . $accessToken; }
        else { $h['X-No-Authorization'] = '1'; }
        if ($uid !== '') { $h['X-User-Id'] = $uid; } else { $h['X-No-User-Id'] = '1'; }
        if ($realm === 'global') {
            $h['X-No-Enterprise-Id'] = '1';
            $h['X-Domain'] = 'www.workbuddy.ai';
        } elseif ($enterpriseId !== '') {
            $h['X-Enterprise-Id'] = $enterpriseId;
            $h['X-Domain'] = $enterpriseId;
        } else {
            $h['X-No-Enterprise-Id'] = '1';
            $h['X-No-Department-Info'] = '1';
        }
        $h['X-Agent-Purpose'] = 'conversation';
        $h['X-Device-Token'] = self::deviceToken($uid);
        $h['X-IDE-Name'] = 'WorkBuddy';
        $h['X-IDE-Type'] = 'WorkBuddy';
        $h['X-IDE-Version'] = (string)(\WB2A\Core\Env::config()['upstream']['client_version'] ?? '5.5.4');
        $h['X-Product'] = 'WorkBuddy';
        return $h;
    }

    public static function refresh(string $realm): array
    {
        return self::common($realm);
    }

    public static function claimWeb(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => self::ua('cn'),
            'x-client-platform' => 'web',
            'Origin' => self::CN_WEB,
            'Referer' => self::CN_WEB . '/',
        ];
    }
}
