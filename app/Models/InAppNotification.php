<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InAppNotification extends Model
{
    protected $table = 'notifications';
    protected $fillable = ['type', 'title', 'message', 'severity', 'data', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    public function markRead(): void
    {
        if (! $this->read_at) $this->update(['read_at' => now()]);
    }

    public function targetUrl(): string
    {
        if ($productId = data_get($this->data, 'product_id')) return route('products.edit', $productId);
        if (data_get($this->data, 'partner_id')) return route('ledger.index');
        return route('notifications.index');
    }
}
