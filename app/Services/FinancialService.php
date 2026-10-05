<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialService
{
    public function recordSettlement(int $partnerId, string $side, float $amount, string $date, ?string $notes = null): LedgerSettlement
    {
        if (! in_array($side, ['receivable', 'payable'], true) || ! is_finite($amount) || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الدفعة غير صالحة.']);
        }

        return DB::transaction(function () use ($partnerId, $side, $amount, $date, $notes): LedgerSettlement {
            Partner::query()->lockForUpdate()->findOrFail($partnerId);
            $partner = $this->partnerBalancesQuery()->findOrFail($partnerId);
            $available = $this->balanceFor($partner, $side);
            $amount = round($amount, 2);
            if ($amount > $available) {
                throw ValidationException::withMessages(['amount' => 'المبلغ يتجاوز الرصيد المستحق. لا يدعم النظام دفعة زائدة غير مصنفة.']);
            }

            return LedgerSettlement::query()->create([
                'partner_id' => $partnerId,
                'amount' => $amount,
                'type' => $side === 'receivable' ? LedgerSettlement::TYPE_INCOMING : LedgerSettlement::TYPE_OUTGOING,
                'settlement_date' => $date,
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ]);
        }, 3);
    }

    public function partnerSummary(Partner $partner): array
    {
        $row = $this->partnerBalancesQuery()->findOrFail($partner->id);

        return [
            'invoiced' => $partner->isCustomer() ? (float) $row->sales_due : (float) $row->purchases_due,
            'paid' => $partner->isCustomer() ? (float) $row->incoming_total : (float) $row->outgoing_total,
            'credits' => $partner->isCustomer() ? (float) $row->outgoing_total : (float) $row->incoming_total,
            'receivable' => $this->receivable($row),
            'payable' => $this->payable($row),
            'signed' => $this->signedBalance($row),
        ];
    }

    public function statementRows(Partner $partner, ?string $from = null, ?string $to = null): Collection
    {
        $rows = collect();
        Invoice::query()->active()->with('returns')->where('partner_id', $partner->id)
            ->where($partner->isCustomer() ? 'type' : 'type', $partner->isCustomer() ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE)
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))->get()
            ->each(function (Invoice $invoice) use ($rows, $partner): void {
                $returns = $partner->isCustomer() ? (float) $invoice->returns->sum('total') : 0.0;
                $gross = round((float) $invoice->total + $returns, 2);
                $rows->push(['date' => $invoice->created_at, 'sort' => 10, 'type' => $partner->isCustomer() ? 'فاتورة بيع' : 'فاتورة شراء', 'reference' => '#'.$invoice->id, 'description' => 'إجمالي الفاتورة النهائي', 'charge' => $gross, 'payment' => 0.0]);
                if ((float) $invoice->initial_paid > 0) {
                    $rows->push(['date' => $invoice->created_at, 'sort' => 20, 'type' => $partner->isCustomer() ? 'دفعة من العميل' : 'دفعة للمورد', 'reference' => '#'.$invoice->id, 'description' => 'مدفوع عند إنشاء الفاتورة', 'charge' => 0.0, 'payment' => (float) $invoice->initial_paid]);
                }
                foreach ($invoice->returns as $return) {
                    $rows->push(['date' => $return->created_at, 'sort' => 30, 'type' => 'مرتجع مبيعات', 'reference' => '#'.$return->id, 'description' => 'مرتجع من الفاتورة #'.$invoice->id, 'charge' => 0.0, 'payment' => (float) $return->total]);
                }
            });
        LedgerSettlement::query()->where('partner_id', $partner->id)
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))->get()
            ->each(function (LedgerSettlement $settlement) use ($rows, $partner): void {
                $reduces = ($partner->isCustomer() && $settlement->type === LedgerSettlement::TYPE_INCOMING)
                    || $partner->isSupplier();
                $rows->push(['date' => $settlement->settlement_date, 'sort' => 40, 'type' => $settlement->type === LedgerSettlement::TYPE_INCOMING ? 'مبلغ مستلم' : 'مبلغ مدفوع', 'reference' => 'P-'.$settlement->id, 'description' => $settlement->notes ?: 'تسوية لاحقة', 'charge' => $reduces ? 0.0 : (float) $settlement->amount, 'payment' => $reduces ? (float) $settlement->amount : 0.0]);
            });

        $balance = 0.0;
        if ($from) {
            $before = now()->parse($from)->subDay()->toDateString();
            $opening = $this->partnerBalancesQuery(null, null, $before)->find($partner->id);
            $balance = $opening ? $this->signedBalance($opening) : 0.0;
        }

        return $rows->sortBy(fn ($row) => $row['date']->format('Y-m-d H:i:s').'|'.str_pad((string) $row['sort'], 2, '0', STR_PAD_LEFT))->values()
            ->map(function (array $row) use (&$balance): array {
                $balance = round($balance + $row['charge'] - $row['payment'], 2);

                return $row + ['balance' => $balance];
            });
    }

    /**
     * amount_paid is the payment captured on the invoice; initial_paid is its
     * historical snapshot. Later payments and refunds are settlements. Linked
     * returns already reduce the original sale total and are not counted twice.
     */
    public function partnerBalancesQuery(?string $search = null, ?string $from = null, ?string $to = null): Builder
    {
        $invoiceSum = function (string $type) use ($from, $to) {
            return Invoice::query()->active()->selectRaw('COALESCE(SUM(total - amount_paid), 0)')
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
            ->withMax(['invoices as last_invoice_at' => fn (Builder $query) => $query->active()], 'updated_at')
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
        if (! $partner->isCustomer()) {
            return 0.0;
        }

        return max(0, round((float) $partner->sales_due - (float) $partner->incoming_total + (float) $partner->outgoing_total, 2));
    }

    public function payable(Partner $partner): float
    {
        if ($partner->isCustomer()) {
            return max(0, round((float) $partner->incoming_total - (float) $partner->sales_due - (float) $partner->outgoing_total, 2));
        }
        if (! $partner->isSupplier()) {
            return 0.0;
        }

        return max(0, round((float) $partner->purchases_due - (float) $partner->outgoing_total - (float) $partner->incoming_total, 2));
    }

    public function balanceFor(Partner $partner, string $side): float
    {
        return $side === 'payable' ? $this->payable($partner) : $this->receivable($partner);
    }

    /**
     * Positive means an amount remains due from the counterparty's normal
     * invoice flow; negative means the counterparty has a credit balance.
     */
    public function signedBalance(Partner $partner): float
    {
        if ($partner->isCustomer()) {
            return round((float) $partner->sales_due - (float) $partner->incoming_total + (float) $partner->outgoing_total, 2);
        }

        if ($partner->isSupplier()) {
            return round((float) $partner->purchases_due - (float) $partner->outgoing_total - (float) $partner->incoming_total, 2);
        }

        return 0.0;
    }
}
