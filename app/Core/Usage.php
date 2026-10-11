<?php
declare(strict_types=1);
namespace WB2A\Core;

/** Usage recording: hourly buckets aggregated into panel analytics. */
final class Usage
{
    public static function add(string $realm, string $uid, string $model, array $d, bool $ok): void
    {
        $scope = 'h:' . date('Y-m-d\TH', time());
        $realm = $realm ?: 'cn';
        $model = $model ?: '(unknown)';
        $key = implode('|', [$scope, $realm, $uid, $model]);
        $store = Env::store();
        $buckets = $store->usageAll();
        $b = $buckets[$key] ?? null;
        if (!is_array($b)) { $b = ['s' => $scope, 'r' => $realm, 'u' => $uid, 'm' => $model, 'q' => 0, 'e' => 0, 'p' => 0, 'c' => 0, 't' => 0, 'l' => 0, 'ln' => 0, 'v' => 0, 'vn' => 0, 'cr' => 0, 'crn' => 0, 'crt' => 0, 'ch' => 0, 'cm' => 0]; }
        $b['q']++;
        if (!$ok) { $b['e']++; }
        $b['p'] += (int)($d['prompt_tokens'] ?? 0);
        $b['c'] += (int)($d['completion_tokens'] ?? 0);
        $t = (int)($d['total_tokens'] ?? 0) ?: ((int)($d['prompt_tokens'] ?? 0) + (int)($d['completion_tokens'] ?? 0));
        $b['t'] += $t;
        if (!empty($d['latency_ms'])) { $b['l'] += (int)$d['latency_ms']; $b['ln']++; }
        if (!empty($d['tps'])) { $b['v'] += (float)$d['tps']; $b['vn']++; }
        if (!empty($d['credit'])) { $b['cr'] += (float)$d['credit']; $b['crn']++; if ($t > 0) { $b['crt'] += $t; } }
        if (!empty($d['cache_hit_tokens'])) { $b['ch'] += (int)$d['cache_hit_tokens']; }
        if (!empty($d['cache_miss_tokens'])) { $b['cm'] += (int)$d['cache_miss_tokens']; }
        $store->usagePut($key, $b);
        // opportunistic 90-day hourly->daily rollup is skipped in PHP edition (documented limitation)
    }

    /** Snapshot aggregated within window (from/to unix seconds). */
    public static function snapshot(?int $from, ?int $to): array
    {
        $buckets = Env::store()->usageAll();
        $tot = self::blank();
        $byAcct = []; $byModel = []; $byRealm = []; $series = [];
        foreach ($buckets as $b) {
            $ts = self::bucketTime((string)($b['s'] ?? ''));
            if ($from !== null && $ts < $from) { continue; }
            if ($to !== null && $ts > $to) { continue; }
            self::acc($tot, $b);
            self::accKey($byAcct, (string)($b['u'] ?? ''), $b);
            self::accKey($byModel, (string)($b['m'] ?? ''), $b);
            self::accKey($byRealm, (string)($b['r'] ?? 'cn'), $b);
            self::accKey($series, (string)($b['s'] ?? ''), $b);
        }
        $fmt = function (array $map): array {
            $out = [];
            foreach ($map as $k => $v) { $out[] = ['key' => $k] + self::finalize($v); }
            usort($out, fn($a, $b) => ($b['total_tokens'] ?? 0) <=> ($a['total_tokens'] ?? 0));
            return $out;
        };
        return [
            'totals' => self::finalize($tot),
            'by_realm' => $fmt($byRealm),
            'by_account' => $fmt($byAcct),
            'by_model' => $fmt($byModel),
            'series' => array_map(function ($k, $v) { return ['t' => substr($k, 2)] + self::finalize($v); }, array_keys($series), array_values($series)),
            'buckets' => count($buckets),
            'window_from' => $from,
            'window_to' => $to,
            'generated' => date('c'),
        ];
    }

    private static function bucketTime(string $scope): int
    {
        if (strpos($scope, 'h:') === 0) { return strtotime(substr($scope, 2) . ':00:00') ?: 0; }
        if (strpos($scope, 'd:') === 0) { return strtotime(substr($scope, 2) . ' 00:00:00') ?: 0; }
        return 0;
    }

    private static function blank(): array
    {
        return ['q' => 0, 'e' => 0, 'p' => 0, 'c' => 0, 't' => 0, 'l' => 0, 'ln' => 0, 'v' => 0, 'vn' => 0, 'cr' => 0.0, 'crn' => 0, 'crt' => 0, 'ch' => 0, 'cm' => 0];
    }

    private static function acc(array &$agg, array $b): void
    {
        foreach (['q', 'e', 'p', 'c', 't', 'l', 'ln', 'v', 'vn', 'cr', 'crn', 'crt', 'ch', 'cm'] as $k) { $agg[$k] += (float)($b[$k] ?? 0); }
    }

    private static function accKey(array &$map, string $k, array $b): void
    {
        if (!isset($map[$k])) { $map[$k] = self::blank(); }
        self::acc($map[$k], $b);
    }

    private static function finalize(array $a): array
    {
        $out = [
            'requests' => (int)$a['q'], 'errors' => (int)$a['e'],
            'prompt_tokens' => (int)$a['p'], 'completion_tokens' => (int)$a['c'], 'total_tokens' => (int)$a['t'],
            'credits' => round($a['cr'], 4), 'credit_samples' => (int)$a['crn'],
            'avg_latency_ms' => $a['ln'] > 0 ? (int)round($a['l'] / $a['ln']) : 0,
            'avg_tokens_per_second' => $a['vn'] > 0 ? round($a['v'] / $a['vn'], 2) : 0,
            'cache_hit_tokens' => (int)$a['ch'], 'cache_miss_tokens' => (int)$a['cm'],
        ];
        $out['credits_per_1m_tokens'] = $a['crt'] > 0 ? round($a['cr'] / $a['crt'] * 1000000, 3) : 0;
        return $out;
    }
}
