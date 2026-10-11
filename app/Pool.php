<?php
declare(strict_types=1);
namespace WB2A;

use WB2A\Core\{Env, Store, Util, Crypto, Config};

/**
 * Account pool: rotation, cooldown/breaker/degrade, session stickiness,
 * status views. State persisted via Store (kv 'pool_state' + accounts table).
 */
final class Pool
{
    private Store $store;
    private array $state = []; // uid => state array (cooldowns, counters, credits...)

    public function __construct(?Store $store = null)
    {
        $this->store = $store ?? Env::store();
        $this->state = $this->store->kvGet('pool_state', []);
        if (!is_array($this->state)) { $this->state = []; }
    }

    // ---------- Account CRUD ----------
    public function addAccount(array $acct): void
    {
        $uid = (string)($acct['account']['uid'] ?? '');
        if ($uid === '' || !Util::validUID($uid)) { throw new \InvalidArgumentException('invalid uid'); }
        if (empty($acct['auth']['accessToken'])) { throw new \InvalidArgumentException('accessToken required'); }
        $acct['auth']['accessToken'] = Crypto::encrypt((string)$acct['auth']['accessToken']);
        $acct['auth']['refreshToken'] = Crypto::encrypt((string)($acct['auth']['refreshToken'] ?? ''));
        $this->store->accountPut($uid, $acct);
        if (!isset($this->state[$uid])) {
            $this->state[$uid] = ['credits' => 0, 'soft_streak' => 0, 'session_dead_fails' => 0, 'consecutive_fails' => 0, 'model_cooldowns' => new \stdClass(), 'model_costs' => new \stdClass()];
        }
        $this->persist();
    }

    public function importPlain(string $uid, array $fields): void
    {
        $this->addAccount(['auth' => ['accessToken' => $fields['access_token'], 'refreshToken' => $fields['refresh_token'] ?? '', 'expiresAt' => $fields['expires_at'] ?? 0, 'realm' => $fields['realm'] ?? 'cn'], 'account' => ['uid' => $uid, 'nickname' => $fields['nickname'] ?? '', 'enterpriseId' => $fields['enterprise_id'] ?? '']]);
    }

    public function remove(string $uid): bool
    {
        if (!isset($this->state[$uid]) && !$this->store->accountGet($uid)) { return false; }
        $this->store->accountDelete($uid);
        unset($this->state[$uid]);
        $this->persist();
        return true;
    }

    public function has(string $uid): bool { return $this->store->accountGet($uid) !== null; }

    /** Decrypted account record. */
    public function account(string $uid): ?array
    {
        $a = $this->store->accountGet($uid);
        if (!$a) { return null; }
        $a['auth']['accessToken'] = Crypto::decrypt((string)($a['auth']['accessToken'] ?? ''));
        $a['auth']['refreshToken'] = Crypto::decrypt((string)($a['auth']['refreshToken'] ?? ''));
        return $a;
    }

    private function realmOf(array $acct): string
    {
        $r = $acct['auth']['realm'] ?? '';
        if ($r === 'global' || $r === 'cn') { return $r; }
        $domain = (string)($acct['auth']['domain'] ?? '');
        return (stripos($domain, 'workbuddy.ai') !== false) ? 'global' : 'cn';
    }

    private function isEnterprise(array $acct): bool
    {
        return trim((string)($acct['account']['enterpriseId'] ?? '')) !== '';
    }

    // ---------- Health / cooldown ----------
    private function st(string $uid): array
    {
        return $this->state[$uid] ?? ['credits' => 0, 'soft_streak' => 0, 'session_dead_fails' => 0, 'consecutive_fails' => 0, 'model_cooldowns' => [], 'model_costs' => []];
    }

