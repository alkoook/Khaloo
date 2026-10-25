<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnService
{
    /**
     * A return is an independent historical document. It restores stock and credits
     * the customer ledger, but never rewrites the issued sale or its lines.
     */
    public function processSalesReturn(Invoice $originalInvoice, mixed $refundAmount, array $returnQuantities, ?string $requestKey = null): Invoice
    {
        if (! $originalInvoice->isSale()) {
            throw new BusinessRuleException('يمكن إرجاع فواتير المبيعات فقط.');
        }
        if (D::cmp($refundAmount, 0) < 0) {
            throw new BusinessRuleException('قيمة الاسترداد لا يمكن أن تكون سالبة.');
        }
        if ($requestKey !== null && ! Str::isUuid($requestKey)) {
            throw new BusinessRuleException('طلب المرتجع غير صالح.');
        }
        $refundAmount = D::scale($refundAmount);

        [$invoice, $linesData, $replayed] = DB::transaction(function () use ($originalInvoice, $refundAmount, $returnQuantities, $requestKey): array {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($originalInvoice->id);
            if ($lockedInvoice->isCancelled()) {
                throw new BusinessRuleException('لا يمكن إنشاء مرتجع لفاتورة ملغاة.');
            }
            if ($requestKey !== null) {
                $existing = Invoice::query()->where('return_request_key', $requestKey)->first();
                if ($existing) {
                    if ($existing->original_invoice_id !== $lockedInvoice->id) {
                        throw new BusinessRuleException('طلب المرتجع غير صالح.');
                    }

                    return [$existing, [], true];
                }
            }
            $lines = InvoiceLine::query()->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->get();
            $independentReturnLines = Invoice::query()->active()
                ->where('original_invoice_id', $lockedInvoice->id)
                ->where('type', Invoice::TYPE_RETURN)
                ->where('return_preserves_original', true)
                ->with('lines:id,invoice_id,product_id,quantity,line_total,net_line_total')
                ->get()->flatMap->lines;
            $independentReturned = $independentReturnLines->groupBy('product_id')
                ->map(fn ($returnLines): string => D::sum($returnLines->pluck('quantity'), 3));
            $items = [];
            $subtotal = '0.00';
            $total = '0.00';

            foreach ($lines as $line) {
                $rawQuantity = $returnQuantities[$line->id] ?? 0;
                try {
                    $quantity = D::quantity($rawQuantity);
                } catch (\Throwable) {
                    throw new BusinessRuleException('كمية المرتجع يجب أن تكون رقماً صالحاً حتى ثلاث منازل عشرية.');
                }
                if (D::cmp($quantity, 0) < 0) {
                    throw new BusinessRuleException('كمية المرتجع غير صالحة.');
                }
                $availableQuantity = D::sub($line->quantity, $independentReturned[$line->product_id] ?? 0, 3);
                if (D::cmp($quantity, $availableQuantity) > 0) {
                    throw new BusinessRuleException("لا يمكن تنفيذ هذا المرتجع لأن كمية «{$line->product_name}» المطلوب إرجاعها أكبر من الكمية المتبقية القابلة للإرجاع.");
                }
                if (D::cmp($quantity, 0) === 0) {
                    continue;
                }
                $currentNet = $line->net_line_total ?? $line->line_total;
                $previousForProduct = $independentReturnLines->where('product_id', $line->product_id);
                $remainingGross = D::sub($line->line_total, D::sum($previousForProduct->pluck('line_total')));
                $remainingNet = D::sub($currentNet, D::sum($previousForProduct->map(fn ($returnLine) => $returnLine->net_line_total ?? $returnLine->line_total)));
                $lineTotal = D::cmp($quantity, $availableQuantity) === 0
                    ? D::scale($remainingGross)
                    : D::min($remainingGross, D::div(D::of($line->line_total)->multipliedBy($quantity), $line->quantity));
                $netLineTotal = D::cmp($quantity, $availableQuantity) === 0
                    ? D::scale($remainingNet)
                    : D::div(D::of($currentNet)->multipliedBy($quantity), $line->quantity);
                $lineDiscount = D::sub($lineTotal, $netLineTotal);
                $items[] = compact('line', 'quantity', 'lineTotal', 'lineDiscount', 'netLineTotal');
                $subtotal = D::add($subtotal, $lineTotal);
                $total = D::add($total, $netLineTotal);
            }

            if ($items === []) {
                throw new BusinessRuleException('حدد كمية لمنتج واحد على الأقل.');
            }
            $subtotal = D::scale($subtotal);
            $total = D::scale($total);
            $discountAmount = D::sub($subtotal, $total);
            if (D::cmp($refundAmount, $total) > 0) {
                throw new BusinessRuleException('الاسترداد النقدي لا يمكن أن يتجاوز إجمالي المرتجع.');
            }

            $debtBefore = D::max(0, D::sub($lockedInvoice->collectibleTotal(), $lockedInvoice->amount_paid));
            if ($lockedInvoice->partner_id) {
                $partner = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($lockedInvoice->partner_id);
                $debtBefore = app(FinancialService::class)->receivable($partner);
            }
            $maxRefund = D::max(0, D::sub($total, $debtBefore));
            if (D::cmp($refundAmount, $maxRefund) > 0) {
                throw new BusinessRuleException('الاسترداد النقدي أكبر من المبلغ المستحق للسيد بعد تسوية الدين.');
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
                'return_preserves_original' => true,
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
                $notificationLines[] = [
                    'product_id' => $product->id,
                    'product_name' => $line->product_name,
                    'quantity' => $quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $netLineTotal,
                ];
            }

            $returnInvoice->audits()->create([
                'event' => 'created',
                'user_id' => auth()->id(),
                'new_values' => [
                    'accounting_mode' => 'independent_return',
                    'original_invoice_id' => $lockedInvoice->id,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'cash_refund' => $refundAmount,
                ],
            ]);
            if (D::cmp($refundAmount, 0) > 0 && $lockedInvoice->partner_id) {
                $refund = LedgerSettlement::create([
                    'partner_id' => $lockedInvoice->partner_id,
                    'invoice_id' => $lockedInvoice->id,
                    'amount' => D::scale($refundAmount),
                    'type' => LedgerSettlement::TYPE_OUTGOING,
                    'payment_method' => 'cash',
                    'settlement_date' => now()->toDateString(),
                    'notes' => "استرداد نقدي لمرتجع فاتورة #{$lockedInvoice->id}",
                ]);
                $refund->audits()->create([
                    'event' => 'created',
                    'user_id' => auth()->id(),
                    'new_values' => $refund->only(['partner_id', 'invoice_id', 'amount', 'type', 'payment_method', 'settlement_date', 'notes']),
                ]);
            }

            return [$returnInvoice->load('partner'), $notificationLines, false];
        });

        if ($replayed) {
            return $invoice;
        }

        app(TelegramService::class)->sendInvoiceNotification(
            Invoice::TYPE_RETURN,
            $invoice->partner?->name ?? 'السيد النقدي',
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
