<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use App\Services\FinancialService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(FinancialService $financials, SettingsService $settings): View
    {
        $start = now()->subMonths(11)->startOfMonth();
        $invoices = Invoice::query()->where('created_at', '>=', $start)->get(['id','type','total','created_at']);
        $expenses = Expense::query()->where('date', '>=', $start->toDateString())->get(['amount','date']);
        $profits = InvoiceLine::query()->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoices.created_at', '>=', $start)
            ->get(['invoices.type','invoices.created_at','invoice_lines.quantity','invoice_lines.unit_price','invoice_lines.purchase_price']);

        $months = collect(range(0, 11))->map(function (int $offset) use ($invoices, $expenses, $profits): array {
            $date = now()->subMonths($offset);
            $sameMonth = fn ($row) => Carbon::parse($row->created_at ?? $row->date)->isSameMonth($date);
            $monthInvoices = $invoices->filter($sameMonth);
            $monthProfits = $profits->filter($sameMonth);
            $monthExpenses = (float) $expenses->filter($sameMonth)->sum('amount');
            $grossProfit = (float) $monthProfits->where('type', Invoice::TYPE_SALE)->sum(fn ($line) => $line->quantity * ((float) $line->unit_price - (float) $line->purchase_price));
            return [
                'label' => $date->locale('ar')->translatedFormat('F Y'),
                'sales' => (float) $monthInvoices->where('type', Invoice::TYPE_SALE)->sum('total'),
                'purchases' => (float) $monthInvoices->where('type', Invoice::TYPE_PURCHASE)->sum('total'),
                'expenses' => $monthExpenses,
                'net_profit' => $grossProfit - $monthExpenses,
            ];
        })->reverse()->values();

        $todayInvoices = $invoices->filter(fn ($invoice) => $invoice->created_at->isToday());
        $todayExpenses = (float) $expenses->filter(fn ($expense) => $expense->date->isToday())->sum('amount');
        $todayProfit = (float) $profits->filter(fn ($line) => $line->created_at->isToday())
            ->where('type', Invoice::TYPE_SALE)->sum(fn ($line) => $line->quantity * ((float) $line->unit_price - (float) $line->purchase_price));

        $partners = $financials->partnerBalancesQuery()->get();
        $receivableThreshold = (float) $settings->get('receivable_alert_threshold', 500);
        $payableThreshold = (float) $settings->get('payable_alert_threshold', 500);

        return view('dashboard', [
            'months' => $months,
            'todaySales' => (float) $todayInvoices->where('type', Invoice::TYPE_SALE)->sum('total'),
            'todayPurchases' => (float) $todayInvoices->where('type', Invoice::TYPE_PURCHASE)->sum('total'),
            'todayExpenses' => $todayExpenses,
            'dailyNetProfit' => $todayProfit - $todayExpenses,
            'totalReceivable' => $partners->sum(fn ($partner) => $financials->receivable($partner)),
            'totalPayable' => $partners->sum(fn ($partner) => $financials->payable($partner)),
            'invoiceCount' => Invoice::query()->count(),
            'recentInvoices' => Invoice::with('partner')->latest()->limit(7)->get(),
            'recentPayments' => LedgerSettlement::with('partner')->latest('settlement_date')->limit(6)->get(),
            'lowStockProducts' => Product::query()->whereColumn('quantity', '<=', 'alert_stock_at')->orderBy('quantity')->limit(8)->get(),
            'highReceivables' => $partners->filter(fn ($partner) => $financials->receivable($partner) >= $receivableThreshold)->take(6),
            'highPayables' => $partners->filter(fn ($partner) => $financials->payable($partner) >= $payableThreshold)->take(6),
        ]);
    }
}
