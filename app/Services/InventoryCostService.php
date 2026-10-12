<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Decimal as D;

class InventoryCostService
{
    /** Caller holds the product row lock. Restore at the sale's historical cost, not today's cost. */
    public function restoreSaleStock(Product $product, mixed $quantity, mixed $historicalCost): void
    {
        $remaining = $product->quantity;
        $newQuantity = D::add($remaining, $quantity, 3);
        $known = $historicalCost !== null && (D::cmp($remaining, 0) === 0 || $product->purchase_price !== null);
        $newCost = $known ? D::div(
            D::of($remaining)->multipliedBy($product->purchase_price ?? 0)
                ->plus(D::of($quantity)->multipliedBy($historicalCost)),
            $newQuantity,
            4,
        ) : null;
        $product->update(['quantity' => $newQuantity, 'purchase_price' => $newCost]);
    }
}
