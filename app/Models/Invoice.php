<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    public const TYPE_SALE = 'sale';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_RETURN = 'return';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'partner_id',
        'original_invoice_id',
        'type',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'total',
        'delivery_amount',
        'additional_total',
        'payable_total',
        'initial_paid',
        'amount_paid',
        'return_request_key',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'delivery_amount' => 'decimal:2',
            'additional_total' => 'decimal:2',
            'payable_total' => 'decimal:2',
            'initial_paid' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'cancelled_at' => 'datetime',
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

    public function additionalLines(): HasMany
    {
        return $this->hasMany(InvoiceAdditionalLine::class);
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

    public function purchaseFundingSource(): HasOne
    {
        return $this->hasOne(PurchaseFundingSource::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function getBalanceAttribute(): float
    {
        return $this->collectibleTotal() - (float) $this->amount_paid;
    }

    public function collectibleTotal(): float
    {
        return $this->isSale() && $this->payable_total !== null
            ? (float) $this->payable_total
            : (float) $this->total;
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
