<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'partner_id',
        'name',
        'description',
        'purchase_price',
        'sale_price',
        'quantity',
        'alert_stock_at',
        'country_origin',
        'image',
        'total_purchase_amount',
        'paid_purchase_amount',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'sale_price' => 'decimal:2',
            'quantity' => 'integer',
            'alert_stock_at' => 'integer',
            'total_purchase_amount' => 'decimal:2',
            'paid_purchase_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<InvoiceLine>
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function quotationLines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }
}
