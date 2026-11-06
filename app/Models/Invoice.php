<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'sale_id', 'provider', 'status', 'provider_status', 'document_sector', 'point_of_sale_ref',
        'external_id', 'cuf', 'number', 'siat_status_code',
        'url_pdf', 'url_xml', 'url_qr', 'issued_at',
        'buyer_doc_type', 'buyer_doc_number', 'buyer_doc_complement', 'buyer_name', 'buyer_code',
        'total', 'void_reason_code', 'voided_at', 'attempts', 'last_error',
    ];

    protected $casts = [
        'status' => InvoiceStatus::class,
        'total' => 'integer',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
        'last_error' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
