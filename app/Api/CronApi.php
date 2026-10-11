<?php
declare(strict_types=1);
namespace WB2A\Api;

use WB2A\Core\{Env, Util, RingLog};
use WB2A\Pool;
use WB2A\Upstream\Client;

/**
 * Cron entry: /cron/<kind>?token=...
 * Designed for hosting-panel cron (wget/curl every 10-15 min). The token is
 * generated at install and required for every invocation (no anonymous runs).
 * Kinds: checkin | travel | activity | keepalive | blackcat | balance | all
 *
 * Semantics (aligned with Go scheduler):
 * - checkin/activity/keepalive/balance are "保号" tasks: include_disabled_in_tasks
 *   controls disabled accounts; paused accounts always run them.
 * - blackcat is the only task that is pure model chat: paused AND disabled skip it.
 * - travel/adoption/growth chains always skip disabled accounts.
 */
final class CronApi
{
    public static function run(string $kind = ''): void
    {
        $cfg = Env::config();
        $token = (string)($cfg['cron_token'] ?? '');
        $given = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
        if ($token === '' || !hash_equals($token, $given)) {
            Util::jsonOut(['ok' => false, 'error' => 'unauthorized cron token'], 401);
            return;
        }
        self::recordHit($kind);
        if ($kind === '' || $kind === 'all') { $kind = 'due'; }
        $pool = new Pool();
        if ($kind === 'due') {
            // run all task kinds whose schedule hour matches now, plus balance refresh
            $sched = $cfg['schedule'];
            $hour = (int)date('G');
            $ran = [];
            foreach (['checkin', 'travel', 'activity', 'keepalive', 'blackcat'] as $k) {
                if (empty($sched[$k . '_enabled'])) { continue; }
                $hours = (array)($sched[$k . '_hours'] ?? []);
                $lastDayHour = Env::store()->kvGet('cron_last_' . $k, '');
                $slot = date('Y-m-d') . 'T' . $hour;
                if (in_array($hour, array_map('intval', $hours), true) && $lastDayHour !== $slot) {
                    self::runKind($pool, $k);
                    Env::store()->kvSet('cron_last_' . $k, $slot);
                    $ran[] = $k;
                }
            }
            if (!empty($sched['balance_refresh_enabled'])) {
                $lastBal = (int)Env::store()->kvGet('cron_last_balance', 0);
                if (time() - $lastBal >= max(1, (int)$sched['balance_refresh_minutes']) * 60) {
                    self::runKind($pool, 'balance');
                    Env::store()->kvSet('cron_last_balance', time());
                    $ran[] = 'balance';
                }
            }
            Util::jsonOut(['ok' => true, 'ran' => $ran]);
            return;
        }
        $out = self::runKind($pool, $kind);
        Util::jsonOut(['ok' => true, 'ran' => [$kind], 'detail' => $out]);
    }

    /** Record an authorized cron hit (source IP / UA / kind) for the panel view. */
    private static function recordHit(string $kind): void
    {
        try {
            $hits = Env::store()->kvGet('cron_hits', []);
            if (!is_array($hits)) { $hits = []; }
            array_unshift($hits, ['ts' => time(), 'ip' => Util::clientIp(), 'ua' => mb_substr(Util::userAgent(), 0, 200), 'kind' => $kind]);
            $hits = array_slice($hits, 0, 100);
            Env::store()->kvSet('cron_hits', $hits);
            $today = Env::store()->kvGet('cron_today', []);
            if (!is_array($today) || ($today['date'] ?? '') !== date('Y-m-d')) { $today = ['date' => date('Y-m-d'), 'count' => 0]; }
            $today['count'] = (int)$today['count'] + 1;
            Env::store()->kvSet('cron_today', $today);
        } catch (\Throwable $e) { /* logging must never break the cron run */ }
    }

