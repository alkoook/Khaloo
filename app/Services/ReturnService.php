<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use Exception;
use Illuminate\Support\Facades\DB;

class ReturnService
{
    /**
     * A return reduces the original sale total and remaining line quantities.
     * The return invoice is an audit record only. Cash actually handed back is
     * stored as an outgoing settlement so the customer's net paid amount stays correct.
     */
    public function processSalesReturn(Invoice $originalInvoice, float $refundAmount, array $returnQuantities): Invoice
    {
        if (! $originalInvoice->isSale()) throw new Exception('يمكن إرجاع فواتير المبيعات فقط.');
        if ($refundAmount < 0) throw new Exception('قيمة الاسترداد لا يمكن أن تكون سالبة.');

        [$invoice, $linesData] = DB::transaction(function () use ($originalInvoice, $refundAmount, $returnQuantities): array {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($originalInvoice->id);
            $lines = InvoiceLine::query()->where('invoice_id', $lockedInvoice->id)->lockForUpdate()->get();
            $items = [];
            $total = 0.0;

            foreach ($lines as $line) {
                $quantity = (int) ($returnQuantities[$line->id] ?? 0);
                if ($quantity < 0) throw new Exception('كمية المرتجع غير صالحة.');
                if ($quantity > $line->quantity) throw new Exception("الكمية المرتجعة من {$line->product_name} أكبر من الكمية المتبقية في الفاتورة.");
                if ($quantity === 0) continue;
                $lineTotal = round($quantity * (float) $line->unit_price, 2);
                $items[] = compact('line', 'quantity', 'lineTotal');
                $total += $lineTotal;
            }

            if ($items === []) throw new Exception('حدد كمية لمنتج واحد على الأقل.');
            $total = round($total, 2);
            if ($refundAmount > $total) throw new Exception('الاسترداد النقدي لا يمكن أن يتجاوز إجمالي المرتجع.');

            $returnInvoice = Invoice::create([
                'partner_id' => $lockedInvoice->partner_id,
                'original_invoice_id' => $lockedInvoice->id,
                'type' => Invoice::TYPE_RETURN,
                'total' => $total,
                'initial_paid' => 0,
                'amount_paid' => 0,
            ]);

            $notificationLines = [];
            foreach ($items as $item) {
                $line = $item['line'];
                $quantity = $item['quantity'];
                $lineTotal = $item['lineTotal'];
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
                ]);
                $product->increment('quantity', $quantity);
                $line->update([
                    'quantity' => $line->quantity - $quantity,
                    'line_total' => round((float) $line->line_total - $lineTotal, 2),
                ]);
                $notificationLines[] = [
                    'product_id' => $product->id,
                    'product_name' => $line->product_name,
                    'quantity' => $quantity,
                    'unit_price' => (float) $line->unit_price,
                    'line_total' => $lineTotal,
                ];
            }

            $lockedInvoice->update(['total' => round((float) $lockedInvoice->total - $total, 2)]);
            if ($refundAmount > 0 && $lockedInvoice->partner_id) {
                LedgerSettlement::create([
                    'partner_id' => $lockedInvoice->partner_id,
                    'amount' => round($refundAmount, 2),
                    'type' => LedgerSettlement::TYPE_OUTGOING,
                    'settlement_date' => now()->toDateString(),
                    'notes' => "استرداد نقدي لمرتجع فاتورة #{$lockedInvoice->id}",
                ]);
            }

            return [$returnInvoice->load('partner'), $notificationLines];
        });

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
        if ($invoice->partner_id) app(AlertService::class)->evaluatePartners($invoice->partner_id);

        return $invoice;
    }
}