    private function healthy(string $uid, ?string $model = null): bool
    {
        $s = $this->st($uid);
        if (!empty($s['disabled']) || !empty($s['paused'])) { return false; }
        $now = time();
        if (!empty($s['until']) && (int)$s['until'] > $now) { return false; }
        if (!empty($s['breaker_until']) && (int)$s['breaker_until'] > $now) { return false; }
        if (!empty($s['degrade_until']) && (int)$s['degrade_until'] > $now) { return false; }
        if ($model !== null) {
            $mc = (array)($s['model_cooldowns'] ?? []);
            if (isset($mc[$model]) && (int)($mc[$model]['until'] ?? 0) > $now && empty($mc[$model]['audit_only'])) { return false; }
        }
        return true;
    }

    private function inFlightFull(string $uid, string $realm): bool
    {
        $s = $this->st($uid);
        $max = (int)(($realm === 'global' ? Env::config()['pool']['max_in_flight_global'] : Env::config()['pool']['max_in_flight']) ?? 3);
        if ($max <= 0) { return false; }
        return (int)($s['in_flight'] ?? 0) >= $max;
    }

    private function floorBlocked(string $uid, ?string $model): bool
    {
        $floor = (int)(Env::config()['pool']['credit_floor'] ?? 0);
        if ($floor <= 0 || $model === null || $model === '') { return false; }
        $s = $this->st($uid);
        if ((int)$s['credits'] >= $floor) { return false; }
        $costs = (array)($s['model_costs'] ?? []);
        if (isset($costs[$model])) { return (float)($costs[$model]['cost_per_1k'] ?? 0) > 0; }
        return false; // tier1 unknown: allow (avoid deadlock)
    }

    // ---------- Pick ----------
    public function pick(array $tried, ?string $model, string $realm): ?string
    {
        $cands = [];
        foreach ($this->store->accountAll() as $uid => $acct) {
            if (in_array($uid, $tried, true)) { continue; }
            if ($this->realmOf($acct) !== $realm) { continue; }
            if (!$this->healthy($uid, $model)) { continue; }
            if ($this->inFlightFull($uid, $realm)) { continue; }
            if ($this->floorBlocked($uid, $model)) { continue; }
            $cands[$uid] = $acct;
        }
        if ($cands === []) { return $this->pickEarliestExpiry($tried, $realm, $model); }
        // cost tiers
        $tierOf = function (string $uid) use ($model): int {
            $costs = (array)($this->st($uid)['model_costs'] ?? []);
            if ($model !== null && isset($costs[$model])) {
                return (float)$costs[$model]['cost_per_1k'] > 0 ? 2 : 0;
            }
            return 1;
        };
        if ($model !== null) {
            $best = PHP_INT_MAX;
            foreach ($cands as $uid => $_) { $t = $tierOf($uid); if ($t < $best) { $best = $t; } }
            foreach ($cands as $uid => $_) { if ($tierOf($uid) !== $best) { unset($cands[$uid]); } }
        }
        // weights
        $maxCredits = 1;
        foreach ($cands as $uid => $_) { $maxCredits = max($maxCredits, (int)$this->st($uid)['credits']); }
        $cfg = Env::config()['pool'];
        $expWindow = Util::durationToSeconds($cfg['expiring_soon'] ?? '168h', 604800);
        $weights = [];
        foreach ($cands as $uid => $_) {
            $s = $this->st($uid);
            $idleH = isset($s['last_used']) ? max(0, (time() - (int)$s['last_used']) / 3600) : 999;
            $idle = min($idleH * (float)($cfg['idle_weight_per_hour'] ?? 0.5), (float)($cfg['idle_weight_max'] ?? 5.0));
            $w = 1.0 + ((int)$s['credits'] / $maxCredits) * 10.0 + $idle;
            if (!empty($cfg['prefer_expiring']) && !empty($s['credits_expiring']) && (int)$s['credits_expiring'] > 0
                && (!empty($s['credits_earliest_expiry']) && (int)$s['credits_earliest_expiry'] > time())
                && (int)$s['credits_earliest_expiry'] - time() <= $expWindow) {
                $w *= 3.0;
            }
            $weights[$uid] = $w;
        }
        // top-5 shortlist
        arsort($weights);
        $top = array_slice(array_keys($weights), 0, 5);
        // anti-stampede: drop accounts used within 100ms, then weighted random draw
        $nowMs = (int)(microtime(true) * 1000);
        $top = array_values(array_filter($top, fn($u) => $nowMs - (int)($this->st($u)['last_used_ms'] ?? 0) >= 100));
        if ($top === []) { $top = array_slice(array_keys($weights), 0, 5); }
        $totalW = 0.0;
        foreach ($top as $u) { $totalW += $weights[$u]; }
        if ($totalW <= 0) { return $this->markPicked($top[0]); }
        $r = mt_rand() / mt_getrandmax() * $totalW;
        foreach ($top as $u) {
            $r -= $weights[$u];
            if ($r <= 0) { return $this->markPicked($u); }
        }
        return $this->markPicked($top[0]);
    }

