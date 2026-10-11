<?php
declare(strict_types=1);
namespace WB2A\Upstream;

/**
 * Outbound payload rewrite pipeline (mirrors Go internal/upstream/payload.go).
 * Single pass, order fixed.
 */
final class Payload
{
    public static function prepare(array $body, string $realm, bool $sanitize, string $promptMode, string $promptText, string $promptDegraded): array
    {
        // 1. force stream
        $body['stream'] = true;
        // 2. max_completion_tokens -> max_tokens
        if (isset($body['max_completion_tokens']) && is_numeric($body['max_completion_tokens'])) {
            if (!isset($body['max_tokens']) || !is_numeric($body['max_tokens'])) {
                $body['max_tokens'] = $body['max_completion_tokens'];
            }
            unset($body['max_completion_tokens']);
        }
        // 3. GPT-style clamp
        if (isset($body['max_tokens']) && is_numeric($body['max_tokens']) && (int)$body['max_tokens'] < 16) {
            $body['max_tokens'] = 16;
        }
        // 4. stream_options
        if (!isset($body['stream_options'])) { $body['stream_options'] = ['include_usage' => true]; }
        // 5. tool_choice normalize
        if (array_key_exists('tool_choice', $body)) {
            $tc = $body['tool_choice'];
            if ($tc === 'none') { unset($body['tool_choice'], $body['tools'], $body['functions']); }
            elseif (is_array($tc) && isset($tc['type']) && in_array($tc['type'], ['auto', 'required'], true)) { $body['tool_choice'] = $tc['type']; }
            elseif (is_array($tc) && ($tc['type'] ?? '') === 'function') { $body['tool_choice'] = (string)($tc['function']['name'] ?? 'auto'); }
        }
        // 6. tool schema pattern escape
        if (!empty($body['tools']) && is_array($body['tools'])) {
            array_walk_recursive($body['tools'], function (&$v, $k) {
                if ($k === 'pattern' || $k === 'patternProperties') {
                    if (is_string($v)) { $v = str_replace('\\_', '_', $v); }
                }
            });
        }
        // 7. roles normalize + image_url compat (per message)
        if (!empty($body['messages']) && is_array($body['messages'])) {
            foreach ($body['messages'] as $i => $m) {
                if (($m['role'] ?? '') === 'developer') { $body['messages'][$i]['role'] = 'system'; }
                $body['messages'][$i] = self::normalizeImages($body['messages'][$i]);
            }
            // 12-14 thinking / effort / reasoning_content
            $body['messages'] = Thinking::apply($body, $body['messages']);
            // 15. sanitize fingerprints
            if ($sanitize) { $body['messages'] = Sanitize::messages($body['messages']); }
        }
        // prompt mode rewrite happens before this pipeline (handler applies prompt rewrite)
        return $body;
    }

    private static function normalizeImages(array $m): array
    {
        if (empty($m['content']) || !is_array($m['content'])) { return $m; }
        foreach ($m['content'] as $i => $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'image_url' && is_string($part['image_url'] ?? null)) {
                $m['content'][$i]['image_url'] = ['url' => $part['image_url']];
            }
        }
        return $m;
    }

    public static function toolNameAllowlist(array $body): array
    {
        $names = [];
        $collect = function ($t) use (&$names) {
            if (!is_array($t)) { return; }
            if (isset($t['function']['name'])) { $names[] = (string)$t['function']['name']; }
            if (isset($t['name'])) { $names[] = (string)$t['name']; }
        };
        foreach (($body['tools'] ?? []) as $t) { $collect($t); }
        foreach (($body['functions'] ?? []) as $t) { $collect($t); }
        foreach (($body['messages'] ?? []) as $m) {
            foreach (($m['tool_calls'] ?? []) as $tc) { $collect($tc); }
            if (isset($m['function_call']['name'])) { $names[] = (string)$m['function_call']['name']; }
        }
        return array_values(array_unique($names));
    }

    public static function sessionKey(array $body): ?string
    {
        $cands = [
            $body['metadata']['conversation_id'] ?? null,
            $body['metadata']['conversationId'] ?? null,
            $body['metadata']['user_id'] ?? null,
            $body['conversation_id'] ?? null,
            $body['conversationId'] ?? null,
            $body['prompt_cache_key'] ?? null,
        ];
        foreach ($cands as $c) {
            if (is_string($c) && $c !== '') { return $c; }
        }
        $sys = ''; $firstUser = '';
        foreach (($body['messages'] ?? []) as $m) {
            $role = $m['role'] ?? '';
            if ($role === 'system' && $sys === '') { $sys = self::contentText($m['content'] ?? ''); }
            if ($role === 'user' && $firstUser === '') {
                $firstUser = self::contentText($m['content'] ?? '');
                break;
            }
        }
        if ($sys === '' && $firstUser === '') { return null; }
        return 'd-' . substr(hash('sha256', $sys . "\x00" . $firstUser), 0, 16);
    }

    public static function contentText($content): string
    {
        if (is_string($content)) { return $content; }
        if (is_array($content)) {
            $s = '';
            foreach ($content as $p) {
                if (is_string($p)) { $s .= $p; }
                elseif (is_array($p)) {
                    if (($p['type'] ?? '') === 'text') { $s .= (string)($p['text'] ?? ''); }
                    else { $s .= '[' . (string)($p['type'] ?? 'part') . ']'; }
                }
            }
            return $s;
        }
        return '';
    }
}
