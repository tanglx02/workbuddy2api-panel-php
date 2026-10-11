<?php
declare(strict_types=1);
namespace WB2A\Api;

use WB2A\Core\{Env, Util, RingLog, Usage};
use WB2A\Pool;
use WB2A\Upstream\{Classify, Headers, Payload, Sse, Client};

/**
 * OpenAI-compatible chat completions endpoint with pool rotation,
 * sticky sessions, SSE whitelist rebuild and error policy.
 */
final class ChatApi
{
    private const MAX_ROTATE = 3;

    const DEGRADED_PROMPT = 'You are a helpful, harmless AI assistant. Follow the user\'s instructions and respond in the user\'s language.';

    /** Degrade gate state (in-memory semantics via file marker; reset at next midnight CST). */
    public static function degradeActive(): bool
    {
        $f = WB2A_ROOT . '/data/degrade_until';
        if (!is_readable($f)) { return false; }
        $until = (int)file_get_contents($f);
        if ($until <= time()) { @unlink($f); return false; }
        return true;
    }

    public static function setDegradeUntil(int $ts): void
    {
        $f = WB2A_ROOT . '/data/degrade_until';
        @file_put_contents($f, (string)$ts, LOCK_EX);
    }

    public static function nextMidnightCST(): int
    {
        $mid = new \DateTime('tomorrow midnight', new \DateTimeZone('Asia/Shanghai'));
        return $mid->getTimestamp();
    }