    private function pickEarliestExpiry(array $tried, string $realm, ?string $model = null): ?string
    {
        $best = null; $bestExp = PHP_INT_MAX;
        foreach ($this->store->accountAll() as $uid => $acct) {
            if (in_array($uid, $tried, true) || $this->realmOf($acct) !== $realm) { continue; }
            $s = $this->st($uid);
            if (!empty($s['disabled']) || !empty($s['paused'])) { continue; }
            // model-level cooldown (6004) must not be released by the fallback path
            if ($model !== null && $model !== '') {
                $mc = (array)($s['model_cooldowns'] ?? []);
                if (isset($mc[$model]) && (int)($mc[$model]['until'] ?? 0) > time()) { continue; }
            }
            if (!empty($s['until']) && (int)$s['until'] > time() && (($s['cool_kind'] ?? '') === 'hard_credit')) { continue; }
            if ($this->inFlightFull($uid, $realm) || $this->floorBlocked($uid, $model)) { continue; }
            $exp = min((int)($s['until'] ?? 0), (int)($s['breaker_until'] ?? 0), (int)($s['degrade_until'] ?? 0));
            if ($exp < $bestExp) { $bestExp = $exp; $best = $uid; }
        }
        return $best !== null ? $this->markPicked($best) : null;
    }

    private function markPicked(string $uid): string
    {
        $s = $this->st($uid);
        $s['last_used'] = time();
        $s['last_used_ms'] = (int)(microtime(true) * 1000);
        $this->state[$uid] = $s;
        return $uid;
    }

    // ---------- Sticky ----------
    public function stickyResolve(string $key, ?string $model): ?string
    {
        $uid = $this->store->stickyGet($key);
        if ($uid === null) { return null; }
        $acct = $this->store->accountGet($uid);
        if (!$acct || !$this->healthy($uid, $model) || $this->inFlightFull($uid, $this->realmOf($acct)) || $this->floorBlocked($uid, $model)) {
            $this->store->stickyDelete($key);
            return null;
        }
        $this->store->stickySet($key, $uid, Config::stickyTtlSec()); // rolling renewal
        return $uid;
    }

    public function stickyBind(string $key, string $uid): void
    {
        if (!empty(Env::config()['session_sticky']['enabled'])) { $this->store->stickySet($key, $uid, Config::stickyTtlSec()); }
    }
    public function stickyUnbind(string $key): void { $this->store->stickyDelete($key); }

