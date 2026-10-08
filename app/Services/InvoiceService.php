<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use Exception;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private readonly InvoicePricingService $pricing) {}

    public function editBlockReason(Invoice $invoice): ?string
    {
        if ($invoice->isCancelled()) {
            return 'لا يمكن تعديل فاتورة ملغاة.';
        }
        if ($invoice->isReturn()) {
            return 'لا يمكن تعديل فاتورة مرتجع مباشرة.';
        }
        if ($invoice->returns()->exists()) {
            return 'لا يمكن تعديل الفاتورة لوجود مرتجعات مسجلة.';
        }
        if ($invoice->partner_id && LedgerSettlement::query()
            ->where('partner_id', $invoice->partner_id)
            ->whereDate('settlement_date', '>=', $invoice->created_at->toDateString())->exists()) {
            return 'لا يمكن تعديل الفاتورة بعد وجود تسوية لاحقة للطرف.';
        }
        if ($invoice->isPurchase() && $invoice->purchaseFundingSource()->exists()) {
            return 'لا يمكن تعديل فاتورة شراء ممولة من جهة مال بعد تسجيل التمويل.';
        }
        if (! $invoice->isPurchase()) {
            return null;
        }

        $lines = $invoice->lines()->get(['product_id', 'quantity']);
        $productIds = $lines->pluck('product_id')->filter()->unique();
        if (InvoiceLine::query()->whereIn('product_id', $productIds)->where('invoice_id', '>', $invoice->id)->exists()) {
            return 'لا يمكن تعديل الفاتورة لوجود حركات مخزون لاحقة.';
        }

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        foreach ($lines->groupBy('product_id') as $productId => $productLines) {
            $product = $products->get($productId);
            if (! $product || $product->quantity < $productLines->sum('quantity')) {
                return 'لا يمكن تعديل الفاتورة لأن جزءًا من الكمية تم بيعه أو استهلاكه.';
            }
        }

        return null;
    }

    public function updateInvoice(Invoice $invoice, array $newLines, float $newAmountPaid, ?string $discountType = null, mixed $discountValue = null, array $additionalLines = [], float $deliveryAmount = 0): Invoice
    {
        if ($invoice->isReturn()) {
            throw new Exception('لا يمكن تعديل فاتورة مرتجع مباشرة.');
        }
        $ids = array_map(fn (array $line) => (int) $line['product_id'], $newLines);
        if (count($ids) !== count(array_unique($ids))) {
            throw new Exception('لا يمكن تكرار المنتج في الفاتورة.');
        }
        $calculation = $this->pricing->calculate($newLines, $discountType, $discountValue);
        $deliveryAmount = $invoice->isSale() ? round($deliveryAmount, 2) : 0.0;
        if (! is_finite($deliveryAmount) || $deliveryAmount < 0) {
            throw new Exception('قيمة التوصيل غير صالحة.');
        }
        $normalizedAdditionalLines = [];
        foreach ($invoice->isSale() ? $additionalLines : [] as $line) {
            $description = trim((string) ($line['description'] ?? ''));
            $quantity = round((float) ($line['quantity'] ?? 0), 3);
            $unitAmount = round((float) ($line['unit_amount'] ?? 0), 2);
            if ($description === '' || ! is_finite($quantity) || $quantity <= 0 || ! is_finite($unitAmount) || $unitAmount < 0) {
                throw new Exception('بيانات إحدى الخانات الإضافية غير صالحة.');
            }
            $normalizedAdditionalLines[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
                'row_total' => round($quantity * $unitAmount, 2),
                'source_note' => filled($line['source_note'] ?? null) ? trim((string) $line['source_note']) : null,
            ];
        }
        $additionalTotal = round((float) collect($normalizedAdditionalLines)->sum('row_total'), 2);
        $payableTotal = round((float) $calculation['total'] + $deliveryAmount + $additionalTotal, 2);
        if ($calculation['total'] <= 0) {
            throw new Exception('يجب أن يكون الإجمالي بعد الحسم أكبر من صفر.');
        }
        if ($newAmountPaid < 0 || $newAmountPaid > $payableTotal) {
            throw new Exception('المبلغ المدفوع يجب أن يكون بين صفر والإجمالي بعد الحسم.');
        }

        $updated = DB::transaction(function () use ($invoice, $calculation, $newAmountPaid, $normalizedAdditionalLines, $deliveryAmount, $additionalTotal, $payableTotal): Invoice {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($reason = $this->editBlockReason($lockedInvoice)) {
                throw new Exception($reason);
            }

            $oldLines = InvoiceLine::query()->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->get();
            $productIds = collect($calculation['lines'])->pluck('product_id')->merge($oldLines->pluck('product_id'))->filter()->unique();
            $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
            if ($lockedInvoice->isPurchase() && InvoiceLine::query()->whereIn('product_id', $productIds)->where('invoice_id', '>', $lockedInvoice->id)->exists()) {
                throw new Exception('لا يمكن تعديل فاتورة الشراء بعد وجود حركة مخزون لاحقة لأحد أصنافها.');
            }

            $oldQuantities = $oldLines->groupBy('product_id')->map->sum('quantity');
            $oldNetValues = $oldLines->groupBy('product_id')->map(fn ($lines) => $lines->sum(fn ($line) => (float) ($line->net_line_total ?? $line->line_total)));
            $stockBefore = $products->map(fn ($product) => ['quantity' => (int) $product->quantity, 'cost' => $product->purchase_price === null ? null : (float) $product->purchase_price]);
            $newQuantities = collect($calculation['lines'])->groupBy('product_id')->map(fn ($lines) => collect($lines)->sum('quantity'));
            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                if (! $product) {
                    throw new Exception('أحد المنتجات لم يعد موجودًا.');
                }
                $old = (int) ($oldQuantities[$productId] ?? 0);
                $new = (int) ($newQuantities[$productId] ?? 0);
                $stock = $lockedInvoice->isSale() ? $product->quantity + $old - $new : $product->quantity - $old + $new;
                if ($stock < 0) {
                    throw new Exception("لا يمكن حفظ التعديل لأن مخزون «{$product->name}» لن يكون كافيًا.");
                }
                if ($lockedInvoice->isSale() && $new > 0 && $product->purchase_price === null) {
                    throw new Exception("لا يمكن بيع «{$product->name}» دون تكلفة شراء موثوقة.");
                }
                $product->update(['quantity' => $stock]);
            }

            $oldCostSnapshots = $oldLines->keyBy('product_id');
            $lockedInvoice->lines()->delete();
            foreach ($calculation['lines'] as $line) {
                $product = $products->get((int) $line['product_id']);
                $quantity = (int) $line['quantity'];
                $purchasePrice = $lockedInvoice->isPurchase()
                    ? round((float) $line['net_line_total'] / $quantity, 4)
                    : ($oldCostSnapshots->get($product->id)?->purchase_price ?? $product->purchase_price);
                InvoiceLine::query()->create([
                    'invoice_id' => $lockedInvoice->id, 'product_id' => $product->id, 'product_name' => $product->name ?? 'منتج',
                    'quantity' => $quantity, 'unit_price' => $line['unit_price'], 'purchase_price' => $purchasePrice,
                    'sale_price' => filled($line['sale_price'] ?? null) ? (float) $line['sale_price'] : null,
                    'line_total' => $line['line_total'], 'discount_amount' => $line['discount_amount'], 'net_line_total' => $line['net_line_total'],
                ]);
                if ($lockedInvoice->isPurchase()) {
                    $before = $stockBefore->get($product->id);
                    $baseValue = ($before['cost'] === null ? 0 : (int) $before['quantity'] * (float) $before['cost']) - (float) ($oldNetValues[$product->id] ?? 0);
                    $averageCost = $product->quantity > 0 ? round((max(0, $baseValue) + (float) $line['net_line_total']) / $product->quantity, 4) : null;
                    $product->update(array_filter(['purchase_price' => $averageCost, 'sale_price' => filled($line['sale_price'] ?? null) ? (float) $line['sale_price'] : null], fn ($value) => $value !== null));
                }
            }

            $lockedInvoice->additionalLines()->delete();
            foreach ($normalizedAdditionalLines as $line) {
                $lockedInvoice->additionalLines()->create($line);
            }

            $lockedInvoice->update([
                'subtotal' => $calculation['subtotal'], 'discount_type' => $calculation['discount_type'],
                'discount_value' => $calculation['discount_value'], 'discount_amount' => $calculation['discount_amount'],
                'total' => $calculation['total'], 'delivery_amount' => $deliveryAmount,
                'additional_total' => $additionalTotal, 'payable_total' => $lockedInvoice->isSale() ? $payableTotal : null,
                'initial_paid' => $newAmountPaid, 'amount_paid' => $newAmountPaid,
            ]);

            return $lockedInvoice->refresh()->load('lines', 'additionalLines');
        }, 3);

        app(AlertService::class)->evaluateProducts($updated->lines->pluck('product_id')->filter());
        if ($updated->partner_id) {
            app(AlertService::class)->evaluatePartners($updated->partner_id);
        }

        return $updated;
    }
}
