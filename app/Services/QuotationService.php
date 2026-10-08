<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function save(array $data, array $lines, ?Quotation $quotation = null): Quotation
    {
        return DB::transaction(function () use ($data, $lines, $quotation): Quotation {
            $quotation ??= new Quotation;
            if (array_key_exists('expires_at', $data) && blank($data['expires_at'])) {
                $data['expires_at'] = null;
            }
            $pricing = app(InvoicePricingService::class)->calculate(
                $lines,
                $data['discount_type'] ?? InvoicePricingService::FIXED,
                $data['discount_value'] ?? null,
            );
            if (! $quotation->exists) {
                $data['number'] = $this->nextNumber();
            }
            $quotation->fill(array_merge($data, [
                'subtotal' => $pricing['subtotal'],
                'discount_type' => $pricing['discount_type'],
                'discount_value' => $pricing['discount_value'],
                'discount_amount' => $pricing['discount_amount'],
                'discount' => $pricing['discount_amount'],
                'total' => $pricing['total'],
            ]))->save();
            $quotation->lines()->delete();
            foreach ($lines as $line) {
                $product = Product::query()->find($line['product_id']);
                $quotation->lines()->create([
                    'product_id' => $product?->id,
                    'product_name' => $product?->name ?: $line['product_name'],
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => (float) $line['unit_price'],
                    'line_total' => round((int) $line['quantity'] * (float) $line['unit_price'], 2),
                ]);
            }

            return $quotation->refresh()->load('lines', 'partner');
        });
    }

    public function convertToSale(Quotation $quotation, float $amountPaid): Invoice
    {
        $invoice = DB::transaction(function () use ($quotation, $amountPaid): Invoice {
            $locked = Quotation::query()->with('lines')->lockForUpdate()->findOrFail($quotation->id);
            if ($locked->converted_invoice_id) {
                return Invoice::findOrFail($locked->converted_invoice_id);
            }
            if (! in_array($locked->status, ['accepted', 'sent', 'draft'], true)) {
                throw ValidationException::withMessages(['convert' => 'لا يمكن تحويل هذا العرض في وضعه الحالي.']);
            }
            if ($amountPaid < 0 || $amountPaid > (float) $locked->total) {
                throw ValidationException::withMessages(['amountPaid' => 'المبلغ المدفوع غير صالح.']);
            }
            if (! $locked->partner_id && $amountPaid < (float) $locked->total) {
                throw ValidationException::withMessages(['partner_id' => 'يجب اختيار عميل عند وجود مبلغ متبقٍ.']);
            }

            $products = Product::query()->whereIn('id', $locked->lines->pluck('product_id')->filter())
                ->lockForUpdate()->get()->keyBy('id');
            foreach ($locked->lines as $line) {
                $product = $products->get($line->product_id);
                if (! $product || $product->quantity < $line->quantity) {
                    throw ValidationException::withMessages(['stock' => "المخزون غير كافٍ للمنتج {$line->product_name}."]);
                }
                if ($product->purchase_price === null) {
                    throw ValidationException::withMessages(['stock' => "لا يمكن بيع «{$line->product_name}» قبل تسجيل تكلفة شراء موثوقة له."]);
                }
            }

            $pricing = app(InvoicePricingService::class)->calculate($locked->lines->map(fn ($line) => [
                'product_id' => $line->product_id, 'product_name' => $line->product_name,
                'quantity' => $line->quantity, 'unit_price' => $line->unit_price,
            ])->all(), $locked->discount_type ?: InvoicePricingService::FIXED, $locked->discount_value ?: $locked->discount);

            $invoice = Invoice::query()->create([
                'partner_id' => $locked->partner_id, 'type' => Invoice::TYPE_SALE,
                'subtotal' => $pricing['subtotal'], 'discount_type' => $pricing['discount_type'],
                'discount_value' => $pricing['discount_value'], 'discount_amount' => $pricing['discount_amount'],
                'total' => $pricing['total'], 'initial_paid' => $amountPaid, 'amount_paid' => $amountPaid,
            ]);
            foreach ($pricing['lines'] as $line) {
                $product = $products->get($line['product_id']);
                $product->decrement('quantity', $line['quantity']);
                InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id, 'product_id' => $product->id,
                    'product_name' => $line['product_name'], 'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'], 'purchase_price' => $product->purchase_price,
                    'sale_price' => $line['unit_price'], 'line_total' => $line['line_total'],
                    'discount_amount' => $line['discount_amount'], 'net_line_total' => $line['net_line_total'],
                ]);
            }
            $locked->update(['status' => 'converted', 'converted_invoice_id' => $invoice->id]);

            return $invoice->load('lines', 'partner');
        });

        app(AlertService::class)->evaluateProducts($invoice->lines->pluck('product_id')->filter());
        if ($invoice->partner_id) {
            app(AlertService::class)->evaluatePartners($invoice->partner_id);
        }

        return $invoice;
    }

    private function nextNumber(): string
    {
        $next = ((int) Quotation::query()->max('id')) + 1;

        return 'Q-'.now()->format('Ym').'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
