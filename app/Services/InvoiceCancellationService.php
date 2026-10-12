<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use App\Support\Decimal as D;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCancellationService
{
    public function blockReason(Invoice $invoice): ?string
    {
        if ($invoice->isCancelled()) {
            return 'هذه الفاتورة ملغاة مسبقًا ولم يُكرر عكس آثارها.';
        }
        if (! in_array($invoice->type, [Invoice::TYPE_SALE, Invoice::TYPE_PURCHASE], true)) {
            return 'لا يمكن إلغاء هذا النوع من المستندات من هنا.';
        }
        if ($invoice->partner_id && LedgerSettlement::query()
            ->where('partner_id', $invoice->partner_id)
            ->whereDate('settlement_date', '>=', $invoice->created_at->toDateString())->exists()) {
            return 'لا يمكن إلغاء الفاتورة بعد تسجيل تسوية لاحقة للطرف.';
        }
        if ($invoice->isSale()) {
            return $invoice->returns()->active()->exists()
                ? 'لا يمكن إلغاء فاتورة بيع لها مرتجعات مسجلة.'
                : null;
        }

        $lines = $invoice->lines()->get(['product_id', 'quantity', 'net_line_total', 'line_total']);
        $productIds = $lines->pluck('product_id')->filter()->unique();
        if (InvoiceLine::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereIn('invoice_lines.product_id', $productIds)
            ->where('invoices.id', '>', $invoice->id)
            ->where('invoices.status', Invoice::STATUS_ACTIVE)
            ->exists()) {
            return 'لا يمكن إلغاء فاتورة الشراء لوجود حركات مخزون لاحقة.';
        }

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        foreach ($lines->groupBy('product_id') as $productId => $productLines) {
            $product = $products->get($productId);
            $quantity = D::sum($productLines->pluck('quantity'), 3);
            if (! $product || D::cmp($product->quantity, $quantity) < 0) {
                return 'لا يمكن إلغاء الفاتورة لأن جزءًا من الكمية تم بيعه أو استهلاكه.';
            }
            if ($product->purchase_price === null && D::cmp($product->quantity, $quantity) > 0) {
                return "لا يمكن إعادة احتساب تكلفة مخزون «{$product->name}» لأن تكلفته الحالية غير موثقة.";
            }
        }

        return null;
    }

    /** @return Collection<int, int> */
    public function cancel(Invoice $invoice, int $userId, string $reason): Collection
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['cancellationReason' => 'يرجى كتابة سبب واضح للإلغاء.']);
        }

        return DB::transaction(function () use ($invoice, $userId, $reason): Collection {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($blockReason = $this->blockReason($locked)) {
                throw ValidationException::withMessages(['cancellationReason' => $blockReason]);
            }

            $lines = InvoiceLine::query()->where('invoice_id', $locked->id)->lockForUpdate()->get();
            $productIds = $lines->pluck('product_id')->filter()->unique()->values();
            $products = Product::query()->whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');

            if ($locked->isSale()) {
                if ($locked->returns()->active()->exists()) {
                    throw ValidationException::withMessages(['cancellationReason' => 'لا يمكن إلغاء فاتورة بيع لها مرتجعات مسجلة.']);
                }
                foreach ($lines as $line) {
                    if ($line->product_id && $products->has($line->product_id)) {
                        $product = $products->get($line->product_id);
                        app(InventoryCostService::class)->restoreSaleStock($product, $line->quantity, $line->purchase_price);
                    }
                }
            } else {
                $hasLaterMovement = InvoiceLine::query()
                    ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                    ->whereIn('invoice_lines.product_id', $productIds)
                    ->where('invoices.id', '>', $locked->id)
                    ->where('invoices.status', Invoice::STATUS_ACTIVE)
                    ->exists();
                if ($hasLaterMovement) {
                    throw ValidationException::withMessages(['cancellationReason' => 'لا يمكن إلغاء فاتورة الشراء بعد وجود حركة مخزون لاحقة لأحد أصنافها.']);
                }
                foreach ($lines as $line) {
                    if (! $line->product_id) {
                        continue;
                    }
                    $product = $products->get($line->product_id);
                    if (! $product || D::cmp($product->quantity, $line->quantity) < 0) {
                        throw ValidationException::withMessages(['cancellationReason' => 'لا يمكن إلغاء فاتورة الشراء لأن جزءًا من كمياتها لم يعد متوفرًا.']);
                    }
                    if ($product->purchase_price === null && D::cmp($product->quantity, $line->quantity) > 0) {
                        throw ValidationException::withMessages(['cancellationReason' => "لا يمكن إعادة احتساب تكلفة مخزون «{$product->name}» لأن تكلفته الحالية غير موثقة."]);
                    }
                    $remainingQuantity = D::sub($product->quantity, $line->quantity, 3);
                    $remainingValue = D::of($product->quantity)->multipliedBy($product->purchase_price ?? 0)->minus($line->net_line_total ?? $line->line_total);
                    $product->update([
                        'quantity' => $remainingQuantity,
                        'purchase_price' => D::cmp($remainingQuantity, 0) > 0 ? D::max(0, D::div($remainingValue, $remainingQuantity, 4)) : null,
                    ]);
                }
            }

            $locked->update([
                'status' => Invoice::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            return $productIds;
        }, 3);
    }
}
