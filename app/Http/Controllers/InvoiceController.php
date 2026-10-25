<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AlertService;
use App\Services\FinancialService;
use App\Services\InvoiceCancellationService;
use App\Services\InvoiceService;
use App\Services\SalesAccountingService;
use App\Services\SettingsService;
use App\Services\ThermalPrintSettings;
use App\Services\WindowsReceiptCutter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function show(Invoice $invoice, FinancialService $financials, SettingsService $settings, InvoiceService $invoiceService, InvoiceCancellationService $cancellations): View
    {
        $invoice->load(['partner', 'lines', 'additionalLines', 'returns', 'settlements']);
        $expectedType = str_contains(request()->path(), 'sales') ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE;
        abort_if($invoice->type !== $expectedType && $invoice->type !== Invoice::TYPE_RETURN, 404);
        $partner = $invoice->partner_id ? $financials->partnerBalancesQuery()->find($invoice->partner_id) : null;
        $balance = $partner ? $financials->signedBalance($partner) : 0;

        return view('invoices.show', [
            'invoice' => $invoice,
            'profitability' => $invoice->isSale() ? app(SalesAccountingService::class)->profitability($invoice) : null,
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

    public function receipt(Invoice $invoice, SettingsService $settings, ThermalPrintSettings $thermalSettings): View
    {
        abort_unless($invoice->isSale() || $invoice->isPurchase() || $invoice->isReturn(), 404);

        return view('invoices.receipt', [
            'invoice' => $invoice->load(['partner', 'lines', 'additionalLines', 'originalInvoice', 'settlements']),
            'settings' => $settings,
            'thermal' => $thermalSettings->resolve(),
        ]);
    }

    public function cutReceipt(Request $request, Invoice $invoice, WindowsReceiptCutter $cutter): JsonResponse
    {
        abort_unless($invoice->isSale() || $invoice->isPurchase() || $invoice->isReturn(), 404);
        $validated = $request->validate(['print_token' => ['required', 'uuid']]);
        $cacheKey = 'receipt-cut:'.$request->user()->id.':'.$validated['print_token'];

        if (! Cache::add($cacheKey, true, now()->addMinutes(10))) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $result = $cutter->cut();
        } catch (\Throwable $exception) {
            Cache::forget($cacheKey);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => $result['enabled'] ? 'cut' : 'disabled', 'printer' => $result['printer']]);
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
