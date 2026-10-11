<?php
declare(strict_types=1);
namespace WB2A\Api;

use WB2A\Core\{Env, Util};
use WB2A\Pool;
use WB2A\Upstream\{Client, Classify};

final class ModelsApi
{
    public static function list(string $rest = ''): void
    {
        if (!Util::requireAuth()) {
            Util::jsonOut(['error' => ['message' => 'missing or invalid API key', 'type' => 'api_error', 'code' => 'invalid_api_key']], 401);
            return;
        }
        $cacheKey = 'models_cache';
        $cached = Env::store()->kvGet($cacheKey);
        if (is_array($cached) && ($cached['ts'] ?? 0) > time() - 600) {
            Util::jsonOut($cached['data']);
            return;
        }
        $pool = new Pool();
        $data = ['object' => 'list', 'data' => []];
        $gotAny = false;
        $globalEnabled = true;
        foreach (['cn', 'global'] as $realm) {
            if ($realm === 'global' && !$globalEnabled) { continue; }
            // pick any healthy account of the realm to fetch models
            $uid = $pool->pick([], null, $realm);
            if ($uid === null) { continue; }
            $acct = $pool->account($uid);
            if (!$acct) { continue; }
            $r = Client::models($realm, (string)$acct['auth']['accessToken'], $uid);
            if (($r['ok'] ?? false) && !empty($r['models'])) { $gotAny = true; }
            foreach (($r['models'] ?? []) as $m) { $data['data'][] = $m; }
        }
        if ($gotAny) {
            Env::store()->kvSet($cacheKey, ['ts' => time(), 'data' => $data]);
            Env::store()->kvSet('models_fail_ts', 0);
        } else {
            // negative cache: serve stale/empty for 5 min before retrying upstream
            $lastFail = (int)Env::store()->kvGet('models_fail_ts', 0);
            Env::store()->kvSet('models_fail_ts', time());
            if ($lastFail > time() - 300 && is_array($cached)) { $data = $cached['data']; }
        }
        Util::jsonOut($data);
    }
}
