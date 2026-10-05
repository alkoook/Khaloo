<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\FinancialService;
use App\Services\SettingsService;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function show(Invoice $invoice, FinancialService $financials, SettingsService $settings): View
    {
        $invoice->load(['partner','lines','returns']);
        $expectedType=str_contains(request()->path(),'sales')?Invoice::TYPE_SALE:Invoice::TYPE_PURCHASE;
        abort_if($invoice->type!==$expectedType&&$invoice->type!==Invoice::TYPE_RETURN,404);
        $partner=$invoice->partner_id?$financials->partnerBalancesQuery()->find($invoice->partner_id):null;
        $balance=$partner?$financials->balanceFor($partner,$invoice->isPurchase()?'payable':'receivable'):0;
        return view('invoices.show',['invoice'=>$invoice,'currentPartnerBalance'=>$balance,'settings'=>$settings]);
    }

    public function receipt(Invoice $invoice, SettingsService $settings): View
    {
        abort_unless($invoice->isSale() || $invoice->isPurchase() || $invoice->isReturn(), 404);

        return view('invoices.receipt', [
            'invoice' => $invoice->load(['partner', 'lines', 'originalInvoice']),
            'settings' => $settings,
        ]);
    }

    public function createSales(): View { return view('invoices.sales.create'); }
    public function createPurchases(): View { return view('invoices.purchases.create'); }
}
