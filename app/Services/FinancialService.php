<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;

class FinancialService
{
    /**
     * amount_paid is the payment captured on the invoice; initial_paid is its
     * historical snapshot. Later payments and refunds are settlements. Linked
     * returns already reduce the original sale total and are not counted twice.
     */
    public function partnerBalancesQuery(?string $search = null, ?string $from = null, ?string $to = null): Builder
    {
        $invoiceSum = function (string $type) use ($from, $to) {
            return Invoice::query()->selectRaw('COALESCE(SUM(total - amount_paid), 0)')
                ->whereColumn('partner_id', 'partners.id')->where('type', $type)
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
        };
        $settlementSum = function (string $type) use ($from, $to) {
            return LedgerSettlement::query()->selectRaw('COALESCE(SUM(amount), 0)')
                ->whereColumn('partner_id', 'partners.id')->where('type', $type)
                ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to));
        };

        return Partner::query()->select('partners.*')
            ->selectSub($invoiceSum(Invoice::TYPE_SALE), 'sales_due')
            ->selectSub($invoiceSum(Invoice::TYPE_PURCHASE), 'purchases_due')
            ->selectSub($settlementSum(LedgerSettlement::TYPE_INCOMING), 'incoming_total')
            ->selectSub($settlementSum(LedgerSettlement::TYPE_OUTGOING), 'outgoing_total')
            ->withMax('invoices as last_invoice_at', 'updated_at')
            ->when(trim((string) $search) !== '', function (Builder $query) use ($search): void {
                $term = trim((string) $search);
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('type', $term)
                        ->orWhereHas('invoices', fn (Builder $invoiceQuery) => $invoiceQuery
                            ->when(is_numeric($term), fn (Builder $q) => $q->whereKey((int) $term), fn (Builder $q) => $q->whereRaw('1 = 0')));
                });
            });
    }

    public function receivable(Partner $partner): float
    {
        if (! $partner->isCustomer()) return 0.0;
        return max(0, round((float) $partner->sales_due - (float) $partner->incoming_total + (float) $partner->outgoing_total, 2));
    }

    public function payable(Partner $partner): float
    {
        if ($partner->isCustomer()) {
            return max(0, round((float) $partner->incoming_total - (float) $partner->sales_due - (float) $partner->outgoing_total, 2));
        }
        if (! $partner->isSupplier()) return 0.0;
        return max(0, round((float) $partner->purchases_due - (float) $partner->outgoing_total + (float) $partner->incoming_total, 2));
    }

    public function balanceFor(Partner $partner, string $side): float
    {
        return $side === 'payable' ? $this->payable($partner) : $this->receivable($partner);
    }
}
