<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\MoneyParty;
use App\Models\MoneyPartyMovement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseFundingSource;
use Illuminate\Database\Eloquent\Builder;

class FinancialStatusService
{
    /**
     * This is an operating view, not a cashbox reconciliation: Khaloo does
     * not hold opening cash or every external cash movement.
     */
    public function summary(?string $from = null, ?string $to = null): array
    {
        $sales = $this->invoiceTotal(Invoice::TYPE_SALE, $from, $to);
        $deliveryIncome = $this->saleChargeTotal('delivery_amount', $from, $to);
        $passThroughAdvances = $this->saleChargeTotal('additional_total', $from, $to);
        $salesCollectedAtIssue = $this->invoicePaid(Invoice::TYPE_SALE, $from, $to);
        $purchases = $this->invoiceTotal(Invoice::TYPE_PURCHASE, $from, $to);
        $purchasesPaid = $this->invoicePaid(Invoice::TYPE_PURCHASE, $from, $to);
        $crossPeriodReturns = $this->crossPeriodReturns($from, $to);
        $costData = $this->salesCost($from, $to);
        $unknownCostLines = $costData['unknown_count'] + $crossPeriodReturns['unknown_count'];
        $cogs = $unknownCostLines === 0 ? $costData['total'] - $crossPeriodReturns['cost'] : null;
        $netSales = $sales - $crossPeriodReturns['total'];
        $expenses = $this->sumExpenses($from, $to);
        $grossProfit = $cogs === null ? null : $netSales + $deliveryIncome - $cogs;
        $netProfit = $grossProfit === null ? null : $grossProfit - $expenses;

        $customerPayments = $this->settlementsForPartnerType('customer', LedgerSettlement::TYPE_INCOMING, $from, $to);
        $customerRefunds = $this->settlementsForPartnerType('customer', LedgerSettlement::TYPE_OUTGOING, $from, $to);
        $anonymousRefunds = $this->anonymousReturnRefunds($from, $to);
        $supplierPayments = $this->settlementsForPartnerType('supplier', LedgerSettlement::TYPE_OUTGOING, $from, $to);
        $supplierRefunds = $this->settlementsForPartnerType('supplier', LedgerSettlement::TYPE_INCOMING, $from, $to);
        $fundedPurchases = $this->fundedPurchases($from, $to);
        $directCashPurchases = max(0, $purchasesPaid - $fundedPurchases);
        $movementCash = $this->movementCashFlow($from, $to);
        $cashRemaining = $salesCollectedAtIssue + $customerPayments - $customerRefunds - $anonymousRefunds
            - $directCashPurchases - $supplierPayments - $expenses - $movementCash['to_parties']
            - $movementCash['owner_withdrawals'] - $passThroughAdvances + $movementCash['from_parties'];
        $cashRemaining += $supplierRefunds;

        $unknownInventoryCount = Product::query()->where('quantity', '>', 0)->whereNull('purchase_price')->count();
        $inventoryValue = $unknownInventoryCount === 0
            ? (float) Product::query()->selectRaw('COALESCE(SUM(quantity * purchase_price), 0) as total')->value('total')
            : null;
        $partyBalances = $this->moneyPartyBalances();
        $partnerBalances = $this->partnerBalances();

        return [
            'net_sales' => round($netSales, 2),
            'delivery_income' => round($deliveryIncome, 2),
            'pass_through_advances' => round($passThroughAdvances, 2),
            'cogs' => $cogs === null ? null : round($cogs, 2),
            'gross_profit' => $grossProfit === null ? null : round($grossProfit, 2),
            'expenses' => round($expenses, 2),
            'net_profit' => $netProfit === null ? null : round($netProfit, 2),
            'purchases' => round($purchases, 2),
            'sales_collected' => round($salesCollectedAtIssue + $customerPayments, 2),
            'additional_collections' => round($customerPayments, 2),
            'refunds' => round($customerRefunds + $anonymousRefunds, 2),
            'anonymous_refunds' => round($anonymousRefunds, 2),
            'direct_cash_purchases' => round($directCashPurchases, 2),
            'supplier_payments' => round($supplierPayments, 2),
            'supplier_refunds' => round($supplierRefunds, 2),
            'money_to_parties' => round($movementCash['to_parties'], 2),
            'money_from_parties' => round($movementCash['from_parties'], 2),
            'owner_withdrawals' => round($movementCash['owner_withdrawals'], 2),
            'cash_remaining' => round($cashRemaining, 2),
            'inventory_value' => $inventoryValue === null ? null : round($inventoryValue, 2),
            'money_at_parties' => $partyBalances['assets'],
            'money_party_liabilities' => $partyBalances['liabilities'],
            'money_party_net' => $partyBalances['net'],
            'money_party_rows' => $partyBalances['rows'],
            'receivables' => $partnerBalances['receivables'],
            'customer_credits' => $partnerBalances['customer_credits'],
            'payables' => $partnerBalances['payables'],
            'net_financial_position' => $inventoryValue === null ? null : round($cashRemaining + $inventoryValue + $partyBalances['assets'] - $partyBalances['liabilities'], 2),
            'unknown_cost_lines' => $unknownCostLines,
            'unknown_inventory_count' => $unknownInventoryCount,
            'unclassified_count' => $movementCash['unclassified_count'],
            'unclassified_amount' => $movementCash['unclassified_amount'],
            'cross_period_returns' => round($crossPeriodReturns['total'], 2),
        ];
    }

