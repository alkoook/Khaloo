<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Partner;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function sendInvoice(Request $request): RedirectResponse
    {
        $request->validate(['invoice_id' => ['required', 'exists:invoices,id']]);

        $invoice = Invoice::with(['partner', 'lines'])->findOrFail($request->invoice_id);
        abort_if($invoice->isCancelled(), 409, 'لا يمكن إرسال فاتورة ملغاة.');

        $type = $invoice->type;
        $typeLabel = $type === 'sale' ? 'بيع' : 'شراء';
        $partnerName = $invoice->partner?->name ?? ($type === 'sale' ? 'زبون' : 'مورد');

        $linesData = $invoice->lines->map(fn ($l) => [
            'product_name' => $l->product_name,
            'quantity' => $l->quantity,
            'unit_price' => $l->unit_price,
            'line_total' => $l->line_total,
        ])->toArray();

        $sent = app(TelegramService::class)->sendInvoiceNotification(
            $type,
            $partnerName,
            number_format($invoice->collectibleTotal(), 2),
            number_format($invoice->amount_paid, 2),
            number_format($invoice->balance, 2),
            (string) $invoice->id,
            $linesData
        );

        $back = $type === 'sale' ? route('invoices.sales.index') : route('invoices.purchases.index');

        return $sent
            ? redirect($back)->with('success', 'تم الإرسال إلى تيلغرام.')
            : redirect($back)->with('error', 'لم يتم الإرسال. تحقق من إعدادات البوت في .env.');
    }

    public function sendLedger(Request $request): RedirectResponse
    {
        $request->validate([
            'partner_id' => ['required', 'exists:partners,id'],
            'balance' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'in:receivable,payable'],
        ]);

        $label = $request->type === 'receivable' ? 'اللي النا' : 'اللي علينا';
        $partner = Partner::findOrFail($request->partner_id);
        $text = "دفتر - {$label}\n";
        $text .= 'الاسم: '.($partner->name ?? '—')."\n";
        $text .= 'المبلغ: '.number_format((float) $request->balance, 2);

        $sent = app(TelegramService::class)->sendMessage($text);

        return $sent
            ? redirect()->route('ledger.index')->with('success', 'تم الإرسال إلى تيلغرام.')
            : redirect()->route('ledger.index')->with('error', 'لم يتم الإرسال. تحقق من إعدادات البوت في .env.');
    }
}