    // ---------- Outcomes ----------
    public function noteSuccess(string $uid, array $usage = []): void
    {
        $s = $this->st($uid);
        $s['soft_streak'] = 0; $s['consecutive_fails'] = 0; $s['session_dead_fails'] = 0;
        $s['fails'] = 0; $s['retry_count'] = 0; unset($s['breaker_until'], $s['degrade_until']);
        $s['success_count'] = (int)($s['success_count'] ?? 0) + 1;
        $s['last_success'] = time();
        // model cost EMA
        if (!empty($usage['credit']) && !empty($usage['total_tokens'])) {
            $model = (string)($usage['model'] ?? '');
            if ($model !== '') {
                $costs = (array)($s['model_costs'] ?? []);
                $c1k = ($usage['credit'] / max(1, $usage['total_tokens'])) * 1000;
                $prev = (float)($costs[$model]['cost_per_1k'] ?? 0);
                $ema = $prev === 0.0 ? $c1k : $prev * 0.7 + $c1k * 0.3;
                $costs[$model] = ['cost_per_1k' => round($ema, 6), 'last_seen' => time()];
                $s['model_costs'] = $costs;
            }
        }
        if (!empty($usage['credit'])) { $s['credits'] = max(0, (int)$s['credits'] - (int)$usage['credit']); }
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function noteError(string $uid): void
    {
        $s = $this->st($uid);
        $s['err_total'] = (int)($s['err_total'] ?? 0) + 1;
        $s['last_err'] = time();
        $s['fails'] = (int)($s['fails'] ?? 0) + 1;
        $cfg = Env::config()['pool'];
        $threshold = (int)($cfg['breaker_threshold'] ?? 3);
        if ($s['fails'] >= $threshold) {
            $base = Config::breakerCooldownSec();
            $max = Config::breakerCooldownMaxSec();
            $dur = min($base * (2 ** (int)($s['retry_count'] ?? 0)), $max);
            $s['breaker_until'] = time() + $dur;
            $s['retry_count'] = (int)($s['retry_count'] ?? 0) + 1;
            $s['fails'] = 0;
        }
        // consecutive-fail degrade
        $s['consecutive_fails'] = (int)($s['consecutive_fails'] ?? 0) + 1;
        if ($s['consecutive_fails'] >= (int)($cfg['degrade_threshold'] ?? 5)) {
            $s['degrade_until'] = time() + min(Config::degradeCooldownSec(), Util::durationToSeconds($cfg['degrade_cooldown_max'] ?? '2h', 7200));
            $s['consecutive_fails'] = 0;
        }
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function noteFailureRotate(string $uid): void { $this->noteError($uid); }

    public function softCooldown(string $uid, string $reason, ?int $resetAt = null): void
    {
        $s = $this->st($uid);
        $base = Config::softRateSec();
        $max = Config::softRateMaxSec();
        $streak = (int)($s['soft_streak'] ?? 0);
        $dur = $resetAt !== null ? min($resetAt - time(), $max) : min($base * (2 ** $streak), $max);
        $s['until'] = time() + max($dur, 1);
        $s['cool_kind'] = 'soft_rate';
        $s['reason'] = $reason;
        $s['soft_streak'] = $streak + 1;
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function cooldownModel(string $uid, string $model, int $until, string $reason): void
    {
        $s = $this->st($uid);
        $mc = (array)($s['model_cooldowns'] ?? []);
        $mc[$model] = ['until' => min($until, time() + Config::softRateMaxSec()), 'reason' => $reason];
        $s['model_cooldowns'] = $mc;
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function hardCooldown4AM(string $uid, string $reason = '余额不足'): void
    {
        $s = $this->st($uid);
        $h = (int)date('G');
        $target = strtotime(($h < 4 ? 'today' : 'tomorrow') . ' 04:00');
        $s['until'] = $target;
        $s['cool_kind'] = 'hard_credit';
        $s['reason'] = $reason;
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function cooldown404(string $uid): void
    {
        $s = $this->st($uid);
        $s['until'] = time() + 60;
        $s['cool_kind'] = 'soft_rate';
        $s['reason'] = 'upstream 404';
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function noteSessionDead(string $uid): void
    {
        $s = $this->st($uid);
        $s['session_dead_fails'] = (int)($s['session_dead_fails'] ?? 0) + 1;
        if ($s['session_dead_fails'] >= 3) { $s['disabled'] = true; $s['disabled_reason'] = '12153 session dead'; }
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function disable(string $uid, string $reason): void
    {
        $s = $this->st($uid);
        $s['disabled'] = true; $s['disabled_reason'] = $reason; unset($s['paused']);
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function revive(string $uid): void
    {
        $s = $this->st($uid);
        unset($s['disabled'], $s['disabled_reason'], $s['until'], $s['breaker_until'], $s['degrade_until'], $s['paused']);
        $s['fails'] = 0; $s['retry_count'] = 0; $s['soft_streak'] = 0; $s['session_dead_fails'] = 0; $s['consecutive_fails'] = 0;
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function setPaused(string $uid, bool $paused): void
    {
        $s = $this->st($uid);
        if ($paused) { $s['paused'] = true; } else { unset($s['paused']); }
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function applyErrorPolicy(string $uid, string $kind, array $up, ?string $model = null): void
    {
        $msg = (string)($up['body'] ?? '');
        switch ($kind) {
            case 'hard_credit':
                $this->hardCooldown4AM($uid);
                break;
            case 'soft_rate':
                $reset = Upstream\Classify::parseResetAt($msg);
                $this->softCooldown($uid, 'rate limited', $reset);
                break;
            case 'model_rate':
                // 6004 model-level limit: cooldown only that model (no account penalty)
                if ($model !== null && $model !== '') {
                    $reset = Upstream\Classify::parseResetAt($msg);
                    $until = $reset !== null ? $reset : time() + Config::softRateSec();
                    $this->cooldownModel($uid, $model, $until, '6004 model usage limit');
                }
                break;
            case 'session_dead':
                $this->noteSessionDead($uid);
                break;
            case 'not_found':
                $this->cooldown404($uid);
                break;
            case 'server':
            case 'client':
                $this->noteError($uid);
                break;
            default:
                // content blocked / bad params / image invalid / model blocked: no account penalty
                break;
        }
    }

    // ---------- Status ----------
    /** Decode exp claim from the accessToken JWT (null = unknown). */
    public static function accessTokenExpired(array $acct): bool
    {
        $tok = (string)($acct['auth']['accessToken'] ?? '');
        if ($tok === '') { return true; }
        $parts = explode('.', $tok);
        if (count($parts) < 2) { return false; } // opaque token: fall back to expiresAt logic
        $payload = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || empty($payload['exp'])) { return false; }
        return (int)$payload['exp'] <= time() + 60;
    }
    public function status(): array
    {
        $accounts = [];
        $total = 0; $healthy = 0; $cooling = 0; $disabled = 0; $paused = 0; $inFlightFull = 0;
        foreach ($this->store->accountAll() as $uid => $acct) {
            $s = $this->st($uid);
            $now = time();
            $coolingNow = (!empty($s['until']) && (int)$s['until'] > $now) || (!empty($s['breaker_until']) && (int)$s['breaker_until'] > $now) || (!empty($s['degrade_until']) && (int)$s['degrade_until'] > $now);
            $ok = $this->healthy($uid);
            $total++;
            if ($ok) { $healthy++; } elseif ($coolingNow) { $cooling++; }
            if (!empty($s['disabled'])) { $disabled++; }
            if (!empty($s['paused'])) { $paused++; }
            if ($this->inFlightFull($uid, $this->realmOf($acct))) { $inFlightFull++; }
            $ent = $this->isEnterprise($acct);
            $row = [
                'uid' => $uid,
                'nickname' => (string)($acct['account']['nickname'] ?? ''),
                'credits' => (int)($s['credits'] ?? 0),
                'credits_total' => (int)($s['credits_total'] ?? 0),
                'cooling' => $coolingNow,
                'cool_kind' => $s['cool_kind'] ?? null,
                'cool_remaining_sec' => $coolingNow ? max(0, min((int)($s['until'] ?? 0), (int)($s['breaker_until'] ?? PHP_INT_MAX)) - $now) : null,
                'reason' => $s['reason'] ?? null,
                'soft_streak' => (int)($s['soft_streak'] ?? 0),
                'realm' => $this->realmOf($acct),
                'enterprise' => $ent,
                'disabled' => (bool)($s['disabled'] ?? false),
                'disabled_reason' => $s['disabled_reason'] ?? null,
                'paused' => (bool)($s['paused'] ?? false),
                'success_count' => (int)($s['success_count'] ?? 0),
                'err_total' => (int)($s['err_total'] ?? 0),
                'last_success' => isset($s['last_success']) ? gmdate('c', (int)$s['last_success']) : null,
                'last_err' => isset($s['last_err']) ? gmdate('c', (int)$s['last_err']) : null,
                'checkin_done' => (bool)($s['checkin_done'] ?? false),
                'in_flight' => (int)($s['in_flight'] ?? 0),
                'consecutive_fails' => (int)($s['consecutive_fails'] ?? 0),
                'breaker_fails' => (int)($s['fails'] ?? 0),
                'breaker_until' => isset($s['breaker_until']) ? gmdate('c', (int)$s['breaker_until']) : null,
                'degrade_until' => isset($s['degrade_until']) ? gmdate('c', (int)$s['degrade_until']) : null,
            ];
            if (!empty($s['credits_earliest_expiry'])) { $row['credits_earliest_expiry'] = gmdate('c', (int)$s['credits_earliest_expiry']); }
            $accounts[] = $row;
        }
        usort($accounts, fn($a, $b) => strcmp($a['uid'], $b['uid']));
        return ['accounts' => $accounts, 'total' => $total, 'healthy' => $healthy, 'cooling' => $cooling, 'disabled' => $disabled, 'paused' => $paused, 'in_flight_full' => $inFlightFull, 'sticky_sessions' => $this->store->stickyCount(), 'model_locks' => $this->modelLocks()];
    }

    /** Aggregate active per-model cooldowns (6004) into a lock view per realm+model. */
    private function modelLocks(): array
    {
        $now = time();
        $locks = [];
        foreach ($this->store->accountAll() as $uid => $acct) {
            $s = $this->st($uid);
            $realm = $this->realmOf($acct);
            $disabled = !empty($s['disabled']);
            foreach ((array)($s['model_cooldowns'] ?? []) as $model => $mc) {
                if ((int)($mc['until'] ?? 0) <= $now) { continue; }
                $k = $realm . ':' . $model;
                if (!isset($locks[$k])) {
                    $locks[$k] = ['realm' => $realm, 'model' => $model, 'total' => 0, 'locked' => 0, 'earliest_unlock' => PHP_INT_MAX, 'fully_unlock_at' => PHP_INT_MAX, 'reason' => (string)($mc['reason'] ?? '6004 model usage limit')];
                }
                $locks[$k]['total']++;
                if (!$disabled) {
                    $locks[$k]['locked']++;
                    $locks[$k]['earliest_unlock'] = min($locks[$k]['earliest_unlock'], (int)$mc['until']);
                    $locks[$k]['fully_unlock_at'] = max($locks[$k]['fully_unlock_at'] === PHP_INT_MAX ? 0 : $locks[$k]['fully_unlock_at'], (int)$mc['until']);
                }
            }
        }
        foreach ($locks as &$l) {
            $l['servable'] = max(0, $l['total'] - $l['locked']);
            $l['earliest_unlock'] = $l['earliest_unlock'] === PHP_INT_MAX ? null : gmdate('c', $l['earliest_unlock']);
            $l['fully_unlock_at'] = $l['fully_unlock_at'] === PHP_INT_MAX ? null : gmdate('c', $l['fully_unlock_at']);
            $l['state'] = $l['servable'] === 0 ? ($l['total'] === $l['locked'] ? 'all_locked' : 'no_servable') : 'partial';
            if ($l['fully_unlock_at'] === 0) { $l['fully_unlock_at'] = $l['earliest_unlock']; }
        }
        unset($l);
        usort($locks, fn($a, $b) => [$a['realm'], $a['model']] <=> [$b['realm'], $b['model']]);
        return array_values($locks);
    }

    /** Accounts that are healthy AND have a free in-flight slot (per realm). */
    public function servableCount(string $realm): int
    {
        $n = 0;
        foreach ($this->store->accountAll() as $uid => $acct) {
            if ($this->realmOf($acct) !== $realm) { continue; }
            if ($this->healthy($uid) && !$this->inFlightFull($uid, $realm)) { $n++; }
        }
        return $n;
    }

    public function healthyCount(): int
    {
        $n = 0;
        foreach ($this->store->accountAll() as $uid => $_) { if ($this->healthy($uid)) { $n++; } }
        return $n;
    }

    public function allUIDs(): array { return array_keys($this->store->accountAll()); }

    public function stateFor(string $uid): array { return $this->st($uid); }

    public function setStateFor(string $uid, array $s): void { $this->state[$uid] = $s; $this->persist(); }

    public function setCredits(string $uid, int $credits, int $total, array $packages = []): void
    {
        $s = $this->st($uid);
        $s['credits'] = $credits; $s['credits_total'] = $total;
        $earliest = 0; $expiring = 0; $window = time() + 7 * 86400;
        foreach ($packages as $p) {
            $end = (int)($p['end_time'] ?? 0);
            if ($end > time() && ($earliest === 0 || $end < $earliest)) { $earliest = $end; }
            if ($end > time() && $end <= $window && (int)($p['remain'] ?? 0) > 0) { $expiring += (int)$p['remain']; }
        }
        if ($earliest > 0) { $s['credits_earliest_expiry'] = $earliest; $s['credits_expiring'] = $expiring; }
        // balance recovery unfreezes hard-credit cooldown
        if ($credits > 0 && (($s['cool_kind'] ?? '') === 'hard_credit') && !empty($s['until']) && (int)$s['until'] > time()) {
            unset($s['until'], $s['cool_kind'], $s['reason']);
        }
        $this->state[$uid] = $s;
        $this->persist();
    }

    public function persist(): void { $this->store->kvSet('pool_state', $this->state); }

    /** Token refresh flow for keepalive. */
    public function refreshTokenIfNeeded(string $uid, bool $force = false): array
    {
        $acct = $this->account($uid);
        if (!$acct) { return ['ok' => false, 'error' => 'no such account']; }
        $exp = (int)($acct['auth']['expiresAt'] ?? 0);
        if (!$force && $exp > time() + 600) { return ['ok' => true, 'skipped' => true]; }
        $r = Upstream\Client::refreshToken($acct);
        if ($r['ok']) {
            $acct['auth']['accessToken'] = $r['access'];
            if ($r['refresh'] !== '') { $acct['auth']['refreshToken'] = $r['refresh']; }
            $acct['auth']['expiresAt'] = time() + max($r['expires_in'], 300);
            $this->store->accountPut($uid, $this->sealAccount($acct));
            $s = $this->st($uid);
            $s['session_dead_fails'] = 0;
            $this->state[$uid] = $s;
            $this->persist();
            return ['ok' => true];
        }
        $kind = Upstream\Classify::classify($r['raw']['status'] ?? 0, $r['raw']['body'] ?? '');
        if ($kind === Upstream\Classify::SESSION_DEAD) { $this->noteSessionDead($uid); }
        return ['ok' => false, 'kind' => $kind];
    }

    private function sealAccount(array $acct): array
    {
        $a = $acct;
        $a['auth']['accessToken'] = Crypto::encrypt((string)$acct['auth']['accessToken']);
        $a['auth']['refreshToken'] = Crypto::encrypt((string)($acct['auth']['refreshToken'] ?? ''));
        return $a;
    }
}
