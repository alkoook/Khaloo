<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    public const STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'];

    protected $fillable = [
        'number', 'partner_id', 'customer_name', 'customer_phone', 'quotation_date',
        'expires_at', 'status', 'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'discount', 'total', 'notes', 'converted_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'expires_at' => 'date',
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    public function getDisplayCustomerAttribute(): string
    {
        return $this->partner?->name ?: ($this->customer_name ?: 'عميل نقدي');
    }
}
