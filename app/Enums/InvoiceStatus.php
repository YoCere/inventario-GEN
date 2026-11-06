<?php

namespace App\Enums;

/**
 * Estado fiscal de una factura. Independiente del proveedor: fromProvider() traduce
 * los textos de Falmus. Un estado desconocido cae en NEEDS_REVIEW (nunca se asume OK).
 */
enum InvoiceStatus: string
{
    case DRAFT = 'draft';               // creada localmente, aún no enviada
    case ISSUING = 'issuing';           // request en vuelo
    case ACCEPTED = 'accepted';         // 908
    case OBSERVED = 'observed';         // 904
    case REJECTED = 'rejected';         // 902
    case VOIDED = 'voided';             // 905
    case PENDING_SEND = 'pending_send'; // contingencia: Falmus la enviará solo
    case UNCERTAIN = 'uncertain';       // se cortó la respuesta: NO reemitir, conciliar
    case NEEDS_REVIEW = 'needs_review'; // estado raro del proveedor
    case FAILED = 'failed';             // 4xx de validación: se puede corregir y reintentar

    public static function fromProvider(string $estado): self
    {
        return match ($estado) {
            'aceptado' => self::ACCEPTED,
            'observado' => self::OBSERVED,
            'rechazado' => self::REJECTED,
            'anulado' => self::VOIDED,
            'pendiente_envio', 'pendiente_validacion' => self::PENDING_SEND,
            default => self::NEEDS_REVIEW,
        };
    }

    /** true = ya existe (o puede existir) una factura en el SIN: prohibido emitir otra. */
    public function blocksReissue(): bool
    {
        return ! in_array($this, [self::DRAFT, self::FAILED, self::REJECTED], true);
    }
}