    public static function chat(string $rest = ''): void
    {
        if (!Util::requireAuth()) {
            Util::jsonOut(['error' => ['message' => 'missing or invalid API key', 'type' => 'api_error', 'code' => 'invalid_api_key']], 401);
            return;
        }
        $reqId = Util::randomHex(16);
        header('X-Request-Id: ' . $reqId);
        $startMs = (int)(microtime(true) * 1000);
        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (!is_array($body) || empty($body['model']) || empty($body['messages'])) {
            Util::jsonOut(['error' => ['message' => 'invalid request body', 'type' => 'invalid_request_error', 'code' => 'invalid_request']], 400);
            return;
        }
        $cfg = Env::config();
        // model resolve
        $modelFull = (string)$body['model'];
        $colon = strpos($modelFull, ':');
        $realm = 'cn'; $bare = $modelFull;
        if ($colon !== false) {
            $head = substr($modelFull, 0, $colon);
            if ($head === 'cn' || $head === 'global') { $realm = $head; $bare = substr($modelFull, $colon + 1); }
        }
        $stream = filter_var($body['stream'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sessKey = Payload::sessionKey($body);
        $allow = Payload::toolNameAllowlist($body);

        // prompt rewrite (custom default)
        $promptMode = (string)($cfg['prompt']['mode'] ?? 'custom');
        $promptText = self::promptText();
        $body = self::applyPrompt($body, $promptMode, $promptText, $realm);

        $pool = new Pool();
        $tried = [];
        $stickyUid = null;
        if ($sessKey !== null && !empty($cfg['session_sticky']['enabled'])) {
            $stickyUid = $pool->stickyResolve($sessKey, $bare);
        }

        $lastErr = ['status' => 503, 'message' => 'no_healthy_account', 'code' => 'no_healthy_account'];
        $okUsage = null; $okAcct = null; $ttfb = 0; $frames = [];
        $attempts = 0;
        $degraded = false;
        $retryUid = null;
        $refreshRetried = [];
        $origBody = $body;
        // degrade gate: once degraded, passthrough requests go straight to the neutral prompt until next midnight CST
        if ($promptMode === 'passthrough' && self::degradeActive()) {
            $promptMode = 'custom';
            $promptText = self::DEGRADED_PROMPT;
        }

        for ($i = 0; $i <= self::MAX_ROTATE; $i++) {
            $uid = null;
            if ($retryUid !== null) { $uid = $retryUid; $retryUid = null; }
            elseif ($stickyUid !== null && !in_array($stickyUid, $tried, true)) { $uid = $stickyUid; }
            else { $uid = $pool->pick($tried, $bare, $realm); }
            if ($uid === null) { break; }
            $tried[] = $uid;
            $attempts++;
            $acct = $pool->account($uid);
            if ($acct === null) { continue; }
            // refresh if near expiry (JWT exp is authoritative; expiresAt is the refresh-token TTL)
            $exp = (int)($acct['auth']['expiresAt'] ?? 0);
            if (($exp !== 0 && $exp < time() + 600) || Pool::accessTokenExpired($acct)) {
                $rf = $pool->refreshTokenIfNeeded($uid, true);
                if (!$rf['ok']) {
                    if (($rf['kind'] ?? '') === Classify::SESSION_DEAD) { $pool->disable($uid, 'refresh failed: session dead'); }
                    continue;
                }
                $acct = $pool->account($uid);
            }
            $out = Payload::prepare($body, $realm, (bool)($cfg['features']['sanitize_blacklist_fingerprints'] ?? true), $promptMode, $promptText, '');
            $out['model'] = $bare;
            $poolState = $pool->stateFor($uid);
            // in-flight lease
            $st = $pool->stateFor($uid); $st['in_flight'] = (int)($st['in_flight'] ?? 0) + 1;
            $pool->setStateFor($uid, $st);

            $url = Client::base($realm) . '/v2/chat/completions';
            $headers = Headers::chat($realm, $uid, (string)$acct['auth']['accessToken'], (string)($acct['account']['enterpriseId'] ?? ''));
            $headers['X-Request-ID'] = $reqId;
            $headers['X-Conversation-Request-ID'] = $reqId;
            $headers['X-Conversation-Message-ID'] = $reqId;
            $headers['X-Root-Request-ID'] = $reqId;
            if (!empty($cfg['logging']['request_client_info'])) {
                $ip = Util::clientIp();
                if ($ip !== '') { $headers['X-Forwarded-For'] = $ip; }
            }
            $payload = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headerTimeout = (int)($cfg['upstream']['header_timeout_seconds'] ?? 120);

            $frames = []; $gotAny = false; $errFrame = null; $t0 = microtime(true); $ttfb = 0;
            $idleTimeout = (int)($cfg['upstream']['idle_timeout_seconds'] ?? 300);
            $resp = \WB2A\Core\Http::request('POST', $url, $headers, $payload, $headerTimeout, function (string $line) use (&$frames, &$gotAny, &$errFrame, &$ttfb, $t0) {
                if ($ttfb === 0) { $ttfb = (int)((microtime(true) - $t0) * 1000); }
                if (strpos($line, 'data:') !== 0) { return; }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') { return; }
                $f = json_decode($data, true);
                if (!is_array($f)) { return; }
                $nf = Sse::normalizeFrame($f);
                if ($nf !== null) { $frames[] = $nf; $gotAny = true; }
                if (isset($f['error'])) { $errFrame = $f['error']; }
            }, ['idle_timeout' => $idleTimeout]);
            $durMs = (int)((microtime(true) - $t0) * 1000);

            $st = $pool->stateFor($uid); $st['in_flight'] = max(0, (int)($st['in_flight'] ?? 1) - 1);
            $pool->setStateFor($uid, $st);

            // transport failure?
            if (!$resp['ok']) {
                $timedOut = in_array((int)($resp['errno'] ?? 0), [CURLE_OPERATION_TIMEDOUT], true);
                if ($timedOut) {
                    // upstream timeout: no penalty, no rotation
                    $lastErr = ['status' => 503, 'message' => 'upstream timeout', 'code' => 'upstream_timeout'];
                    break;
                }
                $pool->noteFailureRotate($uid);
                $lastErr = ['status' => 502, 'message' => 'upstream connection failed', 'code' => 'upstream_error'];
                continue; // rotate
            }

            $status = (int)$resp['status'];
            if ($status >= 400 || $errFrame !== null) {
                $errBody = $errFrame !== null ? json_encode($errFrame, JSON_UNESCAPED_UNICODE) : (string)$resp['body'];
                $kind = Classify::classify($status, $errBody);
                $pool->applyErrorPolicy($uid, $kind, ['body' => $errBody], $bare);
                RingLog::chat("chat $reqId uid=$uid status=$status kind=$kind");
                if ($kind === Classify::CONTENT_BLOCKED) {
                    // passthrough mode: same-request retry once with Degraded neutral prompt
                    if ($promptMode === 'passthrough' && !$degraded) {
                        $degraded = true;
                        self::setDegradeUntil(self::nextMidnightCST());
                        $body = self::applyPrompt($origBody, 'custom', self::DEGRADED_PROMPT, $realm);
                        $promptText = self::DEGRADED_PROMPT;
                        RingLog::chat("chat $reqId: content blocked in passthrough, retrying with degraded prompt");
                        $retryUid = $uid;
                        $i--; // do not count as a rotation attempt
                        continue;
                    }
                    $lastErr = ['status' => 400, 'message' => self::this_extract($errBody), 'code' => 'content_blocked'];
                    break; // do not rotate, pass through
                }
                if ($kind === Classify::MODEL_RATE) {
                    // 6004: this account is out for THIS model only; other accounts may serve it
                    if ($sessKey !== null && $stickyUid === $uid) { $pool->stickyUnbind($sessKey); $stickyUid = null; }
                    $lastErr = ['status' => 429, 'message' => self::this_extract($errBody), 'code' => 'model_rate_limited'];
                    continue;
                }
                if ($status === 401 && empty($refreshRetried[$uid])) {
                    // access token rejected (expired/revoked): force refresh and retry same account once
                    $refreshRetried[$uid] = true;
                    $rf = $pool->refreshTokenIfNeeded($uid, true);
                    if (!empty($rf['ok'])) {
                        $retryUid = $uid;
                        $i--;
                        continue;
                    }
                }
                if (in_array($kind, [Classify::BAD_PARAMS, Classify::PROMPT_TOO_LONG, Classify::IMAGE_INVALID], true)) {
                    $lastErr = ['status' => 400, 'message' => self::this_extract($errBody), 'code' => $kind];
                    break;
                }
                if ($sessKey !== null && $stickyUid === $uid) { $pool->stickyUnbind($sessKey); $stickyUid = null; }
                $lastErr = ['status' => self::mapStatus($kind), 'message' => self::this_extract($errBody), 'code' => $kind];
                continue; // rotate to next account
            }

            if (!$gotAny) {
                $lastErr = ['status' => 502, 'message' => 'empty upstream stream', 'code' => 'upstream_parse'];
                continue;
            }

            // success
            $usage = self::lastUsage($frames);
            $pool->noteSuccess($uid, ['credit' => $usage['credit'] ?? 0, 'total_tokens' => $usage['total_tokens'] ?? 0, 'model' => $bare]);
            if ($sessKey !== null) { $pool->stickyBind($sessKey, $uid); }
            $okUsage = $usage; $okAcct = $uid;
            $tps = $usage['completion_tokens'] ?? 0;
            $tps = $durMs > 0 ? round($tps / ($durMs / 1000), 1) : 0;
            RingLog::chat("| chat | $bare | uid=" . substr($uid, 0, 8) . " | TTFB={$ttfb}ms | tok=" . ($usage['completion_tokens'] ?? 0) . " | {$tps}tok/s | total={$durMs}ms");

            // record usage
            Usage::add($realm, $uid, $bare, [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0, 'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0, 'credit' => $usage['credit'] ?? 0,
                'latency_ms' => $durMs, 'tps' => $tps,
            ], true);
            self::reqLog($reqId, $uid, $bare, 200, true, 'completed', $durMs, $ttfb, $attempts, $usage, $startMs);
            self::emit($stream, $frames, $allow, $bare);
            return;
        }

        // exhausted
        $code = (string)($lastErr['code'] ?? 'no_healthy_account');
        $msg = (string)($lastErr['message'] ?? 'no healthy account');
        if ($code === 'no_healthy_account' || $code === '') { $msg = 'no healthy account available'; }
        self::reqLog($reqId, '', $bare, $lastErr['status'], false, $code, (int)((microtime(true) * 1000) - $startMs), 0, $attempts, [], $startMs);
        RingLog::chat("chat $reqId failed: $code");
        if ($stream) {
            // stream-mode clients get an SSE error frame + [DONE], never a bare JSON 502
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache');
            while (ob_get_level() > 0) { ob_end_flush(); }
            echo 'data: ' . json_encode(['error' => ['message' => $msg, 'type' => 'upstream_error', 'code' => $code]], JSON_UNESCAPED_SLASHES) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
            return;
        }
        Util::jsonOut(['error' => ['message' => $msg, 'type' => 'upstream_error', 'code' => $code]], (int)$lastErr['status']);
    }

    private static function this_extract(string $body): string
    {
        $j = json_decode($body, true);
        if (is_array($j)) {
            if (isset($j['error']['message'])) { return (string)$j['error']['message']; }
            if (isset($j['msg'])) { return (string)$j['msg']; }
        }
        return mb_substr($body, 0, 300);
    }

    private static function mapStatus(string $kind): int
    {
        return match ($kind) {
            Classify::SOFT_RATE => 429,
            Classify::NOT_FOUND, Classify::MODEL_BLOCKED => 400,
            Classify::WAF_BLOCK => 403,
            default => 503,
        };
    }

    private static function lastUsage(array $frames): array
    {
        foreach (array_reverse($frames) as $f) {
            if (isset($f['usage']) && is_array($f['usage']) && isset($f['usage']['total_tokens'])) {
                $u = $f['usage'];
                if (isset($u['credit'])) { $u['credit'] = (float)$u['credit']; }
                return $u;
            }
        }
        return [];
    }

    /** Emit SSE stream or aggregated non-stream response, with DSML repair. */
    private static function emit(bool $stream, array $frames, array $allow, string $model): void
    {
        if (!$stream) {
            $agg = Sse::aggregate($frames);
            if ($agg === null) {
                Util::jsonOut(['error' => ['message' => 'empty upstream stream', 'type' => 'upstream_error', 'code' => 'upstream_parse']], 502);
                return;
            }
            if (isset($agg['__error'])) {
                Util::jsonOut(['error' => $agg['__error']], 502);
                return;
            }
            // DSML repair on aggregated content (Go hard precondition: finish_reason must be stop)
            if (($agg['choices'][0]['finish_reason'] ?? '') === 'stop'
                && isset($agg['choices'][0]['message']['content']) && empty($agg['choices'][0]['message']['tool_calls'])) {
                $calls = Sse::repairToolMarkup((string)$agg['choices'][0]['message']['content'], $allow);
                if ($calls !== []) {
                    $agg['choices'][0]['message']['tool_calls'] = $calls;
                    $agg['choices'][0]['message']['content'] = '';
                    $agg['choices'][0]['finish_reason'] = 'tool_calls';
                }
            }
            Util::jsonOut($agg);
            return;
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        while (ob_get_level() > 0) { ob_end_flush(); }
        $done = false;
        $idxBase = 0;
        foreach ($frames as $f) {
            if (isset($f['error'])) {
                echo 'data: ' . json_encode(['error' => $f['error']], JSON_UNESCAPED_SLASHES) . "\n\n";
                continue;
            }
            // DSML repair inside content deltas
            if (isset($f['choices'][0]['delta']['content']) && is_string($f['choices'][0]['delta']['content'])) {
                $calls = Sse::repairToolMarkup($f['choices'][0]['delta']['content'], $allow);
                if ($calls !== []) {
                    foreach ($calls as $c) { $c['index'] = $idxBase++; $f['choices'][0]['delta']['tool_calls'][] = $c; }
                    $f['choices'][0]['delta']['content'] = '';
                    $f['choices'][0]['finish_reason'] = 'tool_calls';
                }
            }
            if (($f['choices'][0]['finish_reason'] ?? null) === 'stop') { $done = true; }
            echo 'data: ' . json_encode($f, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            if (function_exists('flush')) { flush(); }
            if (connection_aborted()) { return; }
        }
        if (!$done) {
            echo 'data: ' . json_encode(['id' => 'chatcmpl-wb2api', 'object' => 'chat.completion.chunk', 'created' => time(), 'model' => $model, 'choices' => [['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => 'stop']], 'usage' => null]) . "\n\n";
        }
        echo "data: [DONE]\n\n";
        flush();
    }

    public static function promptText(): string
    {
        $cfg = Env::config();
        $file = (string)($cfg['prompt']['file'] ?? '');
        if ($file !== '') {
            if (is_readable($file)) { return (string)file_get_contents($file); }
            RingLog::sys("WARN: prompt.file configured but unreadable, falling back to builtin default: $file");
        }
        $builtin = WB2A_ROOT . '/app/defaultprompt.md';
        if (is_readable($builtin)) { return (string)file_get_contents($builtin); }
        return 'You are a helpful assistant.';
    }

    /** Apply prompt mode to messages. */
    public static function applyPrompt(array $body, string $mode, string $promptText, string $realm): array
    {
        $msgs = $body['messages'] ?? [];
        $isSys = fn($m) => in_array($m['role'] ?? '', ['system', 'developer'], true);
        if ($mode === 'custom') {
            $kept = array_values(array_filter($msgs, fn($m) => !$isSys($m)));
            array_unshift($kept, ['role' => 'system', 'content' => $promptText]);
            $body['messages'] = $kept;
        } elseif ($mode === 'append') {
            $firstNonSys = 0;
            while ($firstNonSys < count($msgs) && $isSys($msgs[$firstNonSys])) { $firstNonSys++; }
            array_splice($msgs, $firstNonSys, 0, [['role' => 'system', 'content' => $promptText]]);
            $body['messages'] = $msgs;
        } elseif ($mode === 'passthrough') {
            // pure passthrough: client system is forwarded verbatim (Go semantics, no synthetic injection)
        }
        return $body;
    }

    private static function reqLog(string $reqId, string $uid, string $model, int $status, bool $ok, string $outcome, int $durMs, int $ttfb, int $attempts, array $usage, int $startTs): void
    {
        $cfg = Env::config();
        if (empty($cfg['logging']['request_archive_enabled'])) { return; }
        $ev = ['time' => date('c'), 'ts' => $startTs, 'request_id' => $reqId, 'path' => '/v1/chat/completions', 'account' => $uid, 'model' => $model, 'status' => $status, 'ok' => $ok, 'outcome' => $outcome, 'duration_ms' => $durMs, 'attempts' => $attempts,
            'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0), 'completion_tokens' => (int)($usage['completion_tokens'] ?? 0), 'total_tokens' => (int)($usage['total_tokens'] ?? 0), 'credit' => (float)($usage['credit'] ?? 0), 'credit_known' => isset($usage['credit'])];
        if (!empty($cfg['logging']['request_client_info'])) { $ev['client_ip'] = Util::clientIp(); $ev['user_agent'] = Util::userAgent(); }
        try { Env::store()->reqlogInsert($ev); } catch (\Throwable $e) { /* never break chat on log failure */ }
    }
}
