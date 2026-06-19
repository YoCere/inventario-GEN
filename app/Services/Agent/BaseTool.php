<?php

namespace App\Services\Agent;

abstract class BaseTool
{
    /**
     * Tool name as exposed to the LLM (snake_case, no spaces).
     */
    abstract public function name(): string;

    /**
     * Short description for the LLM.
     */
    abstract public function description(): string;

    /**
     * JSON Schema for input. Keep it minimal: less tokens.
     */
    abstract public function inputSchema(): array;

    /**
     * Execute the tool. $input is the validated payload from the LLM.
     * Return array (will be JSON-encoded for LLM).
     *
     * @throws \Throwable
     */
    abstract public function execute(array $input, AgentContext $context): array;

    /**
     * Whether this tool requires explicit user confirmation before execution.
     */
    public function requiresConfirmation(): bool
    {
        return false;
    }

    /**
     * Human-readable summary of what this tool will do given input. Shown to user
     * during confirmation. Override in destructive tools.
     */
    public function confirmationSummary(array $input): string
    {
        return $this->name() . ' (' . json_encode($input, JSON_UNESCAPED_UNICODE) . ')';
    }

    /**
     * Permiso Spatie requerido para que un usuario use esta tool.
     * null = pública (ayuda/how-to sin restricción). El ToolRegistry::forUser()
     * filtra por este valor; AgentService no expone tools que el usuario no pueda usar.
     */
    public function requiredPermission(): ?string
    {
        return null;
    }

    /**
     * Si la tool puede usarse desde el canal web (la burbuja). Default true.
     * Las tools que MODIFICAN datos (crear venta/producto) la ponen en false:
     * el asistente web es solo lectura. ToolRegistry::forWeb() las excluye.
     */
    public function webExposed(): bool
    {
        return true;
    }

    /**
     * Anthropic tool definition (passed to API).
     */
    public function toAnthropicSchema(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'input_schema' => $this->inputSchema(),
        ];
    }

    /**
     * OpenAI-compatible tool definition (DeepSeek, Groq, OpenAI, etc.).
     */
    public function toOpenAiSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $this->inputSchema(),
            ],
        ];
    }
}
