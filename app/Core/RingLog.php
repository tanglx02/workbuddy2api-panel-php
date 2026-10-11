<?php
declare(strict_types=1);
namespace WB2A\Core;

/** In-memory ring log with channels (task / chat / sys), persisted tail in store kv. */
final class RingLog
{
    public static function push(string $ch, string $text): void
    {
        $store = Env::store();
        $entries = $store->kvGet('ringlog', []);
        if (!is_array($entries)) { $entries = []; }
        $entries[] = ['ts' => time(), 'ch' => $ch, 'text' => mb_substr(self::clean($text), 0, 500)];
        if (count($entries) > 500) { $entries = array_slice($entries, -500); }
        $store->kvSet('ringlog', $entries);
    }

    public static function sys(string $t): void { self::push('sys', $t); }
    public static function task(string $t): void { self::push('task', $t); }
    public static function chat(string $t): void { self::push('chat', $t); }

    public static function tail(): array
    {
        $e = Env::store()->kvGet('ringlog', []);
        return is_array($e) ? $e : [];
    }

    private static function clean(string $s): string
    {
        $s = str_replace(['access_token=', 'refresh_token=', 'Authorization: Bearer '], ['access_token=***', 'refresh_token=***', 'Authorization: Bearer ***'], $s);
        $s = preg_replace('/(?:sk-|Bearer\s+)[A-Za-z0-9_\-\.]{8,}/', '***', $s) ?? $s;
        return $s;
    }
}
