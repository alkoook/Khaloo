<?php

namespace App\Models;

use App\Support\Decimal as D;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'return_date',
        'notes',
        'return_preserves_original',
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
            'return_date' => 'date',
            'return_preserves_original' => 'boolean',
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

    public function settlements(): HasMany
    {
        return $this->hasMany(LedgerSettlement::class);
    }

    public function refundSettlements(): HasMany
    {
        return $this->hasMany(LedgerSettlement::class, 'return_invoice_id');
    }

    public function accountingDate()
    {
        return $this->isReturn() && $this->return_date ? $this->return_date : $this->created_at;
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeReturnAccountingPeriod(Builder $query, ?string $from = null, ?string $to = null): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->where(function (Builder $query) use ($from): void {
                $query->whereDate('return_date', '>=', $from)
                    ->orWhere(fn (Builder $query) => $query->whereNull('return_date')->whereDate('created_at', '>=', $from));
            }))
            ->when($to, fn (Builder $query) => $query->where(function (Builder $query) use ($to): void {
                $query->whereDate('return_date', '<=', $to)
                    ->orWhere(fn (Builder $query) => $query->whereNull('return_date')->whereDate('created_at', '<=', $to));
            }));
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function getBalanceAttribute(): string
    {
        return D::sub($this->collectibleTotal(), $this->effective_paid);
    }

    public function getEffectivePaidAttribute(): string
    {
        if ($this->isReturn()) {
            return D::scale($this->amount_paid);
        }

        $settlements = $this->relationLoaded('settlements') ? $this->settlements : $this->settlements()->get();
        $positiveType = $this->isPurchase() ? LedgerSettlement::TYPE_OUTGOING : LedgerSettlement::TYPE_INCOMING;
        $positive = D::sum($settlements->where('type', $positiveType)->pluck('amount'));
        $negative = D::sum($settlements->where('type', $positiveType === LedgerSettlement::TYPE_INCOMING
            ? LedgerSettlement::TYPE_OUTGOING
            : LedgerSettlement::TYPE_INCOMING)->pluck('amount'));

        return D::add($this->amount_paid, D::sub($positive, $negative));
    }

    public function collectibleTotal(): string
    {
        return $this->isSale() && $this->payable_total !== null
            ? (string) $this->payable_total
            : (string) $this->total;
    }

    public function getRemainingAttribute(): string
    {
        return D::scale(D::max(0, $this->balance));
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
