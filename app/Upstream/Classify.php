<?php
declare(strict_types=1);
namespace WB2A\Upstream;

/** Error classification (mirrors Go internal/upstream Classify priority). */
final class Classify
{
    const HARD_CREDIT = 'hard_credit';
    const SOFT_RATE = 'soft_rate';
    const MODEL_RATE = 'model_rate'; // 429 + code 6004: model-level usage limit
    const SESSION_DEAD = 'session_dead';
    const ACCOUNT_FAULT = 'account_fault';
    const MODEL_BLOCKED = 'model_blocked';
    const CONTENT_BLOCKED = 'content_blocked';
    const PROMPT_TOO_LONG = 'prompt_too_long';
    const IMAGE_INVALID = 'image_invalid';
    const BAD_PARAMS = 'bad_params';
    const NOT_FOUND = 'not_found';
    const SERVER = 'server';
    const WAF_BLOCK = 'waf_block';
    const CLIENT = 'client';
    const NONE = 'none';

    private static array $hardMarkers = ['insufficient credit', 'no credit', 'credit exhausted', 'credits exhausted', 'out of credit', 'quota exceeded', 'quota exhaust', 'payment required', 'credit not enough', 'not enough credit', '积分不足', '额度不足', '余额不足', '积分用完', '额度用尽', '没有积分'];
    private static array $softMarkers = ['rate limit', 'rate-limiting', 'rate-limited', 'too many requests', 'too many', 'usage limit', '请求过于频繁', '限流'];
    private static array $contentMarkers = ['blocked by security policy', 'unapproved channel', 'illegal api invocation'];

    public static function codeOf($body): string
    {
        if (is_string($body) && preg_match('/"code"\s*:\s*"?(\d+)"?/', $body, $m)) { return $m[1]; }
        if (is_array($body) && isset($body['code'])) { return (string)$body['code']; }
        return '';
    }

    public static function classify(int $status, $body): string
    {
        $b = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (string)$body;
        $code = self::codeOf($b);
        $msg = '';
        if (is_array($body)) { $msg = (string)($body['msg'] ?? ($body['message'] ?? '')); } else { $msg = $b; }
        $hay = $msg !== '' ? $msg : $b;

        if ($code === '11102' || stripos($hay, 'service info not found') !== false) { return self::MODEL_BLOCKED; }
        if ($status === 402) { return self::HARD_CREDIT; }
        if (strpos($hay, 'Offline user session not found') !== false || strpos($hay, '12153') !== false) { return self::SESSION_DEAD; }
        if (preg_match('/request illegal|trial not activated|trial version is not yet activated/i', $hay)) { return self::ACCOUNT_FAULT; }
        if ($status === 429 && $code === '14018') { return self::HARD_CREDIT; }
        if ($status === 429 && $code === '6004') { return self::MODEL_RATE; }
        foreach (self::$hardMarkers as $m) { if (stripos($hay, $m) !== false) { return self::HARD_CREDIT; } }
        foreach (self::$softMarkers as $m) { if (stripos($hay, $m) !== false) { return self::SOFT_RATE; } }
        if (in_array($status, [400, 404, 413], true) && ($code === '11115' || stripos($hay, 'prompt is too long') !== false)) { return self::PROMPT_TOO_LONG; }
        if ($status === 404) { return self::NOT_FOUND; }
        if ($code === '6004') { return self::MODEL_RATE; } // limit text without 429 status
        if ($status >= 500) { return self::SERVER; }
        if ($status === 403 && strpos($b, '"code"') === false && strpos($b, '"msg"') === false) { return self::WAF_BLOCK; }
        if ($code === '11135' || stripos($hay, 'invalid image_url content') !== false || stripos($hay, 'invalid_image_data') !== false || stripos($hay, 'replace the image') !== false) { return self::IMAGE_INVALID; }
        foreach (self::$contentMarkers as $m) { if (stripos($hay, $m) !== false) { return self::CONTENT_BLOCKED; } }
        if (stripos($hay, 'Unmarshal chat params failed') !== false || $code === '11101') { return self::BAD_PARAMS; }
        if ($status >= 400) { return self::CLIENT; }
        return self::NONE;
    }

    /** Parse "将在 YYYY-MM-DD HH:MM:SS( UTC+8) 重置" style reset time (CST). */
    public static function parseResetAt(string $msg): ?int
    {
        if (preg_match('/将在\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $msg, $m)
            || preg_match('/reset at\s*(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/i', $m1 = $msg, $m)) {
            $ts = strtotime(str_replace(' ', 'T', $m[1]) . '+08:00');
            return $ts ?: null;
        }
        return null;
    }
}
