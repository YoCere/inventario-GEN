<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

class SaleException extends Exception
{
    public static function creationFailed(string $message, array $context = []): self
    {
        Log::error("Sale creation failed: {$message}", $context);
        return new self("Error al crear venta: {$message}");
    }

    public static function updateFailed(string $message, array $context = []): self
    {
        Log::error("Sale update failed: {$message}", $context);
        return new self("Error al actualizar venta: {$message}");
    }

    public static function cancellationFailed(string $message, array $context = []): self
    {
        Log::error("Sale cancellation failed: {$message}", $context);
        return new self("Error al cancelar venta: {$message}");
    }

    public static function invalidStatus(string $action, string $status, array $context = []): self
    {
        $message = "No se puede realizar {$action} en venta con estado '{$status}'.";
        Log::warning($message, $context);
        return new self($message);
    }

    public static function missingReference(string $reference, array $context = []): self
    {
        $message = "Referencia requerida faltante: {$reference}.";
        Log::warning($message, $context);
        return new self($message);
    }

    public static function insufficientStock(string $productName, int $requested, int $available): self
    {
        $message = "Stock insuficiente para el producto '{$productName}'. Solicitado: {$requested}, Disponible: {$available}.";
        Log::warning($message);
        return new self($message);
    }

    public static function productNotFound(int $productId): self
    {
        $message = "Producto con ID {$productId} no encontrado durante el procesamiento de venta.";
        Log::error($message);
        return new self($message);
    }

    public static function invalidDiscount(string $reason): self
    {
        Log::warning("Invalid discount applied: {$reason}");
        return new self("Descuento inválido: {$reason}");
    }

    public static function insufficientPayment(float $total, float $received): self
    {
        $message = "Insufficient payment. Total: {$total}, Received: {$received}";
        Log::warning($message);
        return new self("El pago es insuficiente. Favor cobrar el monto completo.");
    }
}
