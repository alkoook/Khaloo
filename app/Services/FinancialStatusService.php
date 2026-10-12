<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Support\Decimal as D;
use Illuminate\Database\Eloquent\Builder;

class FinancialStatusService
{
    /** Activity uses event dates; position is always current, regardless of the selected period. */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $today = now()->toDateString();
        $events = app(SalesAccountingService::class)->activity($from, $to);
        $netSales = D::sum($events->pluck('merchandise'));
        $delivery = D::sum($events->pluck('delivery'));
        $unknown = $events->sum('unknown');
        $cogs = $unknown ? null : D::sum($events->pluck('cost'));
        $flow = $this->cashFlow($from, $to);
        $gross = $cogs === null ? null : D::sub(D::add($netSales, $delivery), $cogs);
        $current = $this->cashFlow(null, $today);
        $products = Product::query()->get(['quantity', 'purchase_price']);
        $unknownInventory = $products->filter(fn ($p) => D::cmp($p->quantity, 0) > 0 && $p->purchase_price === null)->count();
        $inventory = $unknownInventory ? null : D::sum($products->map(fn ($p) => D::mul($p->quantity, $p->purchase_price ?? 0, 7)));
        $purchases = $this->range(Invoice::query()->active()->where('type', Invoice::TYPE_PURCHASE), 'created_at', $from, $to)->get(['total', 'amount_paid']);

