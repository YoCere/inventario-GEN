<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use App\Models\TelegramConversation;
use App\Models\User;
use App\Services\Messaging\TelegramService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class BotAuthHandler
{
    public function __construct(
        protected TelegramService $telegram,
    ) {}

    /** Sesión expira automáticamente tras este tiempo de inactividad. */
    public const SESSION_TTL_HOURS = 24;

    /**
     * Cuántas contraseñas se pueden errar antes de que el chat quede frenado.
     * Mismo número que el login de la web (LoginRequest), para que la regla sea
     * una sola y no dos que se contradicen según por dónde entre la persona.
     */
    public const MAX_INTENTOS = 5;

    /**
     * Cuánto dura el freno. La ventana de un minuto que usa la web no sirve acá:
     * el chat no tiene captcha ni IP estable, y un minuto de espera no desalienta
     * a nadie que esté probando contraseñas con un script.
     */
    public const VENTANA_FRENO_SEGUNDOS = 900;

    /**
     * Un solo texto para "no existe ese usuario" y para "la contraseña está mal".
     * Antes el bot contestaba distinto en cada caso, y encima devolvía el nombre
     * real: con eso, sin saber ninguna contraseña, se podía averiguar quién
     * trabaja en el negocio. El repositorio es público, así que el flujo entero
     * es conocido.
     */
    private const MSG_CREDENCIALES = "❌ Usuario o contraseña incorrectos.\n\nIntentá de nuevo o escribí /cancelar para salir.";

    /**
     * Chequea si chat_id está autenticado Y sesión sigue vigente.
     * Sesiones idle >SESSION_TTL_HOURS se invalidan y obligan re-login (solo
     * pide pwd, el identifier persiste para UX rápida).
     */
    public function isAuthenticated(string $chatId): bool
    {
        $telegramUser = TelegramUser::find($chatId);

        if (!$telegramUser) {
            return false;
        }

        if (!$telegramUser->user) {
            return false;
        }

        // Sesión expirada por inactividad
        if (!$telegramUser->last_login
            || $telegramUser->last_login->lt(now()->subHours(self::SESSION_TTL_HOURS))) {
            return false;
        }

        return true;
    }

    /**
     * Cerrar sesión. Borra la asociación TelegramUser → User. El identifier
     * NO se recuerda, así que el próximo login pedirá usuario + contraseña
     * (escenario "cambio de usuario"). Para mantener identifier y solo pedir
     * pwd otra vez, usar refreshSession() en lugar.
     */
    public function logout(string $chatId, bool $forgetIdentifier = true): void
    {
        $telegramUser = TelegramUser::find($chatId);
        if ($telegramUser) {
            if ($forgetIdentifier) {
                $telegramUser->delete();
            } else {
                // Conservar identifier — solo invalidar la sesión bumping last_login al pasado
                $telegramUser->update(['last_login' => null]);
            }
        }

        // Cualquier conversación activa también se borra para empezar limpio
        TelegramConversation::where('chat_id', $chatId)->delete();
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
        // Frenado: ni se le pide la contraseña. Si se le pidiera, cada mensaje
        // suyo abriría el formulario de nuevo y el aviso de espera se perdería.
        if ($this->avisaSiEstaFrenado($chatId)) {
            return;
        }

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

        // Sin el nombre real: el identifier ya lo escribió la persona en este
        // chat, pero el nombre es un dato nuevo que el bot regalaba antes de
        // comprobar una sola contraseña.
        $this->telegram->sendMessage(
            $chatId,
            "🔐 <b>Ingresá tu contraseña</b> ({$telegramUser->identifier})\n\n" .
            "(Escribí /cancelar para salir)"
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

        // El mensaje con la contraseña se borra ANTES de cualquier otra cosa, y
        // acierte o falle: si se borrara solo al acertar, una contraseña mal
        // tecleada —que suele ser la buena con un dedazo— se quedaría escrita en
        // el chat. También se borra con el chat frenado, porque ahí la persona
        // igual la escribió.
        if (in_array($conversation->step, ['auth:password', 'auth:password_only'], true)) {
            $this->borraElMensajeConLaClave($chatId, $message);
        }

        // El freno se mira antes de leer la contraseña: si no, el chat frenado
        // seguiría sirviendo para probar claves, solo sin enterarse del resultado.
        if ($this->avisaSiEstaFrenado($chatId)) {
            return;
        }

        match ($conversation->step) {
            'auth:identifier' => $this->handleIdentifierInput($chatId, $conversation, $text),
            'auth:password' => $this->handlePasswordInput($chatId, $conversation, $text),
            'auth:password_only' => $this->handlePasswordOnlyInput($chatId, $conversation, $text),
            default => $this->telegram->sendMessage($chatId, "❓ Error. Intenta de nuevo."),
        };
    }

    /** Clave del contador de intentos. Por chat: es lo único estable que hay acá. */
    private function claveDelFreno(string $chatId): string
    {
        return 'bot-login:' . $chatId;
    }

    /**
     * ¿Está frenado este chat? Si lo está, le dice cuánto falta y devuelve true
     * para que quien llama corte.
     */
    private function avisaSiEstaFrenado(string $chatId): bool
    {
        if (! RateLimiter::tooManyAttempts($this->claveDelFreno($chatId), self::MAX_INTENTOS)) {
            return false;
        }

        $segundos = RateLimiter::availableIn($this->claveDelFreno($chatId));
        $minutos = max(1, (int) ceil($segundos / 60));

        Log::warning('Bot: login frenado por demasiados intentos', [
            'chat_id' => $chatId,
            'espera_minutos' => $minutos,
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "🔒 <b>Demasiados intentos</b>\n\n" .
            "Por seguridad el ingreso quedó frenado. Probá de nuevo en {$minutos} " .
            ($minutos === 1 ? 'minuto' : 'minutos') . "."
        );

        return true;
    }

    /** Anota un intento fallido. Al sexto, el chat queda frenado. */
    private function anotaIntentoFallido(string $chatId): void
    {
        RateLimiter::hit($this->claveDelFreno($chatId), self::VENTANA_FRENO_SEGUNDOS);
    }

    /**
     * Borra del chat el mensaje donde la persona escribió su contraseña.
     *
     * Si Telegram no deja borrarlo (mensaje viejo, permisos del bot, red caída)
     * no se puede hacer nada más que anotarlo: dejar a la persona sin entrar por
     * un borrado fallido sería peor que el mensaje que queda.
     */
    private function borraElMensajeConLaClave(string $chatId, array $message): void
    {
        $messageId = $message['message_id'] ?? null;

        if ($messageId === null) {
            return;
        }

        try {
            $this->telegram->deleteMessage($chatId, (int) $messageId);
        } catch (\Throwable $e) {
            Log::warning('Bot: no se pudo borrar el mensaje con la contraseña', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
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

        // Exista o no, el camino es el mismo: se pide la contraseña y recién ahí
        // se contesta. Contestar acá "usuario no encontrado" convertía al bot en
        // un buscador de cuentas válidas que no pedía ninguna credencial.
        $data = $conversation->data ?? [];
        $data['identifier'] = $identifier;
        $data['user_id'] = $user?->id;

        $conversation->update([
            'step' => 'auth:password',
            'data' => $data,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "🔐 <b>Ingresá tu contraseña</b>\n\n" .
            "(Escribí /cancelar para salir)"
        );
    }

    private function handlePasswordInput(string $chatId, TelegramConversation $conversation, string $password): void
    {
        $data = $conversation->data ?? [];
        $userId = $data['user_id'] ?? null;
        $identifier = $data['identifier'] ?? null;

        if (!$identifier) {
            $conversation->delete();
            $this->telegram->sendMessage($chatId, "❌ Error. Intenta de nuevo.");
            return;
        }

        $user = $userId ? User::find($userId) : null;

        // Usuario inexistente y contraseña equivocada terminan igual, con el
        // mismo texto y el mismo intento anotado. La conversación NO se borra:
        // quien se equivocó tecleando tiene que poder reintentar sin volver a
        // escribir el usuario, igual que antes.
        if (! $user || ! Hash::check($password, $user->password)) {
            $this->anotaIntentoFallido($chatId);
            $this->telegram->sendMessage($chatId, self::MSG_CREDENCIALES);
            return;
        }

        RateLimiter::clear($this->claveDelFreno($chatId));

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

        // Mismo criterio que el otro paso: un solo texto, un intento anotado.
        if (! $user || ! Hash::check($password, $user->password)) {
            $this->anotaIntentoFallido($chatId);
            $this->telegram->sendMessage($chatId, self::MSG_CREDENCIALES);
            return;
        }

        RateLimiter::clear($this->claveDelFreno($chatId));

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
