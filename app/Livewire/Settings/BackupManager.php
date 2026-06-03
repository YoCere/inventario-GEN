<?php

namespace App\Livewire\Settings;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupManager extends Component
{
    public bool $isRunning = false;
    public string $lastError = '';

    public function mount(): void
    {
        abort_if(! auth()->user()?->isDeveloper(), 403);
    }

    #[Computed]
    public function backups(): array
    {
        clearstatcache(true);
        $disk = Storage::disk('backups');
        $files = [];

        try {
            // allFiles recurses into subdirectories — works regardless of how
            // spatie nests the backups (by app name, by date, flat, etc.)
            foreach ($disk->allFiles('/') as $filePath) {
                if (pathinfo($filePath, PATHINFO_EXTENSION) !== 'zip') {
                    continue;
                }

                $sizeBytes    = $disk->size($filePath);
                $lastModified = $disk->lastModified($filePath);

                $files[] = [
                    'name'       => basename($filePath),
                    'path'       => $filePath,
                    'size'       => Number::fileSize($sizeBytes),
                    'size_bytes' => $sizeBytes,
                    'date'       => Carbon::createFromTimestamp($lastModified)->format('d/m/Y H:i'),
                    'timestamp'  => $lastModified,
                ];
            }
        } catch (\Throwable $e) {
            // Disk inaccesible o dependencia faltante (ej. ext-intl para Number::fileSize).
            // No reventamos la vista, pero registramos para no ocultar fallos como antes.
            Log::warning('No se pudo listar backups', ['error' => $e->getMessage()]);
        }

        usort($files, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $files;
    }

    public function refreshList(): void
    {
        unset($this->backups);
    }

    public function runBackup(): void
    {
        abort_if(! auth()->user()?->isDeveloper(), 403);

        $this->isRunning = true;
        $this->lastError = '';

        try {
            $exitCode = Artisan::call('backup:run');
            $output   = Artisan::output();

            if ($exitCode !== 0) {
                $this->lastError = $output ?: 'El proceso de backup terminó con código de error ' . $exitCode;
                $this->dispatch('toast', message: 'Error al ejecutar el backup.', type: 'error');
            } else {
                $this->dispatch('toast', message: 'Backup completado correctamente.', type: 'success');
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->dispatch('toast', message: 'Error al ejecutar el backup: ' . $e->getMessage(), type: 'error');
        } finally {
            $this->isRunning = false;
            unset($this->backups); // Clear computed cache
        }
    }

    public function runClean(): void
    {
        abort_if(! auth()->user()?->isDeveloper(), 403);

        try {
            Artisan::call('backup:clean', ['--force' => true]);

            $this->dispatch('toast', message: 'Backups antiguos eliminados correctamente.', type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error al limpiar backups: ' . $e->getMessage(), type: 'error');
        } finally {
            unset($this->backups); // Clear computed cache
        }
    }

    public function download(string $path): StreamedResponse
    {
        abort_if(! auth()->user()?->isDeveloper(), 403);

        // Prevent path traversal
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            abort(400, 'Ruta de archivo no válida.');
        }

        // Only allow .zip files
        if (pathinfo($path, PATHINFO_EXTENSION) !== 'zip') {
            abort(400, 'Solo se pueden descargar archivos ZIP.');
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('backups');

        if (! $disk->exists($path)) {
            abort(404, 'Archivo de backup no encontrado.');
        }

        return $disk->download($path, basename($path));
    }

    public function delete(string $path): void
    {
        abort_if(! auth()->user()?->isDeveloper(), 403);

        // Prevent path traversal
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            $this->dispatch('toast', message: 'Ruta de archivo no válida.', type: 'error');
            return;
        }

        // Only allow .zip files
        if (pathinfo($path, PATHINFO_EXTENSION) !== 'zip') {
            $this->dispatch('toast', message: 'Solo se pueden eliminar archivos ZIP.', type: 'error');
            return;
        }

        $disk = Storage::disk('backups');

        if (! $disk->exists($path)) {
            $this->dispatch('toast', message: 'El archivo de backup no existe.', type: 'error');
            return;
        }

        try {
            $disk->delete($path);

            $this->dispatch('toast', message: 'Backup eliminado correctamente.', type: 'success');
        } catch (\Throwable $e) {
            $this->dispatch('toast', message: 'Error al eliminar el backup: ' . $e->getMessage(), type: 'error');
        } finally {
            unset($this->backups); // Clear computed cache
        }
    }

    public function render()
    {
        return view('livewire.settings.backup-manager');
    }
}