    public function availableAtParty(int $partyId, bool $lock = false): float
    {
        $movements = MoneyPartyMovement::query()
            ->where('money_party_id', $partyId)
            ->where('status', MoneyPartyMovement::STATUS_ACTIVE)
            ->where('category', '!=', MoneyPartyMovement::CATEGORY_UNCLASSIFIED)
            ->where('category', '!=', MoneyPartyMovement::CATEGORY_OWNER_WITHDRAWAL);
        if ($lock) {
            $movements->lockForUpdate();
        }
        $movementNet = (float) $movements->selectRaw(
            'COALESCE(SUM(CASE WHEN direction = ? THEN amount_base_currency ELSE -amount_base_currency END), 0) as total',
            [MoneyPartyMovement::DIRECTION_TO_PARTY]
        )->value('total');

        $funding = PurchaseFundingSource::query()->join('invoices', 'invoices.id', '=', 'purchase_funding_sources.invoice_id')
            ->where('invoices.status', Invoice::STATUS_ACTIVE)
            ->where('money_party_id', $partyId)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->sum('amount_base_currency');

        return round($movementNet - (float) $funding, 2);
    }

    public function moneyPartyBalances(): array
    {
        $parties = MoneyParty::query()->orderBy('name')->get(['id', 'name']);
        $movementNet = MoneyPartyMovement::query()
            ->where('status', MoneyPartyMovement::STATUS_ACTIVE)
            ->where('category', '!=', MoneyPartyMovement::CATEGORY_UNCLASSIFIED)
            ->where('category', '!=', MoneyPartyMovement::CATEGORY_OWNER_WITHDRAWAL)
            ->select('money_party_id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN direction = ? THEN amount_base_currency ELSE -amount_base_currency END), 0) as total',
                [MoneyPartyMovement::DIRECTION_TO_PARTY]
            )->groupBy('money_party_id')->pluck('total', 'money_party_id');
        $fundingByParty = PurchaseFundingSource::query()
            ->join('invoices', 'invoices.id', '=', 'purchase_funding_sources.invoice_id')
            ->where('invoices.status', Invoice::STATUS_ACTIVE)
            ->selectRaw('purchase_funding_sources.money_party_id, COALESCE(SUM(purchase_funding_sources.amount_base_currency), 0) as total')
            ->groupBy('purchase_funding_sources.money_party_id')->pluck('total', 'purchase_funding_sources.money_party_id');
        $assets = 0.0;
        $liabilities = 0.0;
        $rows = $parties->map(function (MoneyParty $party) use (&$assets, &$liabilities, $movementNet, $fundingByParty): array {
            $balance = round((float) ($movementNet[$party->id] ?? 0) - (float) ($fundingByParty[$party->id] ?? 0), 2);
            if ($balance >= 0) {
                $assets += $balance;
            } else {
                $liabilities += abs($balance);
            }

            return ['party' => $party, 'balance' => $balance];
        });

        return [
            'assets' => round($assets, 2),
            'liabilities' => round($liabilities, 2),
            'net' => round($assets - $liabilities, 2),
            'rows' => $rows,
        ];
    }

