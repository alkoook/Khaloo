<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Product;
use App\Services\FinancialService;
use App\Services\SalesAccountingService;
use App\Services\SettingsService;
use App\Support\Decimal as D;
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
        $profits = app(SalesAccountingService::class)->activity($start->toDateString(), now()->toDateString())
            ->groupBy(fn ($event) => $event['date']->toDateString())
            ->map(function ($group, $day) {
                $sales = D::sum($group->pluck('merchandise'));
                $delivery = D::sum($group->pluck('delivery'));
                $cost = D::sum($group->pluck('cost'));

                return (object) ['day' => $day, 'sales' => $sales, 'total' => D::sub(D::add($sales, $delivery), $cost), 'unknown_cost_count' => $group->sum('unknown')];
            });

        $months = collect(range(0, 11))->map(function (int $offset) use ($invoices, $expenses, $profits): array {
            $date = now()->subMonths($offset);
            $forMonth = fn ($row) => Carbon::parse($row->day)->isSameMonth($date);
            $monthSales = D::sum($profits->filter($forMonth)->pluck('sales'));
            $monthPurchases = D::sum($invoices->filter($forMonth)->pluck('purchases'));
            $monthExpenses = D::sum($expenses->filter($forMonth)->pluck('total'));
            $monthProfitRows = $profits->filter($forMonth);
            $grossProfit = $monthProfitRows->sum('unknown_cost_count') > 0 ? null : D::sum($monthProfitRows->pluck('total'));

            return [
                'label' => $date->locale('ar')->translatedFormat('F Y'),
                'sales' => $monthSales,
                'purchases' => $monthPurchases,
                'expenses' => $monthExpenses,
                'net_profit' => $grossProfit === null ? null : D::sub($grossProfit, $monthExpenses),
            ];
        })->reverse()->values();

        $today = now()->toDateString();
        $todayInvoice = $invoices->get($today);
        $todayExpenses = D::scale($expenses->get($today)?->total ?? 0);
        $todayProfitRow = $profits->get($today);
        $todayProfit = (int) ($todayProfitRow?->unknown_cost_count ?? 0) > 0 ? null : D::scale($todayProfitRow?->total ?? 0);

        $partners = $financials->partnerBalancesQuery()->get();
        $receivableThreshold = $settings->get('receivable_alert_threshold', 500);
        $payableThreshold = $settings->get('payable_alert_threshold', 500);

        return view('dashboard', [
            'months' => $months,
            'todaySales' => D::scale($todayProfitRow?->sales ?? 0),
            'todayPurchases' => D::scale($todayInvoice?->purchases ?? 0),
            'todayExpenses' => $todayExpenses,
            'dailyNetProfit' => $todayProfit === null ? null : D::sub($todayProfit, $todayExpenses),
            'totalReceivable' => D::sum($partners->map(fn ($partner) => $financials->receivable($partner))),
            'totalPayable' => D::sum($partners->filter(fn ($partner) => $partner->isSupplier())->map(fn ($partner) => $financials->payable($partner))),
            'invoiceCount' => Invoice::query()->active()->count(),
            'recentInvoices' => Invoice::with('partner')->latest()->limit(7)->get(),
            'recentPayments' => LedgerSettlement::with('partner')->latest('settlement_date')->limit(6)->get(),
            'lowStockProducts' => Product::query()->whereColumn('quantity', '<=', 'alert_stock_at')->orderBy('quantity')->limit(8)->get(),
            'highReceivables' => $partners->filter(fn ($partner) => $financials->receivable($partner) >= $receivableThreshold)->take(6),
            'highPayables' => $partners->filter(fn ($partner) => $partner->isSupplier() && $financials->payable($partner) >= $payableThreshold)->take(6),
        ]);
    }
}