        return array_merge($flow, $this->currentBalances($today), [
            'net_sales' => $netSales, 'delivery_income' => $delivery, 'cogs' => $cogs, 'gross_profit' => $gross,
            'net_profit' => $gross === null ? null : D::sub($gross, $flow['expenses']),
            'purchases' => D::sum($purchases->pluck('total')),
            // Later settlements are partner-level, not allocated to individual purchase invoices.
            'purchase_credit_created' => D::sum($purchases->map(fn ($i) => D::sub($i->total, $i->amount_paid))),
            'purchase_payments' => D::add($flow['direct_cash_purchases'], $flow['supplier_payments']),
            'purchase_count' => $purchases->count(),
            'sale_count' => $events->filter(fn ($e) => $e['invoice']->isSale())->count(),
            'return_count' => $events->filter(fn ($e) => $e['invoice']->isReturn())->count(),
            'returns_total' => D::sum($events->filter(fn ($e) => $e['invoice']->isReturn())->map(fn ($e) => D::sub(0, $e['merchandise']))),
            'current_cash_position' => $current['cash_remaining'], 'position_date' => $today,
            'inventory_value' => $inventory, 'product_count' => $products->count(),
            'out_of_stock_count' => $products->filter(fn ($p) => D::cmp($p->quantity, 0) <= 0)->count(),
            'unknown_inventory_count' => $unknownInventory, 'unknown_cost_lines' => $unknown,
        ]);
    }

    private function currentBalances(string $today): array
    {
        $finance = app(FinancialService::class);
        $partners = $finance->partnerBalancesQuery(null, null, $today)->get();
        $customers = $partners->where('type', Partner::TYPE_CUSTOMER);
        $suppliers = $partners->where('type', Partner::TYPE_SUPPLIER);
        $invoices = Invoice::query()->active()->whereDate('created_at', '<=', $today)
            ->whereIn('type', [Invoice::TYPE_SALE, Invoice::TYPE_PURCHASE])
            ->whereNotNull('partner_id')->get(['partner_id', 'type', 'amount_paid']);
        $customerIssuePaid = D::sum($invoices->where('type', Invoice::TYPE_SALE)->whereIn('partner_id', $customers->modelKeys())->pluck('amount_paid'));
        $supplierIssuePaid = D::sum($invoices->where('type', Invoice::TYPE_PURCHASE)->whereIn('partner_id', $suppliers->modelKeys())->pluck('amount_paid'));

        return [
            // sales_due/purchases_due already subtract issue payments; restore them once for the billed total.
            'customer_invoiced' => D::add(D::sum($customers->pluck('sales_due')), $customerIssuePaid),
            'customer_collected' => D::sub(D::add($customerIssuePaid, D::sum($customers->pluck('incoming_total'))), D::sum($customers->pluck('outgoing_total'))),
            'receivables' => D::sum($customers->map(fn ($p) => $finance->receivable($p))),
            'customer_credits' => D::sum($customers->map(fn ($p) => $finance->payable($p))),
            'supplier_invoiced' => D::add(D::sum($suppliers->pluck('purchases_due')), $supplierIssuePaid),
            'supplier_paid' => D::add($supplierIssuePaid, D::sum($suppliers->pluck('outgoing_total'))),
            // Preserve the existing ledger's supplier incoming-credit convention; never recast it as a purchase.
            'supplier_adjustments' => D::sum($suppliers->pluck('incoming_total')),
            'payables' => D::sum($suppliers->map(fn ($p) => $finance->payable($p))),
            'supplier_credits' => D::sum($suppliers->map(fn ($p) => D::max(0, D::sub(0, $finance->signedBalance($p))))),
        ];
    }

    private function cashFlow(?string $from, ?string $to): array
    {
        $sales = $this->range(Invoice::query()->active()->where('type', Invoice::TYPE_SALE), 'created_at', $from, $to)->get(['amount_paid', 'additional_total']);
        // The invoice payment is the only purchase issue cash source. No money-party/funding queries.
        $purchaseCash = $this->sum($this->range(Invoice::query()->active()->where('type', Invoice::TYPE_PURCHASE), 'created_at', $from, $to), 'amount_paid');
        // amount_paid remains the issue payment; later movements are ONLY ledger settlements.
        $issue = D::sum($sales->pluck('amount_paid'));
        $advances = D::sum($sales->pluck('additional_total'));
        $incoming = $this->settlements(Partner::TYPE_CUSTOMER, LedgerSettlement::TYPE_INCOMING, $from, $to);
        $refunds = $this->settlements(Partner::TYPE_CUSTOMER, LedgerSettlement::TYPE_OUTGOING, $from, $to);
        $supplierPaid = $this->settlements(Partner::TYPE_SUPPLIER, LedgerSettlement::TYPE_OUTGOING, $from, $to);
        $supplierRefunds = $this->settlements(Partner::TYPE_SUPPLIER, LedgerSettlement::TYPE_INCOMING, $from, $to);
        $anonymous = $this->sum($this->range(Invoice::query()->active()->where('type', Invoice::TYPE_RETURN)->whereNull('partner_id')
            ->whereHas('originalInvoice', fn ($q) => $q->active()), 'created_at', $from, $to), 'amount_paid');
        $expenses = $this->sum($this->range(Expense::query(), 'date', $from, $to), 'amount');
        $cashIn = D::sum([$issue, $incoming, $supplierRefunds]);
        $cashOut = D::sum([$refunds, $anonymous, $purchaseCash, $supplierPaid, $expenses, $advances]);

        return [
            'cash_inflows' => $cashIn, 'cash_outflows' => $cashOut, 'cash_remaining' => D::sub($cashIn, $cashOut),
            'sales_collected' => D::add($issue, $incoming), 'sales_paid_at_issue' => $issue,
            'additional_collections' => $incoming, 'refunds' => D::add($refunds, $anonymous), 'anonymous_refunds' => $anonymous,
            'direct_cash_purchases' => $purchaseCash, 'supplier_payments' => $supplierPaid, 'supplier_refunds' => $supplierRefunds,
            'expenses' => $expenses, 'pass_through_advances' => $advances,
        ];
    }

    private function settlements(string $partnerType, string $type, ?string $from, ?string $to): string
    {
        return $this->sum($this->range(LedgerSettlement::query()->whereHas('partner', fn ($q) => $q->where('type', $partnerType))->where('type', $type), 'settlement_date', $from, $to), 'amount');
    }

    private function sum(Builder $query, string $column): string
    {
        return D::sum($query->get([$column])->pluck($column));
    }

    private function range(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        return $query->when($from, fn ($q) => $q->whereDate($column, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($column, '<=', $to));
    }
}
