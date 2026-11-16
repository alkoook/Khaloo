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
    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'card', 'other'];

    public function recordSettlement(int $partnerId, string $side, mixed $amount, string $date, ?string $notes = null, ?string $paymentMethod = 'cash', ?int $invoiceId = null): LedgerSettlement
    {
        $amount = D::scale($amount);
        if (! in_array($side, ['receivable', 'payable'], true) || D::cmp($amount, 0) <= 0) {
            throw ValidationException::withMessages(['amount' => 'قيمة الدفعة غير صالحة.']);
        }
        $paymentMethod = $this->validatePaymentMethod($paymentMethod);

        return DB::transaction(function () use ($partnerId, $side, $amount, $date, $notes, $paymentMethod, $invoiceId): LedgerSettlement {
            Partner::query()->lockForUpdate()->findOrFail($partnerId);
            $partner = $this->partnerBalancesQuery()->findOrFail($partnerId);
            $amount = D::scale($amount);
            $invoice = $this->validateInvoiceReference($partner, $invoiceId);

            $settlement = LedgerSettlement::query()->create([
                'partner_id' => $partnerId,
                'invoice_id' => $invoice?->id,
                'amount' => $amount,
                'type' => $side === 'receivable' ? LedgerSettlement::TYPE_INCOMING : LedgerSettlement::TYPE_OUTGOING,
                'payment_method' => $paymentMethod,
                'settlement_date' => $date,
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ]);
            $settlement->audits()->create([
                'event' => 'created',
                'user_id' => auth()->id(),
                'new_values' => $this->settlementSnapshot($settlement),
            ]);

            return $settlement;
        }, 3);
    }

    public function updateSettlement(LedgerSettlement $settlement, mixed $amount, string $date, ?string $paymentMethod, ?string $notes = null, ?int $invoiceId = null): LedgerSettlement
    {
        $amount = D::scale($amount);
        if (D::cmp($amount, 0) <= 0) {
            throw ValidationException::withMessages(['editAmount' => 'قيمة الدفعة يجب أن تكون أكبر من صفر.']);
        }
        $paymentMethod = $this->validatePaymentMethod($paymentMethod, 'editPaymentMethod');

        return DB::transaction(function () use ($settlement, $amount, $date, $paymentMethod, $notes, $invoiceId): LedgerSettlement {
            $locked = LedgerSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            $partner = Partner::query()->lockForUpdate()->findOrFail($locked->partner_id);
            $old = $this->settlementSnapshot($locked);
            $invoice = $this->validateInvoiceReference($partner, $invoiceId);
            $locked->update([
                'invoice_id' => $invoice?->id,
                'amount' => $amount,
                'settlement_date' => $date,
                'payment_method' => $paymentMethod,
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ]);
            $locked->audits()->create([
                'event' => 'updated',
                'user_id' => auth()->id(),
                'old_values' => $old,
                'new_values' => $this->settlementSnapshot($locked->refresh()),
            ]);

            return $locked->refresh()->load('partner', 'invoice');
        }, 3);
    }

    public function cancelSettlement(LedgerSettlement $settlement, ?string $reason = null): void
    {
        DB::transaction(function () use ($settlement, $reason): void {
            $locked = LedgerSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            Partner::query()->lockForUpdate()->findOrFail($locked->partner_id);
            $old = $this->settlementSnapshot($locked);
            $locked->update([
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => filled($reason) ? trim((string) $reason) : 'إلغاء دفعة مدخلة بالخطأ.',
            ]);
            $locked->audits()->create([
                'event' => 'cancelled',
                'user_id' => auth()->id(),
                'old_values' => $old,
                'new_values' => $this->settlementSnapshot($locked),
                'reason' => $locked->cancellation_reason,
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

    public function statementRows(Partner $partner, ?string $from = null, ?string $to = null, ?string $movementType = null): Collection
    {
        $rows = collect();
        Invoice::query()->active()->with(['returns' => fn ($q) => $q->active()])->where('partner_id', $partner->id)
            ->where($partner->isCustomer() ? 'type' : 'type', $partner->isCustomer() ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE)
            ->get()
            ->each(function (Invoice $invoice) use ($rows, $partner): void {
                $legacyReturns = $partner->isCustomer() ? D::sum($invoice->returns->where('return_preserves_original', false)->pluck('total')) : '0.00';
                $gross = D::add($partner->isCustomer() ? $invoice->collectibleTotal() : $invoice->total, $legacyReturns);
                $rows->push(['date' => $invoice->created_at, 'sort' => 10, 'category' => 'invoice', 'type' => $partner->isCustomer() ? 'فاتورة بيع' : 'فاتورة شراء', 'reference' => '#'.$invoice->id, 'description' => 'إجمالي الفاتورة النهائي', 'charge' => $gross, 'payment' => '0.00']);
                if ($invoice->initial_paid > 0) {
                    $rows->push(['date' => $invoice->created_at, 'sort' => 20, 'category' => 'settlement', 'type' => $partner->isCustomer() ? 'دفعة من السيد' : 'دفعة للمورد', 'reference' => '#'.$invoice->id, 'description' => 'مدفوع عند إنشاء الفاتورة', 'charge' => '0.00', 'payment' => $invoice->initial_paid]);
                }
                foreach ($invoice->returns as $return) {
                    $rows->push(['date' => $return->accountingDate(), 'sort' => 30, 'category' => 'return', 'type' => 'مرتجع مبيعات', 'reference' => '#'.$return->id, 'description' => 'مرتجع من الفاتورة #'.$invoice->id, 'charge' => '0.00', 'payment' => $return->total]);
                }
            });
        LedgerSettlement::query()->where('partner_id', $partner->id)
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))->get()
            ->each(function (LedgerSettlement $settlement) use ($rows, $partner): void {
                $reduces = ($partner->isCustomer() && $settlement->type === LedgerSettlement::TYPE_INCOMING)
                    || $partner->isSupplier();
                $rows->push(['date' => $settlement->settlement_date, 'sort' => 40, 'category' => 'settlement', 'settlement_id' => $settlement->id, 'settlement_type' => $settlement->type, 'type' => $settlement->type === LedgerSettlement::TYPE_INCOMING ? 'مبلغ مستلم' : 'مبلغ مدفوع', 'reference' => 'دفعة-'.$settlement->id, 'description' => $settlement->notes ?: 'تسوية لاحقة', 'charge' => $reduces ? '0.00' : $settlement->amount, 'payment' => $reduces ? $settlement->amount : '0.00']);
            });

        $balance = '0.00';
        if ($from) {
            $before = now()->parse($from)->subDay()->toDateString();
            $opening = $this->partnerBalancesQuery(null, null, $before)->find($partner->id);
            $balance = $opening ? $this->signedBalance($opening) : '0.00';
        }

        return $rows->filter(fn ($row) => (! $from || $row['date']->toDateString() >= $from) && (! $to || $row['date']->toDateString() <= $to) && (! $movementType || $row['category'] === $movementType))->sortBy(fn ($row) => $row['date']->format('Y-m-d').'|'.str_pad((string) $row['sort'], 2, '0', STR_PAD_LEFT).'|'.$row['date']->format('H:i:s'))->values()
            ->map(function (array $row) use (&$balance): array {
                $balance = D::sub(D::add($balance, $row['charge']), $row['payment']);

                return $row + ['balance' => $balance];
            });
    }

    /** @return array{opening:string,rows:Collection,debit_total:string,credit_total:string,closing:string} */
    public function statementData(Partner $partner, ?string $from = null, ?string $to = null, ?string $movementType = null): array
    {
        $opening = '0.00';
        if ($from) {
            $before = now()->parse($from)->subDay()->toDateString();
            $row = $this->partnerBalancesQuery(null, null, $before)->find($partner->id);
            $opening = $row ? $this->signedBalance($row) : '0.00';
        }
        $allRows = $this->statementRows($partner, $from, $to);
        $rows = $movementType ? $allRows->where('category', $movementType)->values() : $allRows;
        $debit = D::sum($rows->pluck('charge'));
        $credit = D::sum($rows->pluck('payment'));

        return [
            'opening' => $opening,
            'rows' => $rows,
            'debit_total' => $debit,
            'credit_total' => $credit,
            'closing' => $allRows->last()['balance'] ?? $opening,
        ];
    }

    /**
     * @return array{opening:string,rows:Collection,invoices_total:string,payments_total:string,returns_total:string,closing:string}
     */
    public function thermalStatementData(Partner $partner, ?string $from = null, ?string $to = null): array
    {
        $statement = $this->statementData($partner, $from, $to);
        $rows = $statement['rows']->map(function (array $row) use ($partner): array {
            return [
                'date' => $row['date'],
                'description' => $this->thermalDescription($partner, $row),
                'amount' => D::sub($row['charge'], $row['payment']),
                'balance' => $row['balance'],
                'category' => $row['category'],
                'charge' => $row['charge'],
                'payment' => $row['payment'],
                'is_payment' => $row['category'] === 'settlement' && (($row['sort'] ?? null) === 20
                    || ($partner->isCustomer() && ($row['settlement_type'] ?? null) === LedgerSettlement::TYPE_INCOMING)
                    || ($partner->isSupplier() && ($row['settlement_type'] ?? null) === LedgerSettlement::TYPE_OUTGOING)),
            ];
        })->filter(fn (array $row) => D::cmp($row['amount'], 0) !== 0)->values();

        return [
            'opening' => $statement['opening'],
            'rows' => $rows,
            'invoices_total' => D::sum($rows->where('category', 'invoice')->pluck('charge')),
            'payments_total' => D::sum($rows->where('is_payment', true)->map(fn (array $row) => D::max($row['charge'], $row['payment']))),
            'returns_total' => D::sum($rows->where('category', 'return')->pluck('payment')),
            'closing' => $statement['closing'],
        ];
    }

    private function thermalDescription(Partner $partner, array $row): string
    {
        $number = ltrim((string) ($row['reference'] ?? ''), '#');
        if ($row['category'] === 'invoice') {
            return ($partner->isCustomer() ? 'فاتورة مبيعات #' : 'فاتورة شراء #').$number;
        }
        if ($row['category'] === 'return') {
            return 'مرتجع مبيعات #'.$number;
        }
        if (($row['sort'] ?? null) === 20) {
            return $partner->isCustomer() ? 'دفعة مستلمة عند الفاتورة' : 'دفعة للمورد عند الفاتورة';
        }

        if ($partner->isCustomer()) {
            if (($row['settlement_type'] ?? null) === LedgerSettlement::TYPE_INCOMING) {
                return 'دفعة مستلمة';
            }

            return str_contains((string) ($row['description'] ?? ''), 'استرداد نقدي') ? 'استرداد نقدي' : 'تصحيح دفعة';
        }

        return ($row['settlement_type'] ?? null) === LedgerSettlement::TYPE_OUTGOING
            ? 'دفعة للمورد'
            : 'مبلغ مسترد من المورد / تصحيح دفعة';
    }

    /**
     * amount_paid is the payment captured on the invoice; initial_paid is its
     * historical snapshot. Later payments and refunds are settlements. Linked
     * Legacy returns reduced the original invoice and are reconstructed once.
     * New independent returns leave the issued invoice untouched and are only credits.
     */
    public function partnerBalancesQuery(?string $search = null, ?string $from = null, ?string $to = null): Builder
    {
        $invoiceSum = function (string $type) use ($from, $to) {
            $totalColumn = $type === Invoice::TYPE_SALE ? 'COALESCE(payable_total, total)' : 'total';

            $sum = Invoice::query()->active()->selectRaw("COALESCE(SUM({$totalColumn} - amount_paid".($type === Invoice::TYPE_SALE
                ? " + (SELECT COALESCE(SUM(r.total), 0) FROM invoices r WHERE r.original_invoice_id = invoices.id AND r.status = 'active' AND r.type = 'return' AND r.return_preserves_original = 0)" : '').'), 0)')
                ->whereColumn('partner_id', 'partners.id')->where('type', $type)
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
            if ($type !== Invoice::TYPE_SALE) {
                return $sum;
            }
            $returns = Invoice::query()->active()->where('type', Invoice::TYPE_RETURN)
                ->whereColumn('partner_id', 'partners.id')
                ->whereHas('originalInvoice', fn ($q) => $q->active())
                ->returnAccountingPeriod($from, $to)
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

    private function validatePaymentMethod(?string $method, string $field = 'paymentMethod'): string
    {
        $method = filled($method) ? (string) $method : 'cash';
        if (! in_array($method, self::PAYMENT_METHODS, true)) {
            throw ValidationException::withMessages([$field => 'طريقة الدفع المحددة غير صالحة.']);
        }

        return $method;
    }

    private function validateInvoiceReference(Partner $partner, ?int $invoiceId): ?Invoice
    {
        if (! $invoiceId) {
            return null;
        }
        $invoice = Invoice::query()->active()->lockForUpdate()->find($invoiceId);
        $validType = $partner->isCustomer() ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE;
        if (! $invoice || $invoice->partner_id !== $partner->id || $invoice->type !== $validType) {
            throw ValidationException::withMessages(['invoiceId' => 'مرجع الفاتورة لا يتطابق مع الطرف ونوع الدفعة.']);
        }

        return $invoice;
    }

    private function settlementBalanceEffect(Partner $partner, string $type): int
    {
        return $partner->isCustomer() && $type === LedgerSettlement::TYPE_OUTGOING ? 1 : -1;
    }

    private function settlementSnapshot(LedgerSettlement $settlement): array
    {
        return $settlement->only([
            'partner_id', 'invoice_id', 'amount', 'type', 'payment_method', 'settlement_date', 'notes',
            'cancelled_at', 'cancelled_by', 'cancellation_reason',
        ]);
    }
}