    private function invoiceTotal(string $type, ?string $from, ?string $to): float
    {
        return (float) $this->dateRange(Invoice::query()->active()->where('type', $type), 'created_at', $from, $to)->sum('total');
    }

    private function invoicePaid(string $type, ?string $from, ?string $to): float
    {
        return (float) $this->dateRange(Invoice::query()->active()->where('type', $type), 'created_at', $from, $to)->sum('amount_paid');
    }

    private function saleChargeTotal(string $column, ?string $from, ?string $to): float
    {
        return (float) $this->dateRange(
            Invoice::query()->active()->where('type', Invoice::TYPE_SALE),
            'created_at',
            $from,
            $to,
        )->sum($column);
    }

    private function salesCost(?string $from, ?string $to): array
    {
        $query = $this->dateRange(
            InvoiceLine::query()->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                ->where('invoices.type', Invoice::TYPE_SALE)
                ->where('invoices.status', Invoice::STATUS_ACTIVE),
            'invoices.created_at',
            $from,
            $to,
        );

        return [
            'total' => (float) (clone $query)->selectRaw('COALESCE(SUM(invoice_lines.quantity * invoice_lines.purchase_price), 0) as total')->value('total'),
            'unknown_count' => (int) (clone $query)->whereNull('invoice_lines.purchase_price')->count('invoice_lines.id'),
        ];
    }

    /**
     * Existing sales returns reduce the original sale in place. A return whose
     * original invoice predates the selected range is therefore a current
     * period negative sale/cost and must be brought into this period.
     */
    private function crossPeriodReturns(?string $from, ?string $to): array
    {
        if (! $from) {
            return ['total' => 0.0, 'cost' => 0.0, 'unknown_count' => 0];
        }

        $query = Invoice::query()->where('invoices.type', Invoice::TYPE_RETURN)
            ->where('invoices.status', Invoice::STATUS_ACTIVE)
            ->join('invoices as originals', 'originals.id', '=', 'invoices.original_invoice_id')
            ->where('originals.status', Invoice::STATUS_ACTIVE)
            ->whereDate('originals.created_at', '<', $from);
        $this->dateRange($query, 'invoices.created_at', $from, $to);

        $total = (float) (clone $query)->sum('invoices.total');
        $costQuery = InvoiceLine::query()->join('invoices as returns', 'returns.id', '=', 'invoice_lines.invoice_id')
            ->join('invoices as originals', 'originals.id', '=', 'returns.original_invoice_id')
            ->where('returns.type', Invoice::TYPE_RETURN)
            ->where('returns.status', Invoice::STATUS_ACTIVE)
            ->where('originals.status', Invoice::STATUS_ACTIVE)
            ->whereDate('originals.created_at', '<', $from)
            ->tap(fn (Builder $builder) => $this->dateRange($builder, 'returns.created_at', $from, $to));
        $cost = (float) (clone $costQuery)->selectRaw('COALESCE(SUM(invoice_lines.quantity * invoice_lines.purchase_price), 0) as total')->value('total');
        $unknownCount = (int) (clone $costQuery)->whereNull('invoice_lines.purchase_price')->count('invoice_lines.id');

        return ['total' => round($total, 2), 'cost' => round($cost, 2), 'unknown_count' => $unknownCount];
    }

