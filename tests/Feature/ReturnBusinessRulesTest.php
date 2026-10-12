<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Services\FinancialService;
use App\Services\InvoiceService;
use App\Services\ReturnService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake();
});

function returnSale(float $paid, bool $withPartner = true): array
{
    $category = Category::query()->create(['name' => 'تصنيف المرتجع']);
    $partner = $withPartner ? Partner::query()->create(['name' => 'زبون المرتجع', 'type' => Partner::TYPE_CUSTOMER]) : null;
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'قطعة اختبار', 'quantity' => 0, 'purchase_price' => 4, 'sale_price' => 10]);
    $invoice = Invoice::query()->create(['partner_id' => $partner?->id, 'type' => Invoice::TYPE_SALE, 'total' => 100, 'initial_paid' => $paid, 'amount_paid' => $paid]);
    $line = InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 10, 'unit_price' => 10, 'purchase_price' => 4, 'line_total' => 100]);

    return compact('partner', 'product', 'invoice', 'line');
}

function returnDebt(Partner $partner): float
{
    $row = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    return app(FinancialService::class)->receivable($row);
}

test('fully paid sale returns stock and records only the cash actually refunded', function () {
    ['partner' => $partner, 'product' => $product, 'invoice' => $invoice, 'line' => $line] = returnSale(100);
    $return = app(ReturnService::class)->processSalesReturn($invoice, 30, [$line->id => 3]);

    expect($product->fresh()->quantity)->toBe('3.000')
        ->and((float) $invoice->fresh()->total)->toBe(70.0)
        ->and((float) $return->total)->toBe(30.0)
        ->and((float) $return->amount_paid)->toBe(30.0)
        ->and((float) LedgerSettlement::query()->where('partner_id', $partner->id)->where('type', 'outgoing')->sum('amount'))->toBe(30.0)
        ->and(returnDebt($partner))->toBe(0.0);
});

test('returns reduce debt first and allow only the genuine excess as refund', function () {
    foreach ([[3, 0, 30], [6, 0, 0], [8, 20, 0]] as [$quantity, $cash, $expectedDebt]) {
        ['partner' => $partner, 'invoice' => $invoice, 'line' => $line] = returnSale(40);
        app(ReturnService::class)->processSalesReturn($invoice, $cash, [$line->id => $quantity]);
        expect(returnDebt($partner))->toBe((float) $expectedDebt)
            ->and((float) LedgerSettlement::query()->where('partner_id', $partner->id)->where('type', 'outgoing')->sum('amount'))->toBe((float) $cash);
    }
});

test('return equal to paid amount does not create a refund or new debt', function () {
    ['partner' => $partner, 'invoice' => $invoice, 'line' => $line] = returnSale(30);
    app(ReturnService::class)->processSalesReturn($invoice, 0, [$line->id => 3]);
    expect(returnDebt($partner))->toBe(40.0)
        ->and(LedgerSettlement::query()->where('type', 'outgoing')->count())->toBe(0);
});

test('cash refund is refused while the return is smaller than outstanding debt', function () {
    ['partner' => $partner, 'product' => $product, 'invoice' => $invoice, 'line' => $line] = returnSale(40);
    expect(fn () => app(ReturnService::class)->processSalesReturn($invoice, 1, [$line->id => 3]))->toThrow(Exception::class);
    expect($product->fresh()->quantity)->toBe('0.000')
        ->and((float) $invoice->fresh()->total)->toBe(100.0)
        ->and(Invoice::query()->where('type', 'return')->count())->toBe(0)
        ->and(returnDebt($partner))->toBe(60.0);
});

test('later partner payment is included before calculating refundable cash', function () {
    ['partner' => $partner, 'invoice' => $invoice, 'line' => $line] = returnSale(20);
    LedgerSettlement::query()->create(['partner_id' => $partner->id, 'amount' => 30, 'type' => 'incoming', 'settlement_date' => now()->toDateString()]);
    app(ReturnService::class)->processSalesReturn($invoice, 20, [$line->id => 7]);
    expect(returnDebt($partner))->toBe(0.0)
        ->and((float) LedgerSettlement::query()->where('type', 'outgoing')->sum('amount'))->toBe(20.0);
});

