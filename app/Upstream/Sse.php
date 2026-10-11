<?php
declare(strict_types=1);
namespace WB2A\Upstream;

/**
 * SSE frame normalization / aggregation (mirrors Go internal/upstream/sse.go + dsml.go).
 */
final class Sse
{
    /** Normalize one data-frame array to the OpenAI whitelist; returns null to drop. */
    public static function normalizeFrame(array $f): ?array
    {
        if (isset($f['error'])) { return $f; } // error frames pass through untouched
        $out = [];
        foreach (['id', 'object', 'created', 'model', 'system_fingerprint', 'service_tier'] as $k) {
            if (isset($f[$k])) { $out[$k] = $f[$k]; }
        }
        $out['object'] = $out['object'] ?? 'chat.completion.chunk';
        $out['id'] = $out['id'] ?? ('chatcmpl-wb2api-' . substr(bin2hex(random_bytes(6)), 0, 10));
        $out['created'] = $out['created'] ?? time();
        $choices = [];
        foreach (($f['choices'] ?? []) as $c) {
            if (!is_array($c)) { continue; }
            $cc = [];
            if (isset($c['index'])) { $cc['index'] = $c['index']; }
            $d = $c['delta'] ?? ($c['message'] ?? []);
            $delta = [];
            foreach (['role', 'content', 'reasoning_content', 'refusal'] as $k) {
                if (isset($d[$k]) && $d[$k] !== '') { $delta[$k] = $d[$k]; }
            }
            if (!empty($d['tool_calls'])) { $delta['tool_calls'] = $d['tool_calls']; }
            if (!empty($d['function_call']) && (string)($d['function_call']['name'] ?? '') !== '') { $delta['function_call'] = $d['function_call']; } // drop empty shells
            if ($delta !== []) { $cc['delta'] = $delta; }
            $fr = $c['finish_reason'] ?? null;
            if ($fr === '') { $fr = null; }
            $cc['finish_reason'] = $fr;
            $choices[] = $cc;
        }
        $out['choices'] = $choices;
        $out['usage'] = $f['usage'] ?? null;
        if (is_array($out['usage'])) {
            foreach (['prompt_tokens_details' => 'cached_tokens', 'completion_tokens_details' => 'reasoning_tokens'] as $k => $alias) {
                if (isset($out['usage'][$k]) && is_array($out['usage'][$k]) && !isset($out['usage'][$alias]) && isset($out['usage'][$k][$alias])) {
                    $out['usage'][$alias] = $out['usage'][$k][$alias];
                }
            }
        }
        return $out;
    }

