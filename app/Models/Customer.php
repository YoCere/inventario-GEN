<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'notes',
        'doc_type',
        'doc_number',
        'doc_complement',
        'business_name',
    ];

    protected $casts = [
        'email' => 'string',
        'name' => 'string',
        'phone' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function billingIdentity(): ?\App\Fiscal\BillingIdentity
    {
        if (! $this->hasBillingIdentity()) {
            return null;
        }

        return new \App\Fiscal\BillingIdentity(
            (string) $this->doc_type,
            (string) $this->doc_number,
            $this->doc_complement,
            $this->business_name ?: $this->name,
        );
    }

    public function hasBillingIdentity(): bool
    {
        return filled($this->doc_type) && filled($this->doc_number);
    }
}
