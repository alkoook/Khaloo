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
        $invoices = Invoice::query()->active()->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN total ELSE 0 END), 0) as sales', [Invoice::TYPE_SALE])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN total ELSE 0 END), 0) as purchases', [Invoice::TYPE_PURCHASE])
            ->groupBy('day')->get()->keyBy('day');
        $expenses = Expense::query()->where('date', '>=', $start->toDateString())
            ->selectRaw('date as day, COALESCE(SUM(amount), 0) as total')->groupBy('day')->get()->keyBy('day');
        $profits = InvoiceLine::query()->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoices.created_at', '>=', $start)
            ->where('invoices.type', Invoice::TYPE_SALE)
            ->where('invoices.status', Invoice::STATUS_ACTIVE)
            ->selectRaw('DATE(invoices.created_at) as day')
            ->selectRaw('COALESCE(SUM(COALESCE(invoice_lines.net_line_total, invoice_lines.line_total) - (invoice_lines.quantity * invoice_lines.purchase_price)), 0) as total')
            ->selectRaw('SUM(CASE WHEN invoice_lines.purchase_price IS NULL THEN 1 ELSE 0 END) as unknown_cost_count')
            ->groupBy('day')->get()->keyBy('day');

        $months = collect(range(0, 11))->map(function (int $offset) use ($invoices, $expenses, $profits): array {
            $date = now()->subMonths($offset);
            $forMonth = fn ($row) => Carbon::parse($row->day)->isSameMonth($date);
            $monthSales = (float) $invoices->filter($forMonth)->sum('sales');
            $monthPurchases = (float) $invoices->filter($forMonth)->sum('purchases');
            $monthExpenses = (float) $expenses->filter($forMonth)->sum('total');
            $monthProfitRows = $profits->filter($forMonth);
            $grossProfit = $monthProfitRows->sum('unknown_cost_count') > 0 ? null : (float) $monthProfitRows->sum('total');

            return [
                'label' => $date->locale('ar')->translatedFormat('F Y'),
                'sales' => $monthSales,
                'purchases' => $monthPurchases,
                'expenses' => $monthExpenses,
                'net_profit' => $grossProfit === null ? null : $grossProfit - $monthExpenses,
            ];
        })->reverse()->values();

        $today = now()->toDateString();
        $todayInvoice = $invoices->get($today);
        $todayExpenses = (float) ($expenses->get($today)?->total ?? 0);
        $todayProfitRow = $profits->get($today);
        $todayProfit = (int) ($todayProfitRow?->unknown_cost_count ?? 0) > 0 ? null : (float) ($todayProfitRow?->total ?? 0);

        $partners = $financials->partnerBalancesQuery()->get();
        $receivableThreshold = (float) $settings->get('receivable_alert_threshold', 500);
        $payableThreshold = (float) $settings->get('payable_alert_threshold', 500);

        return view('dashboard', [
            'months' => $months,
            'todaySales' => (float) ($todayInvoice?->sales ?? 0),
            'todayPurchases' => (float) ($todayInvoice?->purchases ?? 0),
            'todayExpenses' => $todayExpenses,
            'dailyNetProfit' => $todayProfit === null ? null : $todayProfit - $todayExpenses,
            'totalReceivable' => $partners->sum(fn ($partner) => $financials->receivable($partner)),
            'totalPayable' => $partners->filter(fn ($partner) => $partner->isSupplier())->sum(fn ($partner) => $financials->payable($partner)),
            'invoiceCount' => Invoice::query()->active()->count(),
            'recentInvoices' => Invoice::with('partner')->latest()->limit(7)->get(),
            'recentPayments' => LedgerSettlement::with('partner')->latest('settlement_date')->limit(6)->get(),
            'lowStockProducts' => Product::query()->whereColumn('quantity', '<=', 'alert_stock_at')->orderBy('quantity')->limit(8)->get(),
            'highReceivables' => $partners->filter(fn ($partner) => $financials->receivable($partner) >= $receivableThreshold)->take(6),
            'highPayables' => $partners->filter(fn ($partner) => $partner->isSupplier() && $financials->payable($partner) >= $payableThreshold)->take(6),
        ]);
    }
}
