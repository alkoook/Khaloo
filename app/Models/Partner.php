<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_SUPPLIER = 'supplier';

    protected $fillable = [
        'name',
        'father_name',
        'nickname',
        'title',
        'mother_name',
        'national_id',
        'phone',
        'type',
        'image',
    ];

    /**
     * @return HasMany<Invoice>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(LedgerSettlement::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function isCustomer(): bool
    {
        return $this->type === self::TYPE_CUSTOMER;
    }

    public function isSupplier(): bool
    {
        return $this->type === self::TYPE_SUPPLIER;
    }
}
