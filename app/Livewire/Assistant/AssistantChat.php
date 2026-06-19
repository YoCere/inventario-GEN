<?php

namespace App\Livewire\Assistant;

use App\Models\WebConversation;
use App\Services\Assistant\AssistantWebHandler;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AssistantChat extends Component
{
    public string $draft = '';
    public bool $open = false;

    /** @var array<int, array{role:string, text:string}> */
    public array $bubbles = [];

    #[Locked]
    public string $currentRoute = '';

    public function mount(string $currentRoute = ''): void
    {
        $this->currentRoute = $currentRoute;
        $this->loadBubbles();
    }

    public function send(AssistantWebHandler $handler): void
    {
        $text = trim($this->draft);
        if ($text === '') {
            return;
        }

        $this->bubbles[] = ['role' => 'user', 'text' => $text];
        $this->draft = '';

        $reply = $handler->handle(auth()->user(), $text, $this->currentRoute);
        $this->bubbles[] = ['role' => 'assistant', 'text' => $reply];
    }

    public function clear(): void
    {
        WebConversation::getOrCreate(auth()->user())->clear();
        $this->bubbles = [];
    }

    /**
     * Reconstruye las burbujas visibles desde el historial persistido,
     * tomando solo turnos de texto plano (ignora bloques de tool-use).
     */
    private function loadBubbles(): void
    {
        $history = WebConversation::getOrCreate(auth()->user())->history();
        $out = [];
        foreach ($history as $msg) {
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';
            if (($role === 'user' || $role === 'assistant') && is_string($content) && trim($content) !== '') {
                $out[] = ['role' => $role, 'text' => $content];
            }
        }
        $this->bubbles = $out;
    }

    public function render()
    {
        return view('livewire.assistant.assistant-chat');
    }
}
