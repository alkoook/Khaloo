<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LedgerSettlement extends Model
{
    public const TYPE_INCOMING = 'incoming';

    public const TYPE_OUTGOING = 'outgoing';

    protected $fillable = [
        'partner_id', 'invoice_id', 'return_invoice_id', 'amount', 'type', 'payment_method', 'settlement_date', 'notes',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('active_settlement', fn ($query) => $query->whereNull($query->qualifyColumn('cancelled_at')));
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settlement_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function returnInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'return_invoice_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    public function scopeWithCancelled($query)
    {
        return $query->withoutGlobalScope('active_settlement');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
