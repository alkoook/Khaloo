<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use Exception;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function updateInvoice(Invoice $invoice, array $newLines, float $newAmountPaid): Invoice
    {
        if ($invoice->isReturn()) throw new Exception('لا يمكن تعديل فاتورة مرتجع مباشرة.');
        $ids = array_map(fn (array $line) => (int) $line['product_id'], $newLines);
        if (count($ids) !== count(array_unique($ids))) throw new Exception('لا يمكن تكرار المنتج في الفاتورة.');

        $updated = DB::transaction(function () use ($invoice, $newLines, $newAmountPaid): Invoice {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($lockedInvoice->isSale() && $lockedInvoice->returns()->exists()) {
                throw new Exception('لا يمكن تعديل فاتورة بيع لها مرتجعات مسجلة.');
            }
            $oldLines = InvoiceLine::query()->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->get();
            $productIds = collect($newLines)->pluck('product_id')->merge($oldLines->pluck('product_id'))->filter()->unique();
            $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
            $oldQuantities = $oldLines->groupBy('product_id')->map->sum('quantity');
            $newQuantities = collect($newLines)->groupBy('product_id')->map(fn ($lines) => collect($lines)->sum('quantity'));

            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                if (! $product) throw new Exception('أحد المنتجات لم يعد موجودًا.');
                $old = (int) ($oldQuantities[$productId] ?? 0);
                $new = (int) ($newQuantities[$productId] ?? 0);
                $stock = $lockedInvoice->isSale()
                    ? $product->quantity + $old - $new
                    : $product->quantity - $old + $new;
                if ($stock < 0) throw new Exception("لا يمكن حفظ التعديل لأن مخزون «{$product->name}» لن يكون كافيًا.");
                $product->update(['quantity' => $stock]);
            }

            $total = round(collect($newLines)->sum(fn (array $line) => (int) $line['quantity'] * (float) $line['unit_price']), 2);
            if ($newAmountPaid > $total) throw new Exception('المبلغ المدفوع لا يمكن أن يتجاوز إجمالي الفاتورة.');

            $lockedInvoice->lines()->delete();
            foreach ($newLines as $line) {
                $product = $products->get((int) $line['product_id']);
                $quantity = (int) $line['quantity'];
                $unitPrice = (float) $line['unit_price'];
                InvoiceLine::create([
                    'invoice_id' => $lockedInvoice->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name ?? 'منتج',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'purchase_price' => (float) $product->purchase_price,
                    'sale_price' => filled($line['sale_price'] ?? null) ? (float) $line['sale_price'] : null,
                    'line_total' => round($quantity * $unitPrice, 2),
                ]);
                if ($lockedInvoice->isPurchase() && filled($line['sale_price'] ?? null)) {
                    $product->update(['sale_price' => (float) $line['sale_price']]);
                }
            }

            $lockedInvoice->update([
                'total' => $total,
                'initial_paid' => $newAmountPaid,
                'amount_paid' => $newAmountPaid,
            ]);

            return $lockedInvoice->refresh()->load('lines');
        });
        app(AlertService::class)->evaluateProducts($updated->lines->pluck('product_id')->filter());
        if ($updated->partner_id) app(AlertService::class)->evaluatePartners($updated->partner_id);
        return $updated;
    }
}
