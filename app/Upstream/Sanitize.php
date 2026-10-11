<?php
declare(strict_types=1);
namespace WB2A\Upstream;

/** Outbound fingerprint sanitization (mirrors Go internal/upstream/sanitize.go). */
final class Sanitize
{
    private const PRECHECK = ['x-anthropic-billing-header', 'cc_entrypoint=', 'You are Claude Code', 'Main branch (', 'You are a coding agent running in the Codex CLI', 'github.com/anthropics/', '11128'];

    public static function messages(array $messages): array
    {
        $hit = false;
        foreach ($messages as $m) {
            $s = is_string($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE);
            foreach (self::PRECHECK as $p) { if (stripos((string)$s, $p) !== false) { $hit = true; break 2; } }
        }
        if (!$hit) { return $messages; }
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? '') === 'tool') { continue; }
            // array_key_exists: sanitize tool-call turns even when content is null (blind-spot fix)
            if (array_key_exists('content', $m)) { $messages[$i]['content'] = self::text($m['content']); }
            if (isset($m['reasoning_content']) && is_string($m['reasoning_content'])) {
                $messages[$i]['reasoning_content'] = self::text($m['reasoning_content']);
            }
            if (!empty($m['tool_calls'])) {
                foreach ($m['tool_calls'] as $j => $tc) {
                    if (isset($tc['function']['arguments'])) {
                        $messages[$i]['tool_calls'][$j]['function']['arguments'] = self::text((string)$tc['function']['arguments']);
                    }
                }
            }
        }
        return $messages;
    }

    private static function text($content)
    {
        if (is_string($content)) { return self::str($content); }
        if (is_array($content)) {
            foreach ($content as $i => $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $content[$i]['text'] = self::str($part['text']);
                }
            }
        }
        return $content;
    }

    public static function str(string $s): string
    {
        $s = preg_replace('/x-anthropic-billing-header:[^;\n]*;?\s*/i', '', $s) ?? $s;
        $s = str_ireplace('x-anthropic-billing-header', 'x-anthropic-billing-hdr', $s);
        do {
            $prev = $s;
            $s = preg_replace('/cc_[a-z0-9_]+=[^;\n]*;?\s*/i', '', $s) ?? $s;
        } while ($s !== $prev);
        $s = str_replace("You are Claude Code, Anthropic's official CLI for Claude", "You are Claude Code, Anthropic's official CLI tool for Claude", $s);
        $s = str_replace('Main branch (you will usually use this for PRs)', 'Default branch (you will usually use this for PRs)', $s);
        $s = str_replace('You are a coding agent running in the Codex CLI', 'You are a coding tool running in the Codex CLI', $s);
        $s = str_replace('11128', '11-128', $s);
        return $s;
    }
}