    /** Aggregate full SSE stream (array of decoded frames) into a non-stream response. */
    public static function aggregate(array $frames): ?array
    {
        $id = ''; $model = ''; $created = time(); $content = ''; $reasoning = ''; $finish = null;
        $toolCalls = []; $usage = null; $valid = 0;
        foreach ($frames as $f) {
            if (!is_array($f)) { continue; }
            if (isset($f['error'])) { return ['__error' => $f['error']]; }
            if (isset($f['usage']) && is_array($f['usage'])) { $usage = $f['usage']; }
            foreach (($f['choices'] ?? []) as $c) {
                $d = $c['delta'] ?? ($c['message'] ?? []);
                if (isset($d['content']) && $d['content'] !== '') { $content .= $d['content']; $valid++; }
                if (isset($d['reasoning_content']) && $d['reasoning_content'] !== '') { $reasoning .= $d['reasoning_content']; $valid++; }
                if (!empty($d['tool_calls'])) {
                    foreach ($d['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? null;
                        $slot = null;
                        if ($idx !== null && isset($toolCalls[$idx])) { $slot = $idx; }
                        elseif ($idx === null && isset($tc['id'])) {
                            foreach ($toolCalls as $i => $t) { if (($t['id'] ?? '') === $tc['id']) { $slot = $i; break; } }
                        }
                        if ($slot === null) {
                            $slot = $idx !== null ? $idx : count($toolCalls);
                            $toolCalls[$slot] = ['id' => $tc['id'] ?? ('call_' . bin2hex(random_bytes(8))), 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                        }
                        if (!empty($tc['function']['name'])) { $toolCalls[$slot]['function']['name'] .= $tc['function']['name']; }
                        if (isset($tc['function']['arguments'])) { $toolCalls[$slot]['function']['arguments'] .= $tc['function']['arguments']; }
                        if (!empty($tc['id'])) { $toolCalls[$slot]['id'] = $tc['id']; }
                    }
                    $valid++;
                }
                if (!empty($c['finish_reason'])) { $finish = $c['finish_reason']; }
                if (isset($f['model'])) { $model = $f['model']; }
                if (isset($f['id']) && $id === '') { $id = $f['id']; }
            }
        }
        if ($valid === 0) { return null; }
        $msg = ['role' => 'assistant'];
        if ($reasoning !== '') { $msg['reasoning_content'] = $reasoning; }
        $msg['content'] = $content;
        if ($toolCalls !== []) {
            ksort($toolCalls);
            $msg['tool_calls'] = array_values($toolCalls);
            if ($finish === null) { $finish = 'tool_calls'; }
        }
        if ($usage !== null && !isset($usage['total_tokens']) && isset($usage['prompt_tokens'], $usage['completion_tokens'])) {
            $usage['total_tokens'] = $usage['prompt_tokens'] + $usage['completion_tokens'];
        }
        return ['id' => $id ?: ('chatcmpl-wb2api-' . bin2hex(random_bytes(8))), 'object' => 'chat.completion', 'created' => $created, 'model' => $model, 'choices' => [['index' => 0, 'message' => $msg, 'finish_reason' => $finish ?? 'stop']], 'usage' => $usage];
    }

    // ---------- DSML native tool-call markup repair ----------
    private const PIPE = "\u{FF5C}\u{FF5C}";

    public static function repairToolMarkup(string $text, array $allow): array
    {
        $open = '<' . self::PIPE . 'DSML' . self::PIPE . ' calls>';
        $close = '</' . self::PIPE . 'DSML' . self::PIPE . ' calls>';
        $calls = [];
        $pos = 0;
        while (($start = strpos($text, $open, $pos)) !== false) {
            $end = strpos($text, $close, $start);
            if ($end === false) { break; }
            $block = substr($text, $start + strlen($open), $end - $start - strlen($open));
            $pos = $end + strlen($close);
            if (strlen($block) > 256 * 1024) { continue; }
            if (!preg_match_all('/<' . self::PIPE . 'DSML' . self::PIPE . ' invoke name="([^"]+)">(.*?)<' . self::PIPE . 'DSML' . self::PIPE . ' invoke>/s', $block, $ms, PREG_SET_ORDER)) { continue; }
            $blockOnly = trim(preg_replace('/<' . self::PIPE . 'DSML' . self::PIPE . '[^>]*>|<\/' . self::PIPE . 'DSML' . self::PIPE . '[^>]*>|\s+/u', '', $block) ?? '');
            if ($blockOnly !== '') { continue; } // markup block must not carry extra prose
            foreach ($ms as $m) {
                $name = $m[1];
                if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}$/', $name)) { continue; }
                if ($allow !== [] && !in_array($name, $allow, true)) { continue; }
                $args = [];
                if (preg_match_all('/<' . self::PIPE . 'DSML' . self::PIPE . ' parameter name="([^"]+)" string="([^"]*)">(.*?)<' . self::PIPE . 'DSML' . self::PIPE . ' parameter>/s', $m[2], $ps, PREG_SET_ORDER)) {
                    foreach ($ps as $p) {
                        $v = $p[3];
                        if ($p[2] !== 'true') { $v = is_numeric($v) ? $v + 0 : $v; }
                        $args[$p[1]] = $v;
                    }
                }
                $calls[] = ['id' => 'call_' . bin2hex(random_bytes(8)), 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args === [] ? new \stdClass() : $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]];
            }
        }
        return $calls;
    }
}
