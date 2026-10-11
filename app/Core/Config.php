<?php
declare(strict_types=1);
namespace WB2A\Core;

final class Config
{
    /** Merge user config over defaults (deep merge, unknown keys preserved). */
    public static function withDefaults(array $cfg): array
    {
        $def = self::defaults();
        foreach ($def as $k => $v) {
            if (!array_key_exists($k, $cfg) || $cfg[$k] === null) {
                $cfg[$k] = $v;
            }
        }
        foreach ($def as $k => $v) {
            if (is_array($v) && !isset($v[0]) && isset($cfg[$k]) && is_array($cfg[$k])) {
                foreach ($v as $kk => $vv) {
                    if (!array_key_exists($kk, $cfg[$k]) || $cfg[$k][$kk] === null) { $cfg[$k][$kk] = $vv; }
                }
            }
        }
        return $cfg;
    }

    public static function defaults(): array
    {
        return [
            'api_key' => '',
            'storage' => ['mode' => 'auto', 'mysql' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'prefix' => 'wb2a_']],
            'schedule' => [
                'checkin_hours' => [9, 21], 'travel_hours' => [9, 21], 'activity_hours' => [10],
                'keepalive_hours' => [22], 'blackcat_hours' => [23],
                'checkin_enabled' => true, 'travel_enabled' => true, 'activity_enabled' => true,
                'keepalive_enabled' => true, 'blackcat_enabled' => true,
                'balance_refresh_enabled' => true, 'balance_refresh_minutes' => 5,
                'include_disabled_in_tasks' => false,
            ],
            'cooldown' => ['soft_rate' => '600s', 'soft_rate_max' => '2h'],
            'upstream' => ['timeout_seconds' => 120, 'header_timeout_seconds' => 120, 'idle_timeout_seconds' => 300, 'user_agent' => '', 'client_version' => '5.5.4', 'cli_version' => '2.137.1'],
            'features' => ['sanitize_blacklist_fingerprints' => true],
            'prompt' => ['mode' => 'custom', 'file' => ''],
            'pool' => [
                'max_in_flight' => 3, 'max_in_flight_global' => 2,
                'breaker_threshold' => 3, 'breaker_cooldown' => '30m', 'breaker_cooldown_max' => '6h',
                'degrade_threshold' => 5, 'degrade_cooldown' => '10m', 'degrade_cooldown_max' => '2h',
                'idle_weight_per_hour' => 0.5, 'idle_weight_max' => 5.0,
                'prefer_expiring' => true, 'expiring_soon' => '168h',
                'credit_floor' => 100,
            ],
            'session_sticky' => ['enabled' => true, 'ttl' => '30m', 'gc_interval' => '5m'],
            'logging' => ['request_archive_enabled' => true, 'request_retention_days' => 7, 'request_client_info' => true],
            'panel' => ['admin_user' => 'admin', 'package_detail_limit' => 5],
        ];
    }

    public static function softRateSec(): int
    {
        $c = Env::config();
        return Util::durationToSeconds($c['cooldown']['soft_rate'] ?? '600s', 600);
    }

    public static function softRateMaxSec(): int
    {
        $c = Env::config();
        return Util::durationToSeconds($c['cooldown']['soft_rate_max'] ?? '2h', 7200);
    }

    public static function stickyTtlSec(): int
    {
        $c = Env::config();
        return Util::durationToSeconds($c['session_sticky']['ttl'] ?? '30m', 1800);
    }

    public static function breakerCooldownSec(): int
    {
        $c = Env::config();
        return Util::durationToSeconds($c['pool']['breaker_cooldown'] ?? '30m', 1800);
    }

    public static function breakerCooldownMaxSec(): int
    {
        $c = Env::config();
        return Util::durationToSeconds($c['pool']['breaker_cooldown_max'] ?? '6h', 21600);
    }

    public static function degradeCooldownSec(): int
    {
        $c = Env::config();
        return Util::durationToSeconds($c['pool']['degrade_cooldown'] ?? '10m', 600);
    }

    public static function restartRequiredFields(): array
    {
        return ['listen(不适用:PHP共享主机由Web服务器托管)', 'auth_dir', 'state_file', 'upstream.timeout_seconds', 'upstream.header_timeout_seconds', 'upstream.idle_timeout_seconds', 'upstream.user_agent', 'upstash', 'session_sticky.ttl', 'session_sticky.gc_interval', 'logging.request_archive_enabled', 'logging.request_retention_days', 'logging.request_archive_max_mb', 'server.read_timeout'];
    }

    /** Deep merge incoming panel config into on-disk config preserving unknown keys. */
    public static function mergeDeep(array $cur, array $incoming): array
    {
        foreach ($incoming as $k => $v) {
            if (is_array($v) && !isset($v[0]) && isset($cur[$k]) && is_array($cur[$k]) && !isset($cur[$k][0])) {
                $cur[$k] = self::mergeDeep($cur[$k], $v);
            } else {
                $cur[$k] = $v;
            }
        }
        return $cur;
    }
}
