<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class SupplierException extends Exception
{
    public static function creationFailed(string $message, array $context = []): self
    {
        Log::error("Supplier creation failed: {$message}", $context);
        return new self("Error al crear proveedor. {$message}");
    }

    public static function updateFailed(string $message, array $context = []): self
    {
        Log::error("Supplier update failed: {$message}", $context);
        return new self("Error al actualizar proveedor. {$message}");
    }

    public static function deletionFailed(string $message, array $context = []): self
    {
        Log::error("Supplier deletion failed: {$message}", $context);
        return new self("Error al eliminar proveedor. {$message}");
    }
}
