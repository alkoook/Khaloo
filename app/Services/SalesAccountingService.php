<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\Decimal as D;
use Illuminate\Support\Collection;

class SalesAccountingService
{
    /** Return documents are separate events; legacy mutated sales are reconstructed once. */
    public function profitability(Invoice $invoice): array
    {
        $invoice->loadMissing('lines');
        $active = $invoice->isSale() && ! $invoice->isCancelled();
        $returns = $active ? $invoice->returns()->active()->with('lines')->get() : collect();
        $legacyReturns = $returns->where('return_preserves_original', false);
        $lines = $active ? $invoice->lines->concat($legacyReturns->flatMap->lines) : collect();
        $grossCost = $this->cost($lines);
        $returnCost = $this->cost($returns->flatMap->lines);
        $cost = ['total' => D::sub($grossCost['total'], $returnCost['total'], 7), 'unknown' => $grossCost['unknown'] + $returnCost['unknown']];
        $net = $active ? D::sub(D::add($invoice->total, D::sum($legacyReturns->pluck('total'))), D::sum($returns->pluck('total'))) : '0.00';
        $delivery = $active ? D::scale($invoice->delivery_amount) : '0.00';
        $revenue = D::add($net, $delivery);
        $profit = $cost['unknown'] ? null : D::sub($revenue, $cost['total']);

        return ['net_merchandise' => $net, 'delivery' => $delivery, 'cogs' => $cost['unknown'] ? null : D::scale($cost['total']),
            'profit' => $profit, 'margin' => $profit !== null && D::cmp($revenue, 0) > 0
                ? D::div(D::of($profit)->multipliedBy(100), $revenue) : null,
            'unknown_cost_lines' => $cost['unknown'], 'active' => $active];
    }

    /**
     * Reconstruct the issue event, then reverse each return on its own date.
     * This also restores quantities/revenue returned AFTER a historical reporting period.
     */
    public function activity(?string $from = null, ?string $to = null, ?int $partnerId = null, ?int $categoryId = null): Collection
    {
        $events = collect();
        $sales = Invoice::query()->active()->where('type', Invoice::TYPE_SALE)
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->when($from || $to, fn ($q) => $q->where(function ($q) use ($from, $to) {
                $q->where(fn ($q) => $q->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                    ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to)))
                    ->orWhereHas('returns', fn ($q) => $q->active()->returnAccountingPeriod($from, $to));
            }))
            ->with(['partner', 'settlements', 'lines.product', 'returns' => fn ($q) => $q->active(), 'returns.lines.product'])->get();
        foreach ($sales as $sale) {
            $selectLines = fn ($lines) => $categoryId ? $lines->filter(fn ($line) => (int) $line->product?->category_id === $categoryId) : $lines;
            $issued = $selectLines($sale->lines);
            $legacyReturned = $sale->returns->where('return_preserves_original', false)
                ->flatMap(fn ($return) => $selectLines($return->lines));
            $allLines = $issued->concat($legacyReturned);
            $cost = $this->cost($allLines);
            $merchandise = $categoryId ? D::sum($allLines->map(fn ($line) => $line->net_line_total ?? $line->line_total))
                : D::add($sale->total, D::sum($sale->returns->where('return_preserves_original', false)->pluck('total')));
            $subtotal = D::sum($allLines->pluck('line_total'));
            if (! $categoryId && D::cmp($sale->subtotal, 0) > 0) {
                $subtotal = D::add($sale->subtotal, D::sum($sale->returns->where('return_preserves_original', false)->pluck('subtotal')));
            }
            if ($this->inPeriod($sale->created_at->toDateString(), $from, $to) && (! $categoryId || $allLines->isNotEmpty())) {
                $events->push(['invoice' => $sale, 'date' => $sale->created_at, 'merchandise' => $merchandise,
                    'delivery' => $categoryId ? '0.00' : D::scale($sale->delivery_amount),
                    'cost' => $cost['total'], 'unknown' => $cost['unknown'], 'quantity' => D::sum($allLines->pluck('quantity'), 3),
                    'subtotal' => $subtotal, 'discount' => D::sub($subtotal, $merchandise),
                    'line_count' => $allLines->unique(fn ($line) => $line->product_id ?? $line->product_name)->count()]);
            }
            foreach ($sale->returns as $return) {
                $lines = $selectLines($return->lines);
                if (! $this->inPeriod($return->accountingDate()->toDateString(), $from, $to) || ($categoryId && $lines->isEmpty())) {
                    continue;
                }
                $cost = $this->cost($lines);
                $net = $categoryId ? D::sum($lines->map(fn ($line) => $line->net_line_total ?? $line->line_total)) : $return->total;
                $returnSubtotal = D::sum($lines->pluck('line_total'));
                $events->push(['invoice' => $return, 'date' => $return->accountingDate(), 'merchandise' => D::sub(0, $net),
                    'delivery' => '0.00', 'cost' => D::sub(0, $cost['total'], 7), 'unknown' => $cost['unknown'],
                    'quantity' => D::sub(0, D::sum($lines->pluck('quantity'), 3), 3),
                    'subtotal' => D::sub(0, $returnSubtotal), 'discount' => D::sub(0, D::sub($returnSubtotal, $net)), 'line_count' => $lines->count()]);
            }
        }

        return $events->sortBy('date')->values();
    }

    private function cost(Collection $lines): array
    {
        $positive = $lines->filter(fn ($line) => D::cmp($line->quantity, 0) > 0);

        return ['total' => D::sum($positive->map(fn ($line) => D::mul($line->quantity, $line->purchase_price ?? 0, 7)), 7),
            'unknown' => $positive->whereNull('purchase_price')->count()];
    }

    private function inPeriod(string $date, ?string $from, ?string $to): bool
    {
        return (! $from || $date >= $from) && (! $to || $date <= $to);
    }
}