test('unpaid refund remains visible as customer credit until cash is handed back', function () {
    ['partner' => $partner, 'invoice' => $invoice, 'line' => $line] = returnSale(40);
    app(ReturnService::class)->processSalesReturn($invoice, 0, [$line->id => 8]);
    $row = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->receivable($row))->toBe('0.00')
        ->and(app(FinancialService::class)->payable($row))->toBe('20.00')
        ->and(app(FinancialService::class)->signedBalance($row))->toBe('-20.00')
        ->and(LedgerSettlement::query()->where('type', 'outgoing')->count())->toBe(0);

    LedgerSettlement::query()->create(['partner_id' => $partner->id, 'amount' => 20, 'type' => 'outgoing', 'settlement_date' => now()->toDateString()]);
    $row = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->payable($row))->toBe('0.00')
        ->and(app(FinancialService::class)->signedBalance($row))->toBe('0.00');
});

test('multiple returns cannot exceed sold quantity or repeat the same cash refund', function () {
    ['partner' => $partner, 'product' => $product, 'invoice' => $invoice, 'line' => $line] = returnSale(100);
    $service = app(ReturnService::class);
    $key = (string) Str::uuid();
    $first = $service->processSalesReturn($invoice, 30, [$line->id => 3], $key);
    $replayed = $service->processSalesReturn($invoice, 30, [$line->id => 3], $key);
    expect($replayed->id)->toBe($first->id)
        ->and($product->fresh()->quantity)->toBe('3.000')
        ->and(LedgerSettlement::query()->where('type', 'outgoing')->count())->toBe(1);

    $service->processSalesReturn($invoice, 40, [$line->id => 4], (string) Str::uuid());
    expect(fn () => $service->processSalesReturn($invoice, 40, [$line->id => 4], (string) Str::uuid()))->toThrow(Exception::class);
    expect($product->fresh()->quantity)->toBe('7.000')
        ->and($line->fresh()->quantity)->toBe('3.000')
        ->and((float) $invoice->fresh()->total)->toBe(30.0)
        ->and((float) LedgerSettlement::query()->where('type', 'outgoing')->sum('amount'))->toBe(70.0)
        ->and(returnDebt($partner))->toBe(0.0);
});

test('full return clears debt and refunds only what customer actually paid', function () {
    ['partner' => $partner, 'product' => $product, 'invoice' => $invoice, 'line' => $line] = returnSale(40);
    app(ReturnService::class)->processSalesReturn($invoice, 40, [$line->id => 10]);
    expect($product->fresh()->quantity)->toBe('10.000')
        ->and($line->fresh()->quantity)->toBe('0.000')
        ->and((float) $invoice->fresh()->total)->toBe(0.0)
        ->and(returnDebt($partner))->toBe(0.0)
        ->and((float) LedgerSettlement::query()->where('type', 'outgoing')->sum('amount'))->toBe(40.0);
});

test('anonymous paid sale can be returned without creating a fictitious partner ledger', function () {
    ['product' => $product, 'invoice' => $invoice, 'line' => $line] = returnSale(100, false);
    $return = app(ReturnService::class)->processSalesReturn($invoice, 20, [$line->id => 2]);
    expect($product->fresh()->quantity)->toBe('2.000')
        ->and($return->partner_id)->toBeNull()
        ->and((float) $return->amount_paid)->toBe(20.0)
        ->and(LedgerSettlement::query()->count())->toBe(0);
});

test('a sale with returns cannot be rewritten and invalid quantities roll back', function () {
    ['product' => $product, 'invoice' => $invoice, 'line' => $line] = returnSale(100);
    $service = app(ReturnService::class);
    expect(fn () => $service->processSalesReturn($invoice, 0, [$line->id => 11]))->toThrow(Exception::class);
    expect(fn () => $service->processSalesReturn($invoice, 0, [$line->id => '1.0001']))->toThrow(Exception::class);
    expect($product->fresh()->quantity)->toBe('0.000');
    $service->processSalesReturn($invoice, 10, [$line->id => 1]);
    expect(fn () => app(InvoiceService::class)->updateInvoice($invoice, [['product_id' => $product->id, 'quantity' => 9, 'unit_price' => 10]], 90))->toThrow(Exception::class);
    expect($line->fresh()->quantity)->toBe('9.000')
        ->and($product->fresh()->quantity)->toBe('1.000');
});
