<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use App\Models\TelegramConversation;
use App\Models\User;
use App\Services\Messaging\TelegramService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class BotAuthHandler
{
    public function __construct(
        protected TelegramService $telegram,
    ) {}

    /**
     * Chequea si el chat_id está autenticado
     */
    public function isAuthenticated(string $chatId): bool
    {
        $telegramUser = TelegramUser::find($chatId);

        if (!$telegramUser) {
            return false;
        }

        // Verificar que User siga existiendo
        $user = $telegramUser->user;
        return $user !== null;
    }

    /**
     * Obtiene el User autenticado
     */
    public function getAuthenticatedUser(string $chatId): ?User
    {
        $telegramUser = TelegramUser::find($chatId);
        return $telegramUser?->user;
    }

    /**
     * Inicia el flujo de login
     */
    public function startLogin(string $chatId): void
    {
        $telegramUser = TelegramUser::find($chatId);

        if ($telegramUser) {
            // Segundo+ login - pedir solo contraseña
            $this->askPasswordOnly($chatId, $telegramUser);
        } else {
            // Primer login - pedir email/usuario
            $this->askIdentifier($chatId);
        }
    }

    private function askIdentifier(string $chatId): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'auth:identifier',
            'data' => [],
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "🔐 <b>Autenticación requerida</b>\n\n" .
            "¿Cuál es tu email o usuario?\n" .
            "(Escribe /cancelar para salir)"
        );
    }

    private function askPasswordOnly(string $chatId, TelegramUser $telegramUser): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $conversation->update([
            'step' => 'auth:password_only',
            'data' => ['identifier' => $telegramUser->identifier],
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "🔐 Bienvenido <b>{$telegramUser->user->name}</b> ({$telegramUser->identifier})\n\n" .
            "Ingresa tu contraseña:\n" .
            "(Escribe /cancelar para salir)"
        );
    }

    /**
     * Manejador de autenticación en conversación
     */
    public function handle(string $chatId, array $message): void
    {
        $conversation = TelegramConversation::getOrCreate($chatId);
        $text = trim($message['text'] ?? '');

        if (strtolower($text) === '/cancelar') {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Autenticación cancelada.");
            return;
        }

        match ($conversation->step) {
            'auth:identifier' => $this->handleIdentifierInput($chatId, $conversation, $text),
            'auth:password' => $this->handlePasswordInput($chatId, $conversation, $text),
            'auth:password_only' => $this->handlePasswordOnlyInput($chatId, $conversation, $text),
            default => $this->telegram->sendMessage($chatId, "❓ Error. Intenta de nuevo."),
        };
    }

    private function handleIdentifierInput(string $chatId, TelegramConversation $conversation, string $identifier): void
    {
        $identifier = strtolower(trim($identifier));

        if (empty($identifier)) {
            $this->telegram->sendMessage($chatId, "❌ Email o usuario no puede estar vacío.");
            return;
        }

        // Buscar usuario por email o username
        $user = User::where('email', $identifier)
            ->orWhere('name', $identifier)
            ->first();

        if (!$user) {
            $this->telegram->sendMessage(
                $chatId,
                "❌ Usuario no encontrado.\n\n" .
                "Verifica tu email o nombre de usuario e intenta de nuevo."
            );
            return;
        }

        // Guardar en conversación y pedir contraseña
        $data = $conversation->data ?? [];
        $data['identifier'] = $identifier;
        $data['user_id'] = $user->id;
        $data['user_name'] = $user->name;

        $conversation->update([
            'step' => 'auth:password',
            'data' => $data,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "✓ Usuario encontrado: <b>{$user->name}</b>\n\n" .
            "Ahora ingresa tu contraseña:\n" .
            "(Escribe /cancelar para salir)"
        );
    }

    private function handlePasswordInput(string $chatId, TelegramConversation $conversation, string $password): void
    {
        $data = $conversation->data ?? [];
        $userId = $data['user_id'] ?? null;
        $identifier = $data['identifier'] ?? null;

        if (!$userId || !$identifier) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Error. Intenta de nuevo.");
            return;
        }

        $user = User::find($userId);

        if (!$user) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Usuario no encontrado.");
            return;
        }

        // Validar contraseña
        if (!Hash::check($password, $user->password)) {
            $this->telegram->sendMessage($chatId, "❌ Contraseña incorrecta. Intenta de nuevo.");
            return;
        }

        // Login exitoso - guardar TelegramUser
        TelegramUser::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'user_id' => $user->id,
                'identifier' => $identifier,
                'last_login' => now(),
            ]
        );

        $conversation->delete();

        $this->telegram->sendMessage(
            $chatId,
            "✅ <b>¡Autenticado!</b>\n\n" .
            "Bienvenido {$user->name}.\n" .
            "Escribe /ayuda para ver comandos disponibles."
        );

        Log::info('Telegram user authenticated', [
            'chat_id' => $chatId,
            'user_id' => $user->id,
            'identifier' => $identifier,
        ]);
    }

    private function handlePasswordOnlyInput(string $chatId, TelegramConversation $conversation, string $password): void
    {
        $identifier = $conversation->data['identifier'] ?? null;
        $telegramUser = TelegramUser::find($chatId);

        if (!$identifier || !$telegramUser) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Error. Intenta de nuevo.");
            return;
        }

        $user = $telegramUser->user;

        if (!$user) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Usuario no encontrado.");
            return;
        }

        // Validar contraseña
        if (!Hash::check($password, $user->password)) {
            $this->telegram->sendMessage($chatId, "❌ Contraseña incorrecta. Intenta de nuevo.");
            return;
        }

        // Update last_login
        $telegramUser->update(['last_login' => now()]);

        $conversation->delete();

        $this->telegram->sendMessage(
            $chatId,
            "✅ Autenticado.\n\n" .
            "¿Qué necesitas hacer?"
        );

        Log::info('Telegram user re-authenticated', [
            'chat_id' => $chatId,
            'user_id' => $user->id,
        ]);
    }
}
