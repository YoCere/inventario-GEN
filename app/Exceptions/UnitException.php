<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class UnitException extends Exception
{
    public static function creationFailed(string $message, array $context = []): self
    {
        Log::error("Unit creation failed: {$message}", $context);
        return new self("Error al crear unidad. {$message}");
    }

    public static function updateFailed(string $message, array $context = []): self
    {
        Log::error("Unit update failed: {$message}", $context);
        return new self("Error al actualizar unidad. {$message}");
    }

    public static function deletionFailed(string $message, array $context = []): self
    {
        Log::error("Unit deletion failed: {$message}", $context);
        return new self("Error al eliminar unidad. {$message}");
    }
}
