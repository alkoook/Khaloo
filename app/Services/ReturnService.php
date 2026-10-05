<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
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
    public function processSalesReturn(Invoice $originalInvoice, float $refundAmount, array $returnQuantities, ?string $requestKey = null): Invoice
    {
        if (! $originalInvoice->isSale()) {
            throw new Exception('يمكن إرجاع فواتير المبيعات فقط.');
        }
        if (! is_finite($refundAmount) || $refundAmount < 0) {
            throw new Exception('قيمة الاسترداد لا يمكن أن تكون سالبة.');
        }
        if ($requestKey !== null && ! Str::isUuid($requestKey)) {
            throw new Exception('طلب المرتجع غير صالح.');
        }
        $refundAmount = round($refundAmount, 2);

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
            $originalSubtotalBefore = (float) $lockedInvoice->subtotal > 0 ? (float) $lockedInvoice->subtotal : (float) $lines->sum('line_total');
            $items = [];
            $subtotal = 0.0;
            $total = 0.0;

            foreach ($lines as $line) {
                $rawQuantity = $returnQuantities[$line->id] ?? 0;
                if (filter_var($rawQuantity, FILTER_VALIDATE_INT) === false) {
                    throw new Exception('كمية المرتجع يجب أن تكون عددًا صحيحًا.');
                }
                $quantity = (int) $rawQuantity;
                if ($quantity < 0) {
                    throw new Exception('كمية المرتجع غير صالحة.');
                }
                if ($quantity > $line->quantity) {
                    throw new Exception("الكمية المرتجعة من {$line->product_name} أكبر من الكمية المتبقية في الفاتورة.");
                }
                if ($quantity === 0) {
                    continue;
                }
                $lineTotal = round($quantity * (float) $line->unit_price, 2);
                $currentNet = $line->net_line_total === null ? (float) $line->line_total : (float) $line->net_line_total;
                $netLineTotal = $quantity === (int) $line->quantity
                    ? round($currentNet, 2)
                    : round($currentNet * $quantity / (int) $line->quantity, 2);
                $lineDiscount = round($lineTotal - $netLineTotal, 2);
                $items[] = compact('line', 'quantity', 'lineTotal', 'lineDiscount', 'netLineTotal');
                $subtotal += $lineTotal;
                $total += $netLineTotal;
            }

            if ($items === []) {
                throw new Exception('حدد كمية لمنتج واحد على الأقل.');
            }
            $subtotal = round($subtotal, 2);
            $total = round($total, 2);
            $discountAmount = round($subtotal - $total, 2);
            if ($refundAmount > $total) {
                throw new Exception('الاسترداد النقدي لا يمكن أن يتجاوز إجمالي المرتجع.');
            }

            $debtBefore = max(0, round((float) $lockedInvoice->total - (float) $lockedInvoice->amount_paid, 2));
            if ($lockedInvoice->partner_id) {
                $partner = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($lockedInvoice->partner_id);
                $debtBefore = max(0, round((float) $partner->sales_due - (float) $partner->incoming_total + (float) $partner->outgoing_total, 2));
            }
            $maxRefund = max(0, round($total - $debtBefore, 2));
            if ($refundAmount > $maxRefund) {
                throw new Exception('الاسترداد النقدي أكبر من المبلغ المستحق للعميل بعد تسوية الدين.');
            }

            $returnInvoice = Invoice::create([
                'partner_id' => $lockedInvoice->partner_id,
                'original_invoice_id' => $lockedInvoice->id,
                'type' => Invoice::TYPE_RETURN,
                'subtotal' => $subtotal,
                'discount_type' => $discountAmount > 0 ? 'fixed' : null,
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
                $product->increment('quantity', $quantity);
                $line->update([
                    'quantity' => $line->quantity - $quantity,
                    'line_total' => round((float) $line->line_total - $lineTotal, 2),
                    'discount_amount' => round((float) $line->discount_amount - $lineDiscount, 2),
                    'net_line_total' => round(($line->net_line_total === null ? (float) $line->line_total : (float) $line->net_line_total) - $netLineTotal, 2),
                ]);
                $notificationLines[] = [
                    'product_id' => $product->id,
                    'product_name' => $line->product_name,
                    'quantity' => $quantity,
                    'unit_price' => (float) $line->unit_price,
                    'line_total' => $netLineTotal,
                ];
            }

            $remainingDiscount = round((float) $lockedInvoice->discount_amount - $discountAmount, 2);
            $lockedInvoice->update([
                'subtotal' => round($originalSubtotalBefore - $subtotal, 2),
                'discount_amount' => $remainingDiscount,
                'discount_value' => $lockedInvoice->discount_type === 'fixed' ? $remainingDiscount : $lockedInvoice->discount_value,
                'total' => round((float) $lockedInvoice->total - $total, 2),
            ]);
            if ($refundAmount > 0 && $lockedInvoice->partner_id) {
                LedgerSettlement::create([
                    'partner_id' => $lockedInvoice->partner_id,
                    'amount' => round($refundAmount, 2),
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
            number_format((float) $invoice->total - $refundAmount, 2),
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
