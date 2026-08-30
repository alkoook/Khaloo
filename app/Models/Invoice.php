<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_RETURN = 'return';

    protected $fillable = [
        'partner_id',
        'original_invoice_id',
        'type',
        'total',
        'initial_paid',
        'amount_paid',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'initial_paid' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return HasMany<InvoiceLine>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(Invoice::class, 'original_invoice_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->total - (float) $this->amount_paid;
    }

    public function getRemainingAttribute(): float
    {
        return max(0, round($this->balance, 2));
    }

    public function isSale(): bool
    {
        return $this->type === self::TYPE_SALE;
    }

    public function isPurchase(): bool
    {
        return $this->type === self::TYPE_PURCHASE;
    }

    public function isReturn(): bool
    {
        return $this->type === self::TYPE_RETURN;
    }
}
