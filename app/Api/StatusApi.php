<?php
declare(strict_types=1);
namespace WB2A\Api;

use WB2A\Core\{Env, Util};
use WB2A\Pool;

final class StatusApi
{
    public static function status(string $rest = ''): void
    {
        if (!Util::requireAuth()) {
            Util::jsonOut(['error' => ['message' => 'missing or invalid API key', 'type' => 'api_error', 'code' => 'invalid_api_key']], 401);
            return;
        }
        $pool = new Pool();
        $s = $pool->status();
        $out = [
            'total' => $s['total'], 'healthy' => $s['healthy'], 'cooling' => $s['cooling'],
            'disabled' => $s['disabled'], 'in_flight_full' => $s['in_flight_full'],
            'accounts' => $s['accounts'], 'sticky_sessions' => $s['sticky_sessions'],
            'redis_mode' => 'noop', 'model_locks' => $s['model_locks'], 'credit_floor' => (int)(Env::config()['pool']['credit_floor'] ?? 0),
            'realm_totals' => ['cn' => self::realmTotals($s, 'cn'), 'global' => self::realmTotals($s, 'global')],
        ];
        Util::jsonOut($out);
    }

    private static function realmTotals(array $s, string $realm): array
    {
        $t = ['total' => 0, 'healthy' => 0, 'cooling' => 0, 'disabled' => 0, 'in_flight_full' => 0];
        foreach ($s['accounts'] as $a) {
            if (($a['realm'] ?? '') !== $realm) { continue; }
            $t['total']++;
            if (!$a['disabled'] && !$a['cooling'] && ($a['in_flight'] ?? 0) === 0) { $t['healthy']++; }
            if ($a['cooling']) { $t['cooling']++; }
            if ($a['disabled']) { $t['disabled']++; }
        }
        return $t;
    }

    public static function health(string $rest = ''): void
    {
        header('X-Service: workbuddy2api');
        $pool = new Pool();
        // servable = healthy AND has a free in-flight slot; per-realm view
        $cn = $pool->servableCount('cn');
        $gl = $pool->servableCount('global');
        $total = count($pool->allUIDs());
        $servable = $cn + $gl;
        $body = ['healthy' => $servable, 'total' => $total, 'service' => 'workbuddy2api', 'realm_servable' => ['cn' => $cn > 0, 'global' => $gl > 0]];
        Util::jsonOut($body, $servable > 0 ? 200 : 503);
    }
}
