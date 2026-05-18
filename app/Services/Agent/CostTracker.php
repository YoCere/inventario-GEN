<?php

namespace App\Services\Agent;

use App\Models\AiUsageLog;
use App\Models\Setting;
use Carbon\Carbon;

class CostTracker
{
    /**
     * Anthropic pricing (USD per 1M tokens) as of 2026-Q2.
     * Update keys here as new models release.
     */
    private const PRICES = [
        'claude-haiku-4-5-20251001' => ['input' => 0.80, 'output' => 4.00, 'cache_write' => 1.00, 'cache_read' => 0.08],
        'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],
        'claude-opus-4-7' => ['input' => 15.00, 'output' => 75.00, 'cache_write' => 18.75, 'cache_read' => 1.50],

        // DeepSeek (OpenAI-compatible)
        'deepseek-chat' => ['input' => 0.27, 'output' => 1.10, 'cache_write' => 0.0, 'cache_read' => 0.07],
        'deepseek-reasoner' => ['input' => 0.55, 'output' => 2.19, 'cache_write' => 0.0, 'cache_read' => 0.14],

        // OpenAI
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60, 'cache_write' => 0.0, 'cache_read' => 0.075],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00, 'cache_write' => 0.0, 'cache_read' => 1.25],

        // External services
        'whisper-1' => ['per_minute' => 0.006],
        'tts-1' => ['per_1m_chars' => 15.00],
        'piper' => ['per_1m_chars' => 0.0], // self-hosted
    ];

    public function record(
        ?int $userId,
        ?string $chatId,
        string $model,
        string $action,
        int $tokensIn = 0,
        int $tokensOut = 0,
        int $cacheRead = 0,
        int $cacheWrite = 0,
        int $audioSeconds = 0,
        ?string $summary = null,
    ): AiUsageLog {
        $cost = $this->calculateCost($model, $tokensIn, $tokensOut, $cacheRead, $cacheWrite, $audioSeconds);

        return AiUsageLog::create([
            'user_id' => $userId,
            'chat_id' => $chatId,
            'channel' => 'telegram',
            'model' => $model,
            'action' => $action,
            'tokens_input' => $tokensIn,
            'tokens_output' => $tokensOut,
            'tokens_cache_read' => $cacheRead,
            'tokens_cache_write' => $cacheWrite,
            'audio_seconds' => $audioSeconds,
            'cost_usd' => $cost,
            'summary' => $summary,
            'created_at' => now(),
        ]);
    }

    public function calculateCost(
        string $model,
        int $tokensIn,
        int $tokensOut,
        int $cacheRead,
        int $cacheWrite,
        int $audioSeconds,
    ): float {
        $p = self::PRICES[$model] ?? null;
        if (!$p) {
            return 0.0;
        }

        if (isset($p['per_minute'])) {
            return ($audioSeconds / 60) * $p['per_minute'];
        }

        // LLM pricing per token
        $cost = 0.0;
        $cost += ($tokensIn / 1_000_000) * ($p['input'] ?? 0);
        $cost += ($tokensOut / 1_000_000) * ($p['output'] ?? 0);
        $cost += ($cacheRead / 1_000_000) * ($p['cache_read'] ?? 0);
        $cost += ($cacheWrite / 1_000_000) * ($p['cache_write'] ?? 0);

        return round($cost, 6);
    }

    /**
     * Check if daily cost limit exceeded. Returns true if within limit.
     */
    public function withinDailyLimit(): bool
    {
        $maxDaily = (float) Setting::get('ai_max_cost_usd_per_day', '5.00');

        $todayCost = AiUsageLog::whereDate('created_at', today())->sum('cost_usd');

        return $todayCost < $maxDaily;
    }

    /**
     * Get today's spend summary.
     */
    public function todaySpend(): array
    {
        $rows = AiUsageLog::whereDate('created_at', today())
            ->selectRaw('action, SUM(cost_usd) as cost, COUNT(*) as count')
            ->groupBy('action')
            ->get();

        return [
            'total' => round($rows->sum('cost'), 4),
            'by_action' => $rows->keyBy('action')->toArray(),
        ];
    }
}
