<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use App\Support\Decimal as D;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnService
{
    /**
     * A return reduces the original sale total and remaining line quantities.
     * The return invoice is an audit record only. Cash actually handed back is
     * stored as an outgoing settlement so the customer's net paid amount stays correct.
     */
    public function processSalesReturn(Invoice $originalInvoice, mixed $refundAmount, array $returnQuantities, ?string $requestKey = null): Invoice
    {
        if (! $originalInvoice->isSale()) {
            throw new Exception('يمكن إرجاع فواتير المبيعات فقط.');
        }
        if (D::cmp($refundAmount, 0) < 0) {
            throw new Exception('قيمة الاسترداد لا يمكن أن تكون سالبة.');
        }
        if ($requestKey !== null && ! Str::isUuid($requestKey)) {
            throw new Exception('طلب المرتجع غير صالح.');
        }
        $refundAmount = D::scale($refundAmount);

        [$invoice, $linesData, $replayed] = DB::transaction(function () use ($originalInvoice, $refundAmount, $returnQuantities, $requestKey): array {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($originalInvoice->id);
            if ($lockedInvoice->isCancelled()) {
                throw new Exception('لا يمكن إنشاء مرتجع لفاتورة ملغاة.');
            }
            if ($requestKey !== null) {
                $existing = Invoice::query()->where('return_request_key', $requestKey)->first();
                if ($existing) {
                    if ($existing->original_invoice_id !== $lockedInvoice->id) {
                        throw new Exception('طلب المرتجع غير صالح.');
                    }

                    return [$existing, [], true];
                }
            }
            $lines = InvoiceLine::query()->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->get();
            $originalSubtotalBefore = D::cmp($lockedInvoice->subtotal, 0) > 0 ? $lockedInvoice->subtotal : D::sum($lines->pluck('line_total'));
            $items = [];
            $subtotal = '0.00';
            $total = '0.00';

            foreach ($lines as $line) {
                $rawQuantity = $returnQuantities[$line->id] ?? 0;
                try {
                    $quantity = D::quantity($rawQuantity);
                } catch (\Throwable) {
                    throw new Exception('كمية المرتجع يجب أن تكون رقماً صالحاً حتى ثلاث منازل عشرية.');
                }
                if (D::cmp($quantity, 0) < 0) {
                    throw new Exception('كمية المرتجع غير صالحة.');
                }
                if (D::cmp($quantity, $line->quantity) > 0) {
                    throw new Exception("الكمية المرتجعة من {$line->product_name} أكبر من الكمية المتبقية في الفاتورة.");
                }
                if (D::cmp($quantity, 0) === 0) {
                    continue;
                }
                $lineTotal = D::cmp($quantity, $line->quantity) === 0 ? $line->line_total
                    : D::div(D::of($line->line_total)->multipliedBy($quantity), $line->quantity);
                $currentNet = $line->net_line_total ?? $line->line_total;
                $netLineTotal = D::cmp($quantity, $line->quantity) === 0
                    ? D::scale($currentNet)
                    : D::div(D::of($currentNet)->multipliedBy($quantity), $line->quantity);
                $lineDiscount = D::sub($lineTotal, $netLineTotal);
                $items[] = compact('line', 'quantity', 'lineTotal', 'lineDiscount', 'netLineTotal');
                $subtotal = D::add($subtotal, $lineTotal);
                $total = D::add($total, $netLineTotal);
            }

            if ($items === []) {
                throw new Exception('حدد كمية لمنتج واحد على الأقل.');
            }
            $subtotal = D::scale($subtotal);
            $total = D::scale($total);
            $discountAmount = D::sub($subtotal, $total);
            if (D::cmp($refundAmount, $total) > 0) {
                throw new Exception('الاسترداد النقدي لا يمكن أن يتجاوز إجمالي المرتجع.');
            }

            $debtBefore = D::max(0, D::sub($lockedInvoice->collectibleTotal(), $lockedInvoice->amount_paid));
            if ($lockedInvoice->partner_id) {
                $partner = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($lockedInvoice->partner_id);
                $debtBefore = app(FinancialService::class)->receivable($partner);
            }
            $maxRefund = D::max(0, D::sub($total, $debtBefore));
            if (D::cmp($refundAmount, $maxRefund) > 0) {
                throw new Exception('الاسترداد النقدي أكبر من المبلغ المستحق للعميل بعد تسوية الدين.');
            }

            $returnInvoice = Invoice::create([
                'partner_id' => $lockedInvoice->partner_id,
                'original_invoice_id' => $lockedInvoice->id,
                'type' => Invoice::TYPE_RETURN,
                'subtotal' => $subtotal,
                'discount_type' => D::cmp($discountAmount, 0) > 0 ? 'fixed' : null,
                'discount_value' => $discountAmount,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'initial_paid' => $refundAmount,
                'amount_paid' => $refundAmount,
                'return_request_key' => $requestKey,
            ]);

            $notificationLines = [];
            foreach ($items as $item) {
                $line = $item['line'];
                $quantity = $item['quantity'];
                $lineTotal = $item['lineTotal'];
                $lineDiscount = $item['lineDiscount'];
                $netLineTotal = $item['netLineTotal'];
                $product = Product::query()->lockForUpdate()->findOrFail($line->product_id);
                InvoiceLine::create([
                    'invoice_id' => $returnInvoice->id,
                    'product_id' => $product->id,
                    'product_name' => $line->product_name,
                    'quantity' => $quantity,
                    'unit_price' => $line->unit_price,
                    'purchase_price' => $line->purchase_price,
                    'sale_price' => $line->sale_price,
                    'line_total' => $lineTotal,
                    'discount_amount' => $lineDiscount,
                    'net_line_total' => $netLineTotal,
                ]);
                app(InventoryCostService::class)->restoreSaleStock($product, $quantity, $line->purchase_price);
                $line->update([
                    'quantity' => D::sub($line->quantity, $quantity, 3),
                    'line_total' => D::sub($line->line_total, $lineTotal),
                    'discount_amount' => D::sub($line->discount_amount, $lineDiscount),
                    'net_line_total' => D::sub($line->net_line_total ?? $line->line_total, $netLineTotal),
                ]);
                $notificationLines[] = [
                    'product_id' => $product->id,
                    'product_name' => $line->product_name,
                    'quantity' => $quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $netLineTotal,
                ];
            }

            $remainingDiscount = D::sub($lockedInvoice->discount_amount, $discountAmount);
            $lockedInvoice->update([
                'subtotal' => D::sub($originalSubtotalBefore, $subtotal),
                'discount_amount' => $remainingDiscount,
                'discount_value' => $lockedInvoice->discount_type === 'fixed' ? $remainingDiscount : $lockedInvoice->discount_value,
                'total' => D::sub($lockedInvoice->total, $total),
                'payable_total' => D::sub($lockedInvoice->collectibleTotal(), $total),
            ]);
            if (D::cmp($refundAmount, 0) > 0 && $lockedInvoice->partner_id) {
                LedgerSettlement::create([
                    'partner_id' => $lockedInvoice->partner_id,
                    'amount' => D::scale($refundAmount),
                    'type' => LedgerSettlement::TYPE_OUTGOING,
                    'settlement_date' => now()->toDateString(),
                    'notes' => "استرداد نقدي لمرتجع فاتورة #{$lockedInvoice->id}",
                ]);
            }

            return [$returnInvoice->load('partner'), $notificationLines, false];
        });

        if ($replayed) {
            return $invoice;
        }

        app(TelegramService::class)->sendInvoiceNotification(
            Invoice::TYPE_RETURN,
            $invoice->partner?->name ?? 'زبون',
            number_format((float) $invoice->total, 2),
            number_format($refundAmount, 2),
            D::format(D::sub($invoice->total, $refundAmount)),
            (string) $invoice->id,
            $linesData,
        );
        app(AlertService::class)->evaluateProducts(collect($linesData)->pluck('product_id')->filter());
        if ($invoice->partner_id) {
            app(AlertService::class)->evaluatePartners($invoice->partner_id);
        }

        return $invoice;
    }
}
