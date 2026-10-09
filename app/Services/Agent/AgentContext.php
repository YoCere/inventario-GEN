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
        /**
         * Cómo llegó el mensaje: 'texto' o 'audio'. Las herramientas lo usan
         * cuando el dictado merece otro trato — un audio transcrito puede traer
         * cualquier dato mal escrito, así que conviene hacerlo revisar.
         */
        public readonly string $source = 'texto',
    ) {}
}
