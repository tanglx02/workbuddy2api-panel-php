<?php
declare(strict_types=1);
namespace WB2A\Core;

final class Util
{
    public static function jsonOut($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function inputJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '' || strlen($raw) > 4 * 1024 * 1024) { return []; }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function atomicWrite(string $file, string $content): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) { return false; }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $file)) {
            // Some hosts disallow rename over existing files: in-place fallback.
            $ok = file_put_contents($file, $content, LOCK_EX) !== false;
            @unlink($tmp);
            return $ok;
        }
        return true;
    }

    public static function validUID(string $uid): bool
    {
        return $uid !== '' && strlen($uid) <= 64 && (bool)preg_match('/^[A-Za-z0-9_-]+$/', $uid);
    }

    public static function now(): int { return time(); }

    /** sha256-based constant time bearer comparison (mirrors Go httpauth). */
    public static function verifyBearer(?string $header, string $key): bool
    {
        if ($key === '') { return true; }
        $given = '';
        if (is_string($header) && preg_match('/^\s*Bearer\s+(.+)$/i', $header, $m)) {
            $given = trim($m[1]);
        }
        return hash_equals(hash('sha256', $key), hash('sha256', $given));
    }

    public static function authKey(): string
    {
        return (string)(Env::config()['api_key'] ?? '');
    }

    public static function requireAuth(): bool
    {
        return self::verifyBearer($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null), self::authKey());
    }

    public static function clientIp(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
            if (!empty($_SERVER[$h])) {
                $seg = trim(explode(',', $_SERVER[$h])[0]);
                if ($seg !== '') { return $seg; }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    public static function userAgent(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    }

    public static function durationToSeconds($v, int $def): int
    {
        if (is_int($v) || is_numeric($v)) { return (int)$v; }
        if (is_string($v) && preg_match('/^(\d+)\s*(h|m|s)?$/', trim($v), $m)) {
            $n = (int)$m[1];
            switch ($m[2] ?? 's') {
                case 'h': return $n * 3600;
                case 'm': return $n * 60;
                default: return $n;
            }
        }
        return $def;
    }
}
