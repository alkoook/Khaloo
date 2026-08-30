<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerSettlement extends Model
{
    public const TYPE_INCOMING = 'incoming';

    public const TYPE_OUTGOING = 'outgoing';

    protected $fillable = ['partner_id', 'amount', 'type', 'settlement_date', 'notes'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settlement_date' => 'date',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
