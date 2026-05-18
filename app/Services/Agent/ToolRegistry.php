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