    /** Execute one task kind synchronously across the pool. Returns per-uid results. */
    public static function runKind(Pool $pool, string $kind): array
    {
        $out = [];
        $includeDisabled = (bool)(Env::config()['schedule']['include_disabled_in_tasks'] ?? false);
        foreach ($pool->allUIDs() as $uid) {
            $acct = $pool->account($uid);
            if (!$acct) { continue; }
            $st = $pool->stateFor($uid);
            $isEnt = trim((string)($acct['account']['enterpriseId'] ?? '')) !== '';
            $disabled = !empty($st['disabled']);
            $paused = !empty($st['paused']);
            $isKeepAliveKind = in_array($kind, ['checkin', 'activity', 'keepalive', 'balance'], true);
            if ($isKeepAliveKind && $disabled && !$includeDisabled) { continue; }
            if ($kind === 'blackcat' && ($disabled || $paused)) { continue; }
            if (!$isKeepAliveKind && $kind !== 'blackcat' && $disabled) { continue; } // travel/growth chains
            usleep(800000); // account pacing (Go: 800ms between accounts)
            try {
                switch ($kind) {
                    case 'checkin':
                        if ($isEnt) { $out[$uid] = 'skipped (enterprise)'; break; }
                        $out[$uid] = self::doCheckin($pool, $acct, $uid);
                        break;
                    case 'balance':
                        $out[$uid] = self::doBalance($pool, $acct, $uid);
                        break;
                    case 'activity':
                        if ($isEnt) { $out[$uid] = 'skipped (enterprise)'; break; }
                        $r = Client::reportChatActivity($acct, 'wb2api-' . round(microtime(true) * 1000));
                        $line = 'report http=' . $r['status'];
                        // streak self-check (read-only oracle): days=0 after OK report → warn
                        $sr = Client::growthStreak($acct);
                        if ($sr['ok']) {
                            $days = $sr['days'];
                            $line .= " streak days=$days";
                            if ((int)$r['status'] >= 200 && (int)$r['status'] < 300 && $days === 0) {
                                RingLog::task("activity $uid: report OK but streak.days=0 (silent drop?)");
                            }
                        }
                        $out[$uid] = $line;
                        break;
                    case 'keepalive':
                        $r = $pool->refreshTokenIfNeeded($uid, true);
                        $out[$uid] = $r['ok'] ? 'token refreshed' : 'refresh failed';
                        break;
                    case 'travel':
                        $out[$uid] = self::doTravel($acct, $uid);
                        break;
                    case 'blackcat':
                        $out[$uid] = self::doBlackcat($acct, $uid);
                        break;
                    default:
                        $out[$uid] = 'unknown kind';
                }
            } catch (\Throwable $e) {
                $out[$uid] = 'error: ' . mb_substr($e->getMessage(), 0, 120);
            }
            RingLog::task("cron $kind $uid: " . $out[$uid]);
        }
        return $out;
    }

    /** Checkin + balance + 连登管家 (streak redeem + lottery, idempotent best-effort). */
    private static function doCheckin(Pool $pool, array $acct, string $uid): string
    {
        $r = Client::dailyCheckin($acct);
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        $code = (string)($j['code'] ?? '');
        $already = in_array($code, ['10001', '14001'], true) || stripos((string)$r['body'], '已签') !== false;
        $bal = self::fetchBalance($acct);
        if ($bal !== null) { $pool->setCredits($uid, $bal['credits'], $bal['credits_total'], $bal['packages']); }
        $line = 'checkin http=' . $r['status'] . ($already ? ' (already today)' : '')
            . ' credits=' . ($bal['credits'] ?? '?');
        // 连登管家: redeem unlocked tiers then draw all lottery chances (idempotent)
        $sr = Client::growthStreak($acct);
        if ($sr['ok']) {
            foreach ($sr['tiers'] as $tier) {
                $state = (string)($tier['state'] ?? '');
                $isLocked = !empty($tier['locked']);
                $claimed = !empty($tier['claimed']) || !empty($tier['redeemed']);
                if ($isLocked || $claimed) { continue; }
                $t = $tier['tier'] ?? ($tier['days'] ?? null);
                if ($t === null) { continue; }
                $rr = Client::redeemTier($acct, $t);
                $line .= " redeem(t=$t)=" . $rr['status'];
            }
        }
        $ls = Client::lotterySummary($acct);
        if ($ls['ok'] && $ls['chances'] > 0) {
            for ($n = 0; $n < $ls['chances'] && $n < 20; $n++) {
                $d = Client::lotteryDraw($acct);
                if (!$d['ok']) { break; }
                $line .= " 🎲" . ($d['prize'] !== '' ? $d['prize'] : $d['status']);
            }
        }
        return $line;
    }

    /** Balance refresh; enterprise accounts use the enterprise usage endpoint. */
    private static function doBalance(Pool $pool, array $acct, string $uid): string
    {
        $bal = self::fetchBalance($acct);
        if ($bal === null) { return 'balance query failed'; }
        $pool->setCredits($uid, $bal['credits'], $bal['credits_total'], $bal['packages'] ?? []);
        return ($bal['unlimited'] ?? false) ? 'credits=不限' : 'credits=' . $bal['credits'];
    }

    /** Personal userResource or enterprise usage (剩余 = limitNum - credit; -1 = 不限量). */
    private static function fetchBalance(array $acct): ?array
    {
        if (trim((string)($acct['account']['enterpriseId'] ?? '')) !== '') {
            $r = Client::enterpriseUsage($acct);
            if (!$r['ok']) { return null; }
            return ['credits' => $r['credits'], 'credits_total' => $r['credits_total'], 'packages' => [], 'unlimited' => $r['unlimited']];
        }
        $r = Client::userResource($acct);
        if (($r['ok'] ?? false) === false && isset($r['status']) && $r['status'] >= 400) { return null; }
        return ['credits' => $r['credits'], 'credits_total' => $r['credits_total'], 'packages' => $r['packages'] ?? [], 'unlimited' => false];
    }

