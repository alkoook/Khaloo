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

    /** Reverse part of an earlier return using that return line's historical cost layer. */
    public function removeRestoredSaleStock(Product $product, mixed $quantity, mixed $historicalCost): void
    {
        $quantity = D::quantity($quantity);
        if (D::cmp($quantity, 0) <= 0) {
            return;
        }
        if (D::cmp($product->quantity, $quantity) < 0) {
            throw new \App\Exceptions\BusinessRuleException(
                "لا يمكن تخفيض المرتجع لأن مخزون «{$product->name}» المتاح أقل من فرق الكمية المطلوب سحبه."
            );
        }

        $newQuantity = D::sub($product->quantity, $quantity, 3);
        $newCost = $product->purchase_price;
        if (D::cmp($newQuantity, 0) > 0 && $product->purchase_price !== null && $historicalCost !== null) {
            $remainingValue = D::of($product->quantity)->multipliedBy($product->purchase_price)
                ->minus(D::of($quantity)->multipliedBy($historicalCost));
            if ($remainingValue->isNegative()) {
                throw new \App\Exceptions\BusinessRuleException(
                    "لا يمكن تصحيح مرتجع «{$product->name}» لأن قيمة المخزون التاريخية لا تسمح بعكس هذه الكمية بأمان."
                );
            }
            $newCost = D::div($remainingValue, $newQuantity, 4);
        }

        $product->update(['quantity' => $newQuantity, 'purchase_price' => $newCost]);
    }
}
