<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use App\Models\TelegramConversation;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramPollingCommand extends Command
{
    protected $signature = 'telegram:poll';
    protected $description = 'Escucha los mensajes del bot de Telegram (lo levanta supervisor en el servidor).';

    private static function pidFile(): string
    {
        return storage_path('framework/telegram-poll.pid');
    }

    private static function stopFile(): string
    {
        return storage_path('framework/telegram-poll.stop');
    }

    private function isProcessRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq {$pid}\" /NH 2>&1", $output);
            return str_contains(implode('', $output), (string) $pid);
        }
        return file_exists("/proc/{$pid}");
    }

    /**
     * ¿El bot está configurado y encendido?
     *
     * Se consulta al arrancar y cada tanto durante la escucha: el proceso es
     * largo y el token se carga desde Ajustes, así que si saliéramos a esperar
     * para siempre nunca tomaríamos un token cargado después. Salir y dejar que
     * supervisor nos vuelva a levantar es lo que hace que funcione sin redeploy.
     */
    private function botConfigurado(): bool
    {
        Cache::forget('settings.telegram_enabled');
        Cache::forget('settings.telegram_bot_token');

        return Setting::get('telegram_enabled', '0') === '1'
            && trim((string) Setting::get('telegram_bot_token', '')) !== '';
    }

    public function handle(TelegramService $telegram, BotHandler $handler): int
    {
        if (! $this->botConfigurado()) {
            $this->warn('El bot de Telegram está apagado o sin token (Ajustes → Sistema). No hay nada que escuchar.');

            return Command::SUCCESS;
        }

        $pidFile = self::pidFile();

        if (file_exists($pidFile)) {
            $existingPid = (int) trim((string) file_get_contents($pidFile));
            if ($this->isProcessRunning($existingPid)) {
                $this->warn("Already running (PID {$existingPid}). Use php artisan telegram:stop to stop it.");
                return Command::FAILURE;
            }
            // Stale PID file from a previous crash or Ctrl+C
            $this->warn("Stale PID file found (PID {$existingPid} not running). Cleaning up and starting.");
            @unlink($pidFile);
        }

        file_put_contents($pidFile, (string) getmypid());
        @unlink(self::stopFile());

        // Clean up PID file on any exit (Ctrl+C, crash, etc.)
        register_shutdown_function(function () use ($pidFile): void {
            @unlink($pidFile);
            @unlink(self::stopFile());
        });

        $this->info('Telegram polling started (PID ' . getmypid() . '). Use php artisan telegram:stop to stop.');

        $offset    = 0;
        $errDelay  = 5;   // seconds; doubles on consecutive errors, caps at 60

        while (true) {
            if (file_exists(self::stopFile())) {
                $this->info('Stop signal received. Exiting.');
                return Command::SUCCESS; // shutdown function cleans files
            }

            // Apagado desde Ajustes o token cambiado: salimos prolijo. Supervisor
            // nos vuelve a levantar y, si corresponde, arrancamos con el token nuevo.
            if (! $this->botConfigurado()) {
                $this->warn('El bot quedó apagado o sin token en Ajustes. Dejo de escuchar.');

                return Command::SUCCESS;
            }

            try {
                $updates = $telegram->getUpdates($offset, timeout: 20);

                $errDelay = 5; // reset on success

                if (empty($updates)) {
                    continue;
                }

                foreach ($updates as $update) {
                    // Avanzar offset PRIMERO para que un crash del handler no atasque el bot
                    // en el mismo update infinitamente (Telegram re-enviaría tras getUpdates).
                    $offset = $update['update_id'] + 1;
                    try {
                        $handler->dispatch($update);
                    } catch (\Throwable $e) {
                        Log::error('Telegram dispatch failed for update', [
                            'update_id' => $update['update_id'] ?? null,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }

                TelegramConversation::cleanExpired();

            } catch (\Throwable $e) {
                $msg = $e->getMessage();

                // cURL 28 = timeout transitorio en long-polling. Esperado en network
                // blips (WiFi, ISP, NAT). Reintentar rápido sin backoff exponencial.
                // Backoff pleno se reserva para errores reales (401 token inválido, etc.).
                $isTransientTimeout = str_contains($msg, 'cURL error 28')
                                   || str_contains($msg, 'Operation timed out')
                                   || str_contains($msg, 'Connection timed out');

                if ($isTransientTimeout) {
                    Log::info('Telegram polling timeout (transient, retrying)', ['error' => $msg]);
                    sleep(2);
                    continue;
                }

                // Telegram no deja escuchar y tener webhook al mismo tiempo.
                if (str_contains($msg, '409') || str_contains(mb_strtolower($msg), 'conflict')) {
                    $this->error('Hay un webhook configurado en este bot: Telegram no permite las dos formas a la vez.');
                    Log::error('Telegram polling en conflicto con un webhook activo', ['error' => $msg]);
                }

                $this->error('Polling error: ' . $msg);
                Log::error('Telegram polling error', ['error' => $msg]);
                sleep($errDelay);
                $errDelay = min($errDelay * 2, 60);
            }
        }
    }
}
