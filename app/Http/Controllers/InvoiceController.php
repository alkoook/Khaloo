<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AlertService;
use App\Services\FinancialService;
use App\Services\InvoiceCancellationService;
use App\Services\InvoiceService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function show(Invoice $invoice, FinancialService $financials, SettingsService $settings, InvoiceService $invoiceService, InvoiceCancellationService $cancellations): View
    {
        $invoice->load(['partner', 'lines', 'additionalLines', 'returns']);
        $expectedType = str_contains(request()->path(), 'sales') ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE;
        abort_if($invoice->type !== $expectedType && $invoice->type !== Invoice::TYPE_RETURN, 404);
        $partner = $invoice->partner_id ? $financials->partnerBalancesQuery()->find($invoice->partner_id) : null;
        $balance = $partner ? $financials->balanceFor($partner, $invoice->isPurchase() ? 'payable' : 'receivable') : 0;

        return view('invoices.show', [
            'invoice' => $invoice,
            'currentPartnerBalance' => $balance,
            'settings' => $settings,
            'editBlockReason' => $invoiceService->editBlockReason($invoice),
            'cancellationBlockReason' => $cancellations->blockReason($invoice),
        ]);
    }

    public function cancelPurchase(Request $request, Invoice $invoice, InvoiceCancellationService $cancellations): RedirectResponse
    {
        abort_unless($invoice->isPurchase(), 404);
        abort_unless($request->user()?->can('delete', $invoice), 403);
        $validated = $request->validate(['cancellation_reason' => ['required', 'string', 'min:5', 'max:1000']]);

        try {
            $productIds = $cancellations->cancel($invoice, (int) $request->user()->id, $validated['cancellation_reason']);
        } catch (ValidationException $exception) {
            return back()->withErrors(['cancellation_reason' => collect($exception->errors())->flatten()->first()]);
        }

        app(AlertService::class)->evaluateProducts($productIds);
        if ($invoice->partner_id) {
            app(AlertService::class)->evaluatePartners($invoice->partner_id);
        }

        return redirect()->route('invoices.purchases.show', $invoice)->with('success', 'تم إلغاء فاتورة الشراء وعكس أثر المخزون مرة واحدة مع الاحتفاظ بسجلها.');
    }

    public function receipt(Invoice $invoice, SettingsService $settings): View
    {
        abort_unless($invoice->isSale() || $invoice->isPurchase() || $invoice->isReturn(), 404);

        return view('invoices.receipt', [
            'invoice' => $invoice->load(['partner', 'lines', 'additionalLines', 'originalInvoice']),
            'settings' => $settings,
        ]);
    }

    public function createSales(): View
    {
        return view('invoices.sales.create');
    }

    public function createPurchases(): View
    {
        return view('invoices.purchases.create');
    }
}