    private function sumExpenses(?string $from, ?string $to): float
    {
        return (float) $this->dateRange(Expense::query(), 'date', $from, $to)->sum('amount');
    }

    private function settlementsForPartnerType(string $partnerType, string $type, ?string $from, ?string $to): float
    {
        $query = LedgerSettlement::query()->join('partners', 'partners.id', '=', 'ledger_settlements.partner_id')
            ->where('partners.type', $partnerType)
            ->where('ledger_settlements.type', $type);

        return (float) $this->dateRange($query, 'ledger_settlements.settlement_date', $from, $to)->sum('ledger_settlements.amount');
    }

    private function fundedPurchases(?string $from, ?string $to): float
    {
        $query = PurchaseFundingSource::query()->join('invoices', 'invoices.id', '=', 'purchase_funding_sources.invoice_id')
            ->where('invoices.type', Invoice::TYPE_PURCHASE)
            ->where('invoices.status', Invoice::STATUS_ACTIVE);

        return (float) $this->dateRange($query, 'invoices.created_at', $from, $to)->sum('purchase_funding_sources.amount_base_currency');
    }

    /** Cash returned from a cash sale has no partner settlement by design. */
    private function anonymousReturnRefunds(?string $from, ?string $to): float
    {
        return (float) $this->dateRange(
            Invoice::query()->active()->where('type', Invoice::TYPE_RETURN)->whereNull('partner_id'),
            'created_at',
            $from,
            $to,
        )->sum('amount_paid');
    }

    private function movementCashFlow(?string $from, ?string $to): array
    {
        $base = $this->dateRange(
            MoneyPartyMovement::query()->where('status', MoneyPartyMovement::STATUS_ACTIVE),
            'date',
            $from,
            $to,
        );
        $classified = (clone $base)->where('category', '!=', MoneyPartyMovement::CATEGORY_UNCLASSIFIED);
        $toParties = (float) (clone $classified)
            ->where('direction', MoneyPartyMovement::DIRECTION_TO_PARTY)
            ->where('category', '!=', MoneyPartyMovement::CATEGORY_OWNER_WITHDRAWAL)
            ->sum('amount_base_currency');
        $fromParties = (float) (clone $classified)
            ->where('direction', MoneyPartyMovement::DIRECTION_FROM_PARTY)
            ->sum('amount_base_currency');
        $ownerWithdrawals = (float) (clone $classified)
            ->where('category', MoneyPartyMovement::CATEGORY_OWNER_WITHDRAWAL)
            ->where('direction', MoneyPartyMovement::DIRECTION_TO_PARTY)
            ->sum('amount_base_currency');
        $unclassified = (clone $base)->where('category', MoneyPartyMovement::CATEGORY_UNCLASSIFIED);

        return [
            'to_parties' => $toParties,
            'from_parties' => $fromParties,
            'owner_withdrawals' => $ownerWithdrawals,
            'unclassified_count' => (int) $unclassified->count(),
            'unclassified_amount' => (float) $unclassified->sum('amount_base_currency'),
        ];
    }

    private function partnerBalances(): array
    {
        $financials = app(FinancialService::class);
        $partners = $financials->partnerBalancesQuery()->get();
        $supplierPayables = $partners->where('type', Partner::TYPE_SUPPLIER)
            ->sum(fn ($partner) => $financials->payable($partner));

        return [
            'receivables' => round($partners->sum(fn ($partner) => $financials->receivable($partner)), 2),
            'customer_credits' => round($partners->where('type', 'customer')->sum(fn ($partner) => $financials->payable($partner)), 2),
            'payables' => round($supplierPayables, 2),
        ];
    }

    private function dateRange(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $builder) => $builder->whereDate($column, '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate($column, '<=', $to));
    }
}
