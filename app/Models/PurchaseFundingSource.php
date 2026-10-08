<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseFundingSource extends Model
{
    protected $fillable = ['invoice_id', 'money_party_id', 'amount_base_currency'];

    protected function casts(): array
    {
        return ['amount_base_currency' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function moneyParty(): BelongsTo
    {
        return $this->belongsTo(MoneyParty::class);
    }
}
