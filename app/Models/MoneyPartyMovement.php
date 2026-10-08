<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyPartyMovement extends Model
{
    public const DIRECTION_TO_PARTY = 'to_party';
    public const DIRECTION_FROM_PARTY = 'from_party';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const CATEGORY_PURCHASE_ADVANCE = 'purchase_advance';
    public const CATEGORY_PURCHASE_COMPENSATION = 'purchase_compensation';
    public const CATEGORY_OWNER_WITHDRAWAL = 'owner_withdrawal';
    public const CATEGORY_RETURNED_FROM_PARTY = 'returned_from_party';
    public const CATEGORY_OTHER = 'other';
    public const CATEGORY_UNCLASSIFIED = 'unclassified';

    protected $fillable = [
        'money_party_id', 'direction', 'category', 'amount', 'currency',
        'exchange_rate_snapshot', 'amount_base_currency', 'date', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'exchange_rate_snapshot' => 'decimal:6',
            'amount_base_currency' => 'decimal:2',
            'date' => 'date',
        ];
    }

    public function moneyParty(): BelongsTo
    {
        return $this->belongsTo(MoneyParty::class);
    }
}
