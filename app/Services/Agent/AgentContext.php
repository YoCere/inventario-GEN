<?php

namespace App\Services\Agent;

use App\Models\User;

/**
 * Per-request context passed to every tool invocation.
 * Holds auth user, channel info, and any session data.
 */
class AgentContext
{
    public function __construct(
        public readonly ?User $user,
        public readonly string $chatId,
        public readonly string $channel = 'telegram',
        public readonly ?string $route = null,
        public readonly ?string $systemPrompt = null,
    ) {}
}
