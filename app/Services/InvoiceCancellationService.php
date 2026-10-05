<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCancellationService
{
    /** @return Collection<int, int> */
    public function cancel(Invoice $invoice, int $userId, string $reason): Collection
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['cancellationReason' => 'يرجى كتابة سبب واضح للإلغاء.']);
        }

        return DB::transaction(function () use ($invoice, $userId, $reason): Collection {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['cancellationReason' => 'هذه الفاتورة ملغاة مسبقًا ولم يُكرر عكس آثارها.']);
            }
            if (! in_array($locked->type, [Invoice::TYPE_SALE, Invoice::TYPE_PURCHASE], true)) {
                throw ValidationException::withMessages(['cancellationReason' => 'لا يمكن إلغاء هذا النوع من المستندات من هنا.']);
            }
            if ($locked->partner_id && LedgerSettlement::query()
                ->where('partner_id', $locked->partner_id)
                ->whereDate('settlement_date', '>=', $locked->created_at->toDateString())->exists()) {
                throw ValidationException::withMessages(['cancellationReason' => 'لا يمكن إلغاء الفاتورة بعد تسجيل تسوية لاحقة للطرف.']);
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
                        $products->get($line->product_id)->increment('quantity', $line->quantity);
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
                    if (! $product || $product->quantity < $line->quantity) {
                        throw ValidationException::withMessages(['cancellationReason' => 'لا يمكن إلغاء فاتورة الشراء لأن جزءًا من كمياتها لم يعد متوفرًا.']);
                    }
                    if ($product->purchase_price === null && $product->quantity > $line->quantity) {
                        throw ValidationException::withMessages(['cancellationReason' => "لا يمكن إعادة احتساب تكلفة مخزون «{$product->name}» لأن تكلفته الحالية غير موثقة."]);
                    }
                    $remainingQuantity = $product->quantity - $line->quantity;
                    $remainingValue = round(($product->quantity * (float) $product->purchase_price) - (float) ($line->net_line_total ?? $line->line_total), 4);
                    $product->update([
                        'quantity' => $remainingQuantity,
                        'purchase_price' => $remainingQuantity > 0 ? max(0, round($remainingValue / $remainingQuantity, 4)) : null,
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
