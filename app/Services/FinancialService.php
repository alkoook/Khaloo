<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Support\Decimal as D;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialService
{
    public function recordSettlement(int $partnerId, string $side, mixed $amount, string $date, ?string $notes = null): LedgerSettlement
    {
        $amount = D::scale($amount);
        if (! in_array($side, ['receivable', 'payable'], true) || D::cmp($amount, 0) <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الدفعة غير صالحة.']);
        }

        return DB::transaction(function () use ($partnerId, $side, $amount, $date, $notes): LedgerSettlement {
            Partner::query()->lockForUpdate()->findOrFail($partnerId);
            $partner = $this->partnerBalancesQuery()->findOrFail($partnerId);
            $available = $this->balanceFor($partner, $side);
            $amount = D::scale($amount);
            if (D::cmp($amount, $available) > 0) {
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
            'invoiced' => $partner->isCustomer() ? D::scale($row->sales_due) : D::scale($row->purchases_due),
            'paid' => $partner->isCustomer() ? D::scale($row->incoming_total) : D::scale($row->outgoing_total),
            'credits' => $partner->isCustomer() ? D::scale($row->outgoing_total) : D::scale($row->incoming_total),
            'receivable' => $this->receivable($row),
            'payable' => $this->payable($row),
            'signed' => $this->signedBalance($row),
        ];
    }

    public function statementRows(Partner $partner, ?string $from = null, ?string $to = null): Collection
    {
        $rows = collect();
        Invoice::query()->active()->with(['returns' => fn ($q) => $q->active()])->where('partner_id', $partner->id)
            ->where($partner->isCustomer() ? 'type' : 'type', $partner->isCustomer() ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE)
            ->get()
            ->each(function (Invoice $invoice) use ($rows, $partner): void {
                $returns = $partner->isCustomer() ? D::sum($invoice->returns->pluck('total')) : '0.00';
                $gross = D::add($partner->isCustomer() ? $invoice->collectibleTotal() : $invoice->total, $returns);
                $rows->push(['date' => $invoice->created_at, 'sort' => 10, 'type' => $partner->isCustomer() ? 'فاتورة بيع' : 'فاتورة شراء', 'reference' => '#'.$invoice->id, 'description' => 'إجمالي الفاتورة النهائي', 'charge' => $gross, 'payment' => '0.00']);
                if ($invoice->initial_paid > 0) {
                    $rows->push(['date' => $invoice->created_at, 'sort' => 20, 'type' => $partner->isCustomer() ? 'دفعة من العميل' : 'دفعة للمورد', 'reference' => '#'.$invoice->id, 'description' => 'مدفوع عند إنشاء الفاتورة', 'charge' => '0.00', 'payment' => $invoice->initial_paid]);
                }
                foreach ($invoice->returns as $return) {
                    $rows->push(['date' => $return->created_at, 'sort' => 30, 'type' => 'مرتجع مبيعات', 'reference' => '#'.$return->id, 'description' => 'مرتجع من الفاتورة #'.$invoice->id, 'charge' => '0.00', 'payment' => $return->total]);
                }
            });
        LedgerSettlement::query()->where('partner_id', $partner->id)
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))->get()
            ->each(function (LedgerSettlement $settlement) use ($rows, $partner): void {
                $reduces = ($partner->isCustomer() && $settlement->type === LedgerSettlement::TYPE_INCOMING)
                    || $partner->isSupplier();
                $rows->push(['date' => $settlement->settlement_date, 'sort' => 40, 'type' => $settlement->type === LedgerSettlement::TYPE_INCOMING ? 'مبلغ مستلم' : 'مبلغ مدفوع', 'reference' => 'دفعة-'.$settlement->id, 'description' => $settlement->notes ?: 'تسوية لاحقة', 'charge' => $reduces ? '0.00' : $settlement->amount, 'payment' => $reduces ? $settlement->amount : '0.00']);
            });

        $balance = '0.00';
        if ($from) {
            $before = now()->parse($from)->subDay()->toDateString();
            $opening = $this->partnerBalancesQuery(null, null, $before)->find($partner->id);
            $balance = $opening ? $this->signedBalance($opening) : '0.00';
        }

        return $rows->filter(fn ($row) => (! $from || $row['date']->toDateString() >= $from) && (! $to || $row['date']->toDateString() <= $to))->sortBy(fn ($row) => $row['date']->format('Y-m-d H:i:s').'|'.str_pad((string) $row['sort'], 2, '0', STR_PAD_LEFT))->values()
            ->map(function (array $row) use (&$balance): array {
                $balance = D::sub(D::add($balance, $row['charge']), $row['payment']);

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
            $totalColumn = $type === Invoice::TYPE_SALE ? 'COALESCE(payable_total, total)' : 'total';

            $sum = Invoice::query()->active()->selectRaw("COALESCE(SUM({$totalColumn} - amount_paid".($type === Invoice::TYPE_SALE
                ? " + (SELECT COALESCE(SUM(r.total), 0) FROM invoices r WHERE r.original_invoice_id = invoices.id AND r.status = 'active' AND r.type = 'return')" : '').'), 0)')
                ->whereColumn('partner_id', 'partners.id')->where('type', $type)
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
            if ($type !== Invoice::TYPE_SALE) {
                return $sum;
            }
            $returns = Invoice::query()->active()->where('type', Invoice::TYPE_RETURN)
                ->whereColumn('partner_id', 'partners.id')
                ->whereHas('originalInvoice', fn ($q) => $q->active())
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
                ->selectRaw('COALESCE(SUM(total), 0)');

            return DB::query()->selectRaw('('.$sum->toSql().') - ('.$returns->toSql().')', [...$sum->getBindings(), ...$returns->getBindings()]);
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

    public function receivable(Partner $partner): string
    {
        if (! $partner->isCustomer()) {
            return '0.00';
        }

        return D::scale(D::max(0, $this->signedBalance($partner)));
    }

    public function payable(Partner $partner): string
    {
        if ($partner->isCustomer()) {
            return D::scale(D::max(0, D::sub(0, $this->signedBalance($partner))));
        }
        if (! $partner->isSupplier()) {
            return '0.00';
        }

        return D::scale(D::max(0, $this->signedBalance($partner)));
    }

    public function balanceFor(Partner $partner, string $side): string
    {
        return $side === 'payable' ? $this->payable($partner) : $this->receivable($partner);
    }

    /**
     * Positive means an amount remains due from the counterparty's normal
     * invoice flow; negative means the counterparty has a credit balance.
     */
    public function signedBalance(Partner $partner): string
    {
        if ($partner->isCustomer()) {
            return D::add(D::sub($partner->sales_due, $partner->incoming_total), $partner->outgoing_total);
        }

        if ($partner->isSupplier()) {
            return D::sub(D::sub($partner->purchases_due, $partner->outgoing_total), $partner->incoming_total);
        }

        return '0.00';
    }
}
