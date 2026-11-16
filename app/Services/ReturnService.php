<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Product;
use App\Support\Decimal as D;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnService
{
    /** A return is independent: it never rewrites the issued sale or its lines. */
    public function processSalesReturn(
        Invoice $originalInvoice,
        mixed $refundAmount,
        array $returnQuantities,
        ?string $requestKey = null,
        ?string $returnDate = null,
        ?string $notes = null,
    ): Invoice {
        $this->assertOriginalSale($originalInvoice);
        $refundAmount = $this->normalizeRefund($refundAmount);
        $returnDate = $this->normalizeReturnDate($originalInvoice, $returnDate);
        if ($requestKey !== null && ! Str::isUuid($requestKey)) {
            throw new BusinessRuleException('طلب المرتجع غير صالح.');
        }

        [$invoice, $notificationLines, $replayed] = DB::transaction(function () use (
            $originalInvoice, $refundAmount, $returnQuantities, $requestKey, $returnDate, $notes
        ): array {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($originalInvoice->id);
            $this->assertOriginalSale($lockedInvoice);

            if ($requestKey !== null) {
                $existing = Invoice::query()->where('return_request_key', $requestKey)->first();
                if ($existing) {
                    if ((int) $existing->original_invoice_id !== (int) $lockedInvoice->id) {
                        throw new BusinessRuleException('طلب المرتجع غير صالح.');
                    }

                    return [$existing, [], true];
                }
            }

            $items = $this->calculateItems($this->lockedReturnContext($lockedInvoice), $returnQuantities);
            [$subtotal, $total, $discountAmount] = $this->totals($items);
            $this->assertRefundAllowed($lockedInvoice, null, $total, $refundAmount);

            $returnInvoice = Invoice::query()->create([
                'partner_id' => $lockedInvoice->partner_id,
                'original_invoice_id' => $lockedInvoice->id,
                'type' => Invoice::TYPE_RETURN,
                'subtotal' => $subtotal,
                'discount_type' => D::cmp($discountAmount, 0) > 0 ? 'fixed' : null,
                'discount_value' => $discountAmount,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'initial_paid' => $refundAmount,
                'amount_paid' => $refundAmount,
                'return_request_key' => $requestKey,
                'return_date' => $returnDate,
                'notes' => $this->normalizeNotes($notes),
                'return_preserves_original' => true,
            ]);

            $notificationLines = $this->createLinesAndRestoreStock($returnInvoice, $items);
            $this->syncRefundSettlement($returnInvoice, null, $refundAmount, $returnDate);
            $returnInvoice->audits()->create([
                'event' => 'created',
                'user_id' => auth()->id(),
                'new_values' => $this->snapshot($returnInvoice->load('lines')),
            ]);

            return [$returnInvoice->load('partner', 'lines'), $notificationLines, false];
        }, 3);

        if ($replayed) {
            return $invoice;
        }

        app(TelegramService::class)->sendInvoiceNotification(
            Invoice::TYPE_RETURN,
            $invoice->partner?->name ?? 'السيد النقدي',
            number_format((float) $invoice->total, 2),
            number_format((float) $refundAmount, 2),
            D::format(D::sub($invoice->total, $refundAmount)),
            (string) $invoice->id,
            $notificationLines,
        );
        $this->evaluateAlerts($invoice);

        return $invoice;
    }

    public function updateSalesReturn(
        Invoice $returnInvoice,
        mixed $refundAmount,
        array $returnQuantities,
        string $returnDate,
        ?string $notes = null,
    ): Invoice {
        if (! $returnInvoice->isReturn() || $returnInvoice->isCancelled()) {
            throw new BusinessRuleException('لا يمكن تعديل مستند ليس مرتجع مبيعات نشطًا.');
        }
        if (! $returnInvoice->return_preserves_original) {
            throw new BusinessRuleException('لا يمكن تعديل هذا المرتجع القديم بأمان لأنه أُنشئ قبل اعتماد المستندات المستقلة.');
        }
        $refundAmount = $this->normalizeRefund($refundAmount);

        $updated = DB::transaction(function () use ($returnInvoice, $refundAmount, $returnQuantities, $returnDate, $notes): Invoice {
            $lockedReturn = Invoice::query()->lockForUpdate()->findOrFail($returnInvoice->id);
            if (! $lockedReturn->isReturn() || $lockedReturn->isCancelled()) {
                throw new BusinessRuleException('لا يمكن تعديل مستند ليس مرتجع مبيعات نشطًا.');
            }
            $original = Invoice::query()->lockForUpdate()->findOrFail($lockedReturn->original_invoice_id);
            $this->assertOriginalSale($original);
            $returnDate = $this->normalizeReturnDate($original, $returnDate);
            $oldLines = InvoiceLine::query()->where('invoice_id', $lockedReturn->id)->lockForUpdate()->get();
            if ($oldLines->groupBy('product_id')->contains(fn (Collection $lines) => $lines->count() > 1)) {
                throw new BusinessRuleException('تعذر تعديل المرتجع لأن بيانات أصنافه القديمة تحتاج مراجعة يدوية.');
            }
            $oldRefund = D::scale($lockedReturn->amount_paid);
            $oldSnapshot = $this->snapshot($lockedReturn->setRelation('lines', $oldLines));
            $items = $this->calculateItems($this->lockedReturnContext($original, $lockedReturn->id), $returnQuantities);
            [$subtotal, $total, $discountAmount] = $this->totals($items);
            $this->assertRefundAllowed($original, $lockedReturn, $total, $refundAmount);

            $products = Product::query()->whereIn('id', $oldLines->pluck('product_id')->merge(collect($items)->pluck('product_id'))->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $oldByProduct = $oldLines->keyBy('product_id');
            $newByProduct = collect($items)->keyBy('product_id');

            foreach ($oldByProduct->keys()->merge($newByProduct->keys())->unique() as $productId) {
                $product = $products->get($productId);
                if (! $product) {
                    throw new BusinessRuleException('أحد منتجات المرتجع لم يعد موجودًا.');
                }
                $oldLine = $oldByProduct->get($productId);
                $newItem = $newByProduct->get($productId);
                $delta = D::sub($newItem['quantity'] ?? 0, $oldLine?->quantity ?? 0, 3);
                if (D::cmp($delta, 0) > 0) {
                    app(InventoryCostService::class)->restoreSaleStock($product, $delta, $newItem['purchase_price']);
                } elseif (D::cmp($delta, 0) < 0) {
                    app(InventoryCostService::class)->removeRestoredSaleStock($product, D::sub(0, $delta, 3), $oldLine?->purchase_price);
                }
            }

            foreach ($items as $item) {
                $values = $this->lineValues($lockedReturn, $item);
                $existingLine = $oldByProduct->get($item['product_id']);
                $existingLine ? $existingLine->update($values) : InvoiceLine::query()->create($values);
            }
            $removedIds = $oldLines->whereNotIn('product_id', $newByProduct->keys())->pluck('id');
            if ($removedIds->isNotEmpty()) {
                InvoiceLine::query()->whereIn('id', $removedIds)->delete();
            }

            $lockedReturn->update([
                'subtotal' => $subtotal,
                'discount_type' => D::cmp($discountAmount, 0) > 0 ? 'fixed' : null,
                'discount_value' => $discountAmount,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'initial_paid' => $refundAmount,
                'amount_paid' => $refundAmount,
                'return_date' => $returnDate,
                'notes' => $this->normalizeNotes($notes),
            ]);
            $this->syncRefundSettlement($lockedReturn, $oldRefund, $refundAmount, $returnDate);
            $fresh = $lockedReturn->refresh()->load('partner', 'lines');
            $fresh->audits()->create([
                'event' => 'updated',
                'user_id' => auth()->id(),
                'old_values' => $oldSnapshot,
                'new_values' => $this->snapshot($fresh),
            ]);

            return $fresh;
        }, 3);

        $this->evaluateAlerts($updated);

        return $updated;
    }

    public function cancelSalesReturn(Invoice $returnInvoice, string $reason): Invoice
    {
        if (! $returnInvoice->isReturn()) {
            throw new BusinessRuleException('المستند المحدد ليس مرتجع مبيعات.');
        }
        if (! $returnInvoice->return_preserves_original) {
            throw new BusinessRuleException('لا يمكن إلغاء هذا المرتجع القديم آليًا لأنه أُنشئ قبل اعتماد المستندات المستقلة.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BusinessRuleException('اكتب سببًا واضحًا لإلغاء المرتجع.');
        }

        $cancelled = DB::transaction(function () use ($returnInvoice, $reason): Invoice {
            $lockedReturn = Invoice::query()->lockForUpdate()->findOrFail($returnInvoice->id);
            if ($lockedReturn->isCancelled()) {
                throw new BusinessRuleException('هذا المرتجع ملغى بالفعل.');
            }
            $lines = InvoiceLine::query()->where('invoice_id', $lockedReturn->id)->orderBy('id')->lockForUpdate()->get();
            $products = Product::query()->whereIn('id', $lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $before = $this->snapshot($lockedReturn->setRelation('lines', $lines));

            foreach ($lines as $line) {
                $product = $products->get($line->product_id);
                if (! $product) {
                    throw new BusinessRuleException('أحد منتجات المرتجع لم يعد موجودًا، لذلك لا يمكن عكس المخزون بأمان.');
                }
                app(InventoryCostService::class)->removeRestoredSaleStock($product, $line->quantity, $line->purchase_price);
            }
            $this->syncRefundSettlement(
                $lockedReturn,
                $lockedReturn->amount_paid,
                '0.00',
                $lockedReturn->accountingDate()->format('Y-m-d'),
            );
            $lockedReturn->update([
                'status' => Invoice::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => $reason,
            ]);
            $fresh = $lockedReturn->refresh()->load('partner', 'lines');
            $fresh->audits()->create([
                'event' => 'cancelled', 'user_id' => auth()->id(), 'old_values' => $before,
                'new_values' => $this->snapshot($fresh), 'reason' => $reason,
            ]);

            return $fresh;
        }, 3);

        $this->evaluateAlerts($cancelled);

        return $cancelled;
    }

    /** Data shared by create and edit; the edited return is excluded from previous quantities. */
    public function returnableLines(Invoice $originalInvoice, ?Invoice $editingReturn = null): array
    {
        $originalInvoice->loadMissing('lines', 'returns.lines');
        $editingReturn?->loadMissing('lines');
        $returns = $originalInvoice->returns->where('status', Invoice::STATUS_ACTIVE);

        return $this->buildContext($originalInvoice->lines, $returns, $editingReturn?->id)['rows']->map(function (array $row) use ($editingReturn): array {
            $currentQuantity = $editingReturn
                ? D::sum($editingReturn->lines->where('product_id', $row['product_id'])->pluck('quantity'), 3)
                : '0.000';

            return [
                'id' => $row['line']->id,
                'product_id' => $row['product_id'],
                'product_name' => $row['line']->product_name,
                'effective_unit_price' => $row['effective_unit_price'],
                'sold_quantity' => $row['sold_quantity'],
                'returned_quantity' => $row['other_returned_quantity'],
                'available_quantity' => $row['available_quantity'],
                'current_quantity' => $currentQuantity,
            ];
        })->values()->all();
    }

    private function lockedReturnContext(Invoice $originalInvoice, ?int $excludeReturnId = null): array
    {
        $saleLines = InvoiceLine::query()->where('invoice_id', $originalInvoice->id)->lockForUpdate()->get();
        $returns = Invoice::query()->active()->where('type', Invoice::TYPE_RETURN)
            ->where('original_invoice_id', $originalInvoice->id)->orderBy('id')->lockForUpdate()->get();
        $returnLines = $returns->isEmpty() ? collect() : InvoiceLine::query()
            ->whereIn('invoice_id', $returns->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($returns as $return) {
            $return->setRelation('lines', $returnLines->where('invoice_id', $return->id)->values());
        }

        return $this->buildContext($saleLines, $returns, $excludeReturnId);
    }

    private function buildContext(Collection $saleLines, Collection $returns, ?int $excludeReturnId): array
    {
        $legacyLines = $returns->where('return_preserves_original', false)->flatMap->lines;
        $otherReturns = $excludeReturnId ? $returns->where('id', '!=', $excludeReturnId) : $returns;
        $otherLines = $otherReturns->flatMap->lines;

        $rows = $saleLines->map(function (InvoiceLine $line) use ($legacyLines, $otherLines): array {
            $legacyForProduct = $legacyLines->where('product_id', $line->product_id);
            $otherForProduct = $otherLines->where('product_id', $line->product_id);
            $soldQuantity = D::add($line->quantity, D::sum($legacyForProduct->pluck('quantity'), 3), 3);
            $historicalGross = D::add($line->line_total, D::sum($legacyForProduct->pluck('line_total')));
            $historicalNet = D::add(
                $line->net_line_total ?? $line->line_total,
                D::sum($legacyForProduct->map(fn (InvoiceLine $item) => $item->net_line_total ?? $item->line_total))
            );
            $otherReturnedQuantity = D::sum($otherForProduct->pluck('quantity'), 3);

            return [
                'line' => $line,
                'product_id' => $line->product_id,
                'sold_quantity' => $soldQuantity,
                'historical_gross' => $historicalGross,
                'historical_net' => $historicalNet,
                'other_returned_quantity' => $otherReturnedQuantity,
                'other_returned_gross' => D::sum($otherForProduct->pluck('line_total')),
                'other_returned_net' => D::sum($otherForProduct->map(fn (InvoiceLine $item) => $item->net_line_total ?? $item->line_total)),
                'available_quantity' => D::sub($soldQuantity, $otherReturnedQuantity, 3),
                'effective_unit_price' => D::cmp($soldQuantity, 0) > 0 ? D::div($historicalNet, $soldQuantity) : D::scale($line->unit_price),
            ];
        });

        return ['rows' => $rows];
    }

    private function calculateItems(array $context, array $returnQuantities): array
    {
        $items = [];
        foreach ($context['rows'] as $row) {
            try {
                $quantity = D::quantity($returnQuantities[$row['line']->id] ?? 0);
            } catch (\Throwable) {
                throw new BusinessRuleException('كمية الإرجاع يجب أن تكون رقمًا صالحًا حتى ثلاث منازل عشرية.');
            }
            if (D::cmp($quantity, 0) < 0) {
                throw new BusinessRuleException('كمية الإرجاع لا يمكن أن تكون سالبة.');
            }
            if (D::cmp($quantity, $row['available_quantity']) > 0) {
                throw new BusinessRuleException('لا يمكن تسجيل كمية الإرجاع المطلوبة لأن إجمالي المرتجعات سيتجاوز الكمية المباعة.');
            }
            if (D::cmp($quantity, 0) === 0) {
                continue;
            }

            $remainingGross = D::sub($row['historical_gross'], $row['other_returned_gross']);
            $remainingNet = D::sub($row['historical_net'], $row['other_returned_net']);
            $lineTotal = D::cmp($quantity, $row['available_quantity']) === 0
                ? D::scale($remainingGross)
                : D::min($remainingGross, D::div(D::of($row['historical_gross'])->multipliedBy($quantity), $row['sold_quantity']));
            $netLineTotal = D::cmp($quantity, $row['available_quantity']) === 0
                ? D::scale($remainingNet)
                : D::min($remainingNet, D::div(D::of($row['historical_net'])->multipliedBy($quantity), $row['sold_quantity']));

            $items[] = [
                'line' => $row['line'],
                'product_id' => $row['product_id'],
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'discount_amount' => D::sub($lineTotal, $netLineTotal),
                'net_line_total' => $netLineTotal,
                'purchase_price' => $row['line']->purchase_price,
            ];
        }
        if ($items === []) {
            throw new BusinessRuleException('حدد كمية لمنتج واحد على الأقل.');
        }

        return $items;
    }

    private function totals(array $items): array
    {
        $subtotal = D::sum(array_column($items, 'line_total'));
        $total = D::sum(array_column($items, 'net_line_total'));

        return [$subtotal, $total, D::sub($subtotal, $total)];
    }

    private function createLinesAndRestoreStock(Invoice $returnInvoice, array $items): array
    {
        $products = Product::query()->whereIn('id', collect($items)->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $notificationLines = [];
        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                throw new BusinessRuleException('أحد منتجات المرتجع لم يعد موجودًا.');
            }
            InvoiceLine::query()->create($this->lineValues($returnInvoice, $item));
            app(InventoryCostService::class)->restoreSaleStock($product, $item['quantity'], $item['purchase_price']);
            $notificationLines[] = [
                'product_id' => $product->id,
                'product_name' => $item['line']->product_name,
                'quantity' => $item['quantity'],
                'unit_price' => $item['line']->unit_price,
                'line_total' => $item['net_line_total'],
            ];
        }

        return $notificationLines;
    }

    private function lineValues(Invoice $returnInvoice, array $item): array
    {
        return [
            'invoice_id' => $returnInvoice->id,
            'product_id' => $item['product_id'],
            'product_name' => $item['line']->product_name,
            'quantity' => $item['quantity'],
            'unit_price' => $item['line']->unit_price,
            'purchase_price' => $item['purchase_price'],
            'sale_price' => $item['line']->sale_price,
            'line_total' => $item['line_total'],
            'discount_amount' => $item['discount_amount'],
            'net_line_total' => $item['net_line_total'],
        ];
    }

    private function assertRefundAllowed(Invoice $original, ?Invoice $editingReturn, string $total, string $refund): void
    {
        if (D::cmp($refund, $total) > 0) {
            throw new BusinessRuleException('المبلغ المردود نقدًا لا يمكن أن يتجاوز إجمالي المرتجع.');
        }

        if ($original->partner_id) {
            $partner = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($original->partner_id);
            $baseline = app(FinancialService::class)->signedBalance($partner);
            if ($editingReturn) {
                $baseline = D::sub(D::add($baseline, $editingReturn->total), $editingReturn->amount_paid);
            }
        } else {
            $otherReturns = $original->returns()->active()->when($editingReturn, fn ($q) => $q->whereKeyNot($editingReturn->id))->get();
            $baseline = D::add(
                D::sub(D::sub($original->collectibleTotal(), $original->amount_paid), D::sum($otherReturns->pluck('total'))),
                D::sum($otherReturns->pluck('amount_paid'))
            );
        }
        $maxRefund = D::max(0, D::sub($total, D::max(0, $baseline)));
        if (D::cmp($refund, $maxRefund) > 0) {
            throw new BusinessRuleException('المبلغ المردود نقدًا أكبر من الرصيد المستحق للسيد بعد تسوية قيمة المرتجع.');
        }
    }

    private function syncRefundSettlement(Invoice $returnInvoice, mixed $oldRefund, string $newRefund, string $date): void
    {
        if (! $returnInvoice->partner_id) {
            return;
        }
        $settlement = $this->refundSettlement($returnInvoice, D::scale($oldRefund ?? 0));
        if (D::cmp($newRefund, 0) === 0) {
            if ($settlement) {
                $old = $this->settlementSnapshot($settlement);
                $settlement->update([
                    'cancelled_at' => now(),
                    'cancelled_by' => auth()->id(),
                    'cancellation_reason' => 'إلغاء الاسترداد النقدي ضمن تعديل المرتجع.',
                ]);
                $settlement->audits()->create([
                    'event' => 'cancelled', 'user_id' => auth()->id(), 'old_values' => $old,
                    'new_values' => $this->settlementSnapshot($settlement), 'reason' => $settlement->cancellation_reason,
                ]);
            }

            return;
        }

        $values = [
            'partner_id' => $returnInvoice->partner_id,
            'invoice_id' => null,
            'return_invoice_id' => $returnInvoice->id,
            'amount' => $newRefund,
            'type' => LedgerSettlement::TYPE_OUTGOING,
            'payment_method' => 'cash',
            'settlement_date' => $date,
            'notes' => "استرداد نقدي للمرتجع #{$returnInvoice->id} من فاتورة #{$returnInvoice->original_invoice_id}",
        ];
        if ($settlement) {
            $old = $this->settlementSnapshot($settlement);
            $settlement->update($values);
            $settlement->audits()->create([
                'event' => 'updated', 'user_id' => auth()->id(), 'old_values' => $old,
                'new_values' => $this->settlementSnapshot($settlement->refresh()),
            ]);
        } else {
            $settlement = LedgerSettlement::query()->create($values);
            $settlement->audits()->create([
                'event' => 'created', 'user_id' => auth()->id(), 'new_values' => $this->settlementSnapshot($settlement),
            ]);
        }
    }

    private function refundSettlement(Invoice $returnInvoice, string $oldRefund): ?LedgerSettlement
    {
        $linked = LedgerSettlement::query()->where('return_invoice_id', $returnInvoice->id)->lockForUpdate()->first();
        if ($linked || D::cmp($oldRefund, 0) === 0) {
            return $linked;
        }

        $legacy = LedgerSettlement::query()->where('partner_id', $returnInvoice->partner_id)
            ->where('invoice_id', $returnInvoice->original_invoice_id)
            ->where('type', LedgerSettlement::TYPE_OUTGOING)
            ->where('amount', $oldRefund)->where('notes', 'like', '%استرداد نقدي%')->lockForUpdate()->get();
        if ($legacy->count() !== 1) {
            throw new BusinessRuleException('تعذر تحديد حركة الاسترداد النقدي القديمة بأمان؛ راجع سجل الدفعات قبل تعديل المرتجع.');
        }

        return $legacy->first();
    }

    private function normalizeRefund(mixed $amount): string
    {
        try {
            $amount = D::scale($amount);
        } catch (\Throwable) {
            throw new BusinessRuleException('المبلغ المردود نقدًا يجب أن يكون رقمًا صالحًا.');
        }
        if (D::cmp($amount, 0) < 0) {
            throw new BusinessRuleException('المبلغ المردود نقدًا لا يمكن أن يكون سالبًا.');
        }

        return $amount;
    }

    private function normalizeReturnDate(Invoice $originalInvoice, ?string $date): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $date ?: now()->toDateString())->format('Y-m-d');
        } catch (\Throwable) {
            throw new BusinessRuleException('تاريخ المرتجع غير صالح.');
        }
        if ($date < $originalInvoice->created_at->toDateString()) {
            throw new BusinessRuleException('تاريخ المرتجع لا يمكن أن يسبق تاريخ فاتورة البيع الأصلية.');
        }
        if ($date > now()->toDateString()) {
            throw new BusinessRuleException('تاريخ المرتجع لا يمكن أن يكون في المستقبل.');
        }

        return $date;
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : $notes;
    }

    private function assertOriginalSale(Invoice $invoice): void
    {
        if (! $invoice->isSale()) {
            throw new BusinessRuleException('يمكن إرجاع فواتير المبيعات فقط.');
        }
        if ($invoice->isCancelled()) {
            throw new BusinessRuleException('لا يمكن إنشاء أو تعديل مرتجع لفاتورة ملغاة.');
        }
    }

    private function snapshot(Invoice $return): array
    {
        return [
            'return_id' => $return->id,
            'original_invoice_id' => $return->original_invoice_id,
            'return_date' => optional($return->return_date)->format('Y-m-d'),
            'subtotal' => D::scale($return->subtotal),
            'discount_amount' => D::scale($return->discount_amount),
            'total' => D::scale($return->total),
            'cash_refund' => D::scale($return->amount_paid),
            'account_credit_effect' => D::sub($return->total, $return->amount_paid),
            'notes' => $return->notes,
            'status' => $return->status,
            'cancelled_at' => optional($return->cancelled_at)->toISOString(),
            'cancelled_by' => $return->cancelled_by,
            'cancellation_reason' => $return->cancellation_reason,
            'lines' => $return->lines->map(fn (InvoiceLine $line) => $line->only([
                'id', 'product_id', 'product_name', 'quantity', 'unit_price', 'purchase_price',
                'line_total', 'discount_amount', 'net_line_total',
            ]))->values()->all(),
        ];
    }

    private function settlementSnapshot(LedgerSettlement $settlement): array
    {
        return $settlement->only([
            'partner_id', 'invoice_id', 'return_invoice_id', 'amount', 'type', 'payment_method',
            'settlement_date', 'notes', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        ]);
    }

    private function evaluateAlerts(Invoice $return): void
    {
        app(AlertService::class)->evaluateProducts($return->lines->pluck('product_id')->filter());
        if ($return->partner_id) {
            app(AlertService::class)->evaluatePartners($return->partner_id);
        }
    }
}
