<?php
declare(strict_types=1);
namespace WB2A\Upstream;

/** DeepSeek thinking injection / effort downgrade / reasoning_content backfill. */
final class Thinking
{
    private const RANK = ['off' => 0, 'minimal' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'xhigh' => 5, 'max' => 6];

    public static function isDeepSeek(string $model): bool
    {
        return stripos($model, 'deepseek') !== false;
    }

    public static function apply(array $body, array $messages): array
    {
        $model = (string)($body['model'] ?? '');
        if (!self::isDeepSeek($model)) { return $messages; }
        $th = $body['thinking'] ?? null;
        if (!is_array($th) || empty($th['type']) || !in_array($th['type'], ['enabled', 'disabled'], true)) {
            $body['thinking'] = ['type' => 'enabled'];
        }
        if (($body['thinking']['type'] ?? '') === 'disabled') {
            unset($body['reasoning_effort'], $body['reasoningEffort']);
        } elseif (empty($body['reasoning_effort']) && empty($body['reasoningEffort'])) {
            $body['reasoning_effort'] = 'high'; // defaultDeepSeekEffort
        }
        // effort downgrade against upstream model catalog (models cache) when available
        if (($body['thinking']['type'] ?? '') === 'enabled' && !empty($body['reasoning_effort'])) {
            $supported = self::supportedEfforts($model);
            if ($supported !== []) {
                $body['reasoning_effort'] = self::downgradeEffort((string)$body['reasoning_effort'], $supported);
            }
        }
        // reasoning_content backfill
        $needBackfill = ($body['thinking']['type'] ?? '') === 'enabled';
        foreach ($messages as $i => $m) {
            if (($m['role'] ?? '') !== 'assistant') { continue; }
            if (!array_key_exists('reasoning_content', $m)) {
                $messages[$i]['reasoning_content'] = $needBackfill ? ' ' : '';
            } elseif (!is_string($m['reasoning_content'])) {
                $messages[$i]['reasoning_content'] = '';
            }
        }
        return $messages;
    }

    /** Look up supported reasoning efforts from the models cache (best effort, [] = unknown). */
    private static function supportedEfforts(string $model): array
    {
        try {
            $cached = \WB2A\Core\Env::store()->kvGet('models_cache');
            if (!is_array($cached)) { return []; }
            foreach (($cached['data']['data'] ?? []) as $m) {
                if (($m['id'] ?? '') === $model) {
                    $se = $m['reasoning_supported_efforts'] ?? [];
                    return is_array($se) ? array_values(array_filter($se, 'is_string')) : [];
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
        return [];
    }

    public static function downgradeEffort(string $effort, array $supported): string
    {
        if ($supported === [] || in_array($effort, $supported, true)) { return $effort; }
        $rank = self::RANK[$effort] ?? null;
        if ($rank === null) { return $effort; }
        $best = null; $bestR = -1;
        foreach ($supported as $s) {
            $r = self::RANK[$s] ?? -1;
            if ($r <= $rank && $r > $bestR) { $bestR = $r; $best = $s; }
        }
        return $best ?? $effort;
    }
}
