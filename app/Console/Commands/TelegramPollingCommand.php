<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Messaging\TelegramService;
use App\Services\Telegram\BotHandler;
use App\Models\TelegramConversation;
use Illuminate\Support\Facades\Log;

class TelegramPollingCommand extends Command
{
    protected $signature = 'telegram:poll';
    protected $description = 'Start Telegram bot polling (development only)';

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

    public function handle(TelegramService $telegram, BotHandler $handler): int
    {
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

                $this->error('Polling error: ' . $msg);
                Log::error('Telegram polling error', ['error' => $msg]);
                sleep($errDelay);
                $errDelay = min($errDelay * 2, 60);
            }
        }
    }
}
