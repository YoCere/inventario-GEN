<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class PurchaseException extends Exception
{
    public static function creationFailed(string $message, array $context = []): self
    {
        Log::error("Purchase creation failed: {$message}", $context);
        return new self("Error al crear compra: {$message}");
    }

    public static function updateFailed(string $message, array $context = []): self
    {
        Log::error("Purchase update failed: {$message}", $context);
        return new self("Error al actualizar compra: {$message}");
    }

    public static function deletionFailed(string $message, array $context = []): self
    {
        Log::error("Purchase deletion failed: {$message}", $context);
        return new self("Error al eliminar compra. {$message}");
    }

    public static function invalidStatus(string $action, string $status, array $context = []): self
    {
        $message = "No se puede {$action} compra con estado '{$status}'.";
        Log::warning($message, $context);
        return new self($message);
    }

    public static function missingReference(string $reference, array $context = []): self
    {
        $message = "Referencia requerida faltante: {$reference}.";
        Log::warning($message, $context);
        return new self($message);
    }
}
