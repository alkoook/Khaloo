<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MoneyParty extends Model
{
    protected $fillable = ['name', 'notes'];

    public function movements(): HasMany
    {
        return $this->hasMany(MoneyPartyMovement::class);
    }

    public function purchaseFundingSources(): HasMany
    {
        return $this->hasMany(PurchaseFundingSource::class);
    }
}
