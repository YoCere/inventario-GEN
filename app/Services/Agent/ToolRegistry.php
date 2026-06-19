<?php

namespace App\Services\Agent;

class ToolRegistry
{
    /** @var array<string, BaseTool> */
    private array $tools = [];

    public function register(BaseTool $tool): self
    {
        $this->tools[$tool->name()] = $tool;
        return $this;
    }

    public function get(string $name): ?BaseTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Devuelve un ToolRegistry NUEVO con solo las tools que el usuario puede usar.
     * Tools con requiredPermission()===null son públicas. Respeta Gate::before
     * (developer pasa todo) vía $user->can().
     */
    public function forUser(\App\Models\User $user): self
    {
        $filtered = new self();
        foreach ($this->tools as $tool) {
            $perm = $tool->requiredPermission();
            if ($perm === null || $user->can($perm)) {
                $filtered->register($tool);
            }
        }
        return $filtered;
    }

    /**
     * Como forUser() pero además excluye tools NO expuestas a web (escritura).
     * Frontera de seguridad del canal web: el asistente de la burbuja es solo lectura.
     */
    public function forWeb(\App\Models\User $user): self
    {
        $filtered = new self();
        foreach ($this->tools as $tool) {
            if (! $tool->webExposed()) {
                continue;
            }
            $perm = $tool->requiredPermission();
            if ($perm === null || $user->can($perm)) {
                $filtered->register($tool);
            }
        }
        return $filtered;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function anthropicSchemas(): array
    {
        return array_values(array_map(
            fn (BaseTool $t) => $t->toAnthropicSchema(),
            $this->tools
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function openaiSchemas(): array
    {
        return array_values(array_map(
            fn (BaseTool $t) => $t->toOpenAiSchema(),
            $this->tools
        ));
    }

    /**
     * @return array<string, BaseTool>
     */
    public function all(): array
    {
        return $this->tools;
    }
}