    /** Travel state machine with adoption chain (agreement → first → depart). */
    private static function doTravel(array $acct, string $uid): string
    {
        if (trim((string)($acct['account']['enterpriseId'] ?? '')) !== '') { return 'skipped (enterprise)'; }
        $realm = $acct['auth']['realm'] ?? 'cn';
        if ($realm === 'global') { return 'skipped (global realm)'; }
        // adoption: no buddy → agreement (idempotent) → first adopt; threshold failure retried once per day
        $info = Client::buddyInfo($acct);
        if ($info['ok'] && $info['buddy'] === null) {
            $adoptKey = 'travel_adopt_' . $uid . '_' . date('Y-m-d');
            if (Env::store()->kvGet($adoptKey, '')) { return 'no buddy (adopt attempted today)'; }
            Env::store()->kvSet($adoptKey, '1');
            Client::buddyAgreement($acct);
            $a = Client::buddyAdopt($acct);
            return 'adopt http=' . $a['status'];
        }
        $headers = ['Authorization' => 'Bearer ' . (string)($acct['auth']['accessToken'] ?? ''), 'X-User-Id' => $uid];
        $st = \WB2A\Core\Http::request('GET', Client::base('cn') . '/v2/activity/growth/buddy/travel/status', $headers, null, 30);
        $j = json_decode((string)$st['body'], true);
        $state = (string)(($j['data']['travel'] ?? [])['state'] ?? ($j['data']['state'] ?? ''));
        if ($state === 'arrived') {
            $r = \WB2A\Core\Http::request('POST', Client::base('cn') . '/v2/activity/growth/buddy/travel/claim', $headers + ['Content-Type' => 'application/json'], '{}', 30);
            return 'claim http=' . $r['status'];
        }
        if ($state === 'idle' || $state === '') {
            // one depart per CST natural day
            $cstDay = gmdate('Y-m-d', time() + 8 * 3600);
            $depKey = 'travel_depart_' . $uid . '_' . $cstDay;
            if (Env::store()->kvGet($depKey, '')) { return 'already departed today (CST)'; }
            $r = \WB2A\Core\Http::request('POST', Client::base('cn') . '/v2/activity/growth/buddy/travel/depart', $headers + ['Content-Type' => 'application/json'], '{"location_id":4}', 30);
            Env::store()->kvSet($depKey, '1');
            return 'depart http=' . $r['status'];
        }
        return 'state=' . ($state ?: 'unknown') . ' skipped';
    }

    /** 夜猫子: check black_cat task progress first; only then one real glm-5.2 short chat in the 23:00-08:00 window. */
    private static function doBlackcat(array $acct, string $uid): string
    {
        if (trim((string)($acct['account']['enterpriseId'] ?? '')) !== '') { return 'skipped (enterprise)'; }
        $realm = $acct['auth']['realm'] ?? 'cn';
        if ($realm === 'global') { return 'skipped (global realm)'; }
        $h = (int)date('G');
        if ($h >= 8 && $h < 23) { return 'outside night window'; }
        $last = Env::store()->kvGet('blackcat_last_' . $uid, '');
        if ($last === gmdate('Y-m-d', time() + 8 * 3600)) { return 'already done today'; }
        // progress check: black_cat target met → skip
        $tasks = Client::growthTasks($acct);
        foreach ($tasks as $t) {
            if (($t['task_code'] ?? '') !== 'black_cat') { continue; }
            if ((int)($t['claimed'] ?? 0) === 1 || (int)($t['current'] ?? 0) >= max(1, (int)($t['target'] ?? 3))) { return 'black_cat already met'; }
        }
        $r = self::shortChat($acct, 'glm-5.2', 'ping');
        Env::store()->kvSet('blackcat_last_' . $uid, gmdate('Y-m-d', time() + 8 * 3600));
        return $r ? 'night chat sent (glm-5.2)' : 'night chat failed';
    }

    /** Minimal real upstream chat (non-stream aggregation, no pool rotation). */
    private static function shortChat(array $acct, string $model, string $text): bool
    {
        $realm = $acct['auth']['realm'] ?? 'cn';
        $payload = json_encode([
            'model' => $model,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => $text]],
            'max_tokens' => 32,
        ], JSON_UNESCAPED_SLASHES);
        $headers = \WB2A\Upstream\Headers::chat($realm, (string)($acct['account']['uid'] ?? ''), (string)($acct['auth']['accessToken'] ?? ''), (string)($acct['account']['enterpriseId'] ?? ''));
        $r = \WB2A\Core\Http::request('POST', Client::base($realm) . '/v2/chat/completions', $headers, $payload, 90, function (string $line) use (&$got) {
            if (strpos($line, 'data:') === 0 && strpos($line, '[DONE]') === false) { $got = true; }
        }, ['idle_timeout' => 60]);
        return ($r['ok'] ?? false) && ($r['status'] ?? 0) < 400 && !empty($got);
    }
}
