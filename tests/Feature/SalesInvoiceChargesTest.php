<?php

use App\Livewire\Invoices\Sales\Create;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceAdditionalLine;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\FinancialStatusService;
use App\Services\InvoiceCancellationService;
use App\Services\InvoiceService;
use App\Services\ReturnService;
use App\Support\Decimal;
use Livewire\Livewire;

function chargeFixture(): array
{
    $user = User::factory()->create(['is_admin' => true]);
    $partner = Partner::query()->create(['name' => 'عميل رسوم', 'type' => Partner::TYPE_CUSTOMER]);
    $category = Category::query()->create(['name' => 'تصنيف رسوم']);
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'بضاعة خلو', 'quantity' => 50, 'purchase_price' => 40, 'sale_price' => 100]);

    return compact('user', 'partner', 'product');
}

function createChargedSale(User $user, Partner $partner, Product $product, float $delivery = 0, array $additional = [], float $paid = 0): Invoice
{
    $component = Livewire::actingAs($user)->test(Create::class)
        ->set('partner_id', $partner->id)
        ->set('lines', [[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'available' => $product->quantity,
            'quantity' => 1,
            'unit_price' => 100,
        ]])
        ->set('hasDelivery', $delivery > 0)
        ->set('deliveryAmount', $delivery > 0 ? (string) $delivery : '')
        ->set('additionalLines', $additional)
        ->set('amount_paid', (string) $paid)
        ->call('save')
        ->assertHasNoErrors();

    expect($component->errors()->isEmpty())->toBeTrue();

    return Invoice::query()->latest('id')->firstOrFail();
}

test('sales preserve merchandise total while separating delivery and reimbursements', function () {
    ['user' => $user, 'partner' => $partner, 'product' => $product] = chargeFixture();

    $plain = createChargedSale($user, $partner, $product, paid: 100);
    expect((float) $plain->total)->toBe(100.0)
        ->and((float) $plain->delivery_amount)->toBe(0.0)
        ->and((float) $plain->additional_total)->toBe(0.0)
        ->and($plain->collectibleTotal())->toBe('100.00');

    $delivery = createChargedSale($user, $partner, $product, delivery: 5, paid: 105);
    expect((float) $delivery->total)->toBe(100.0)->and($delivery->collectibleTotal())->toBe('105.00');

    $passThrough = createChargedSale($user, $partner, $product, additional: [[
        'description' => 'غرض خارجي', 'quantity' => 1, 'unit_amount' => 10, 'source_note' => 'من محل أبو أحمد',
    ]], paid: 110);
    expect((float) $passThrough->total)->toBe(100.0)
        ->and((float) $passThrough->additional_total)->toBe(10.0)
        ->and($passThrough->collectibleTotal())->toBe('110.00')
        ->and($passThrough->additionalLines()->count())->toBe(1);

    $combined = createChargedSale($user, $partner, $product, delivery: 5, additional: [
        ['description' => 'غرض أول', 'quantity' => 1, 'unit_amount' => 4, 'source_note' => 'طلب خاص'],
        ['description' => 'غرض ثان', 'quantity' => 2, 'unit_amount' => 3, 'source_note' => null],
    ], paid: 50);
    expect((float) $combined->total)->toBe(100.0)
        ->and((float) $combined->delivery_amount)->toBe(5.0)
        ->and((float) $combined->additional_total)->toBe(10.0)
        ->and($combined->collectibleTotal())->toBe('115.00')
        ->and($product->fresh()->quantity)->toBe('46.000')
        ->and((float) Invoice::query()->active()->where('type', Invoice::TYPE_SALE)->sum('total'))->toBe(400.0);

    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->receivable($balance))->toBe('65.00');

    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['net_sales'])->toBe('400.00')
        ->and($summary['delivery_income'])->toBe('10.00')
        ->and($summary['pass_through_advances'])->toBe('20.00')
        ->and($summary['gross_profit'])->toBe('250.00')
        ->and($summary['sales_collected'])->toBe('365.00')
        ->and($summary['cash_remaining'])->toBe('345.00');
});

test('pass through advance and reimbursement have zero net cash and profit effect', function () {
    ['user' => $user, 'partner' => $partner, 'product' => $product] = chargeFixture();

    $invoice = createChargedSale($user, $partner, $product, additional: [[
        'description' => 'غرض عابر', 'quantity' => 1, 'unit_amount' => 10, 'source_note' => null,
    ]], paid: 110);

    $summary = app(FinancialStatusService::class)->summary();
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);

    expect((float) $invoice->total)->toBe(100.0)
        ->and($invoice->collectibleTotal())->toBe('110.00')
        ->and($summary['sales_collected'])->toBe('110.00')
        ->and($summary['pass_through_advances'])->toBe('10.00')
        ->and($summary['cash_remaining'])->toBe('100.00')
        ->and($summary['net_sales'])->toBe('100.00')
        ->and($summary['gross_profit'])->toBe('60.00')
        ->and(app(FinancialService::class)->receivable($balance))->toBe('0.00')
        ->and($product->fresh()->quantity)->toBe('49.000');
});

test('partial payment keeps the full pass through advance and unpaid customer balance', function () {
    ['user' => $user, 'partner' => $partner, 'product' => $product] = chargeFixture();

    createChargedSale($user, $partner, $product, additional: [[
        'description' => 'غرض عابر', 'quantity' => 1, 'unit_amount' => 10, 'source_note' => null,
    ]], paid: 50);

    $summary = app(FinancialStatusService::class)->summary();
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);

    expect($summary['sales_collected'])->toBe('50.00')
        ->and($summary['pass_through_advances'])->toBe('10.00')
        ->and($summary['cash_remaining'])->toBe('40.00')
        ->and(app(FinancialService::class)->receivable($balance))->toBe('60.00');
});

test('editing persists delivery and additional rows without extra stock movement', function () {
    ['user' => $user, 'partner' => $partner, 'product' => $product] = chargeFixture();
    $invoice = createChargedSale($user, $partner, $product, paid: 100);
    $stockAfterSale = $product->fresh()->quantity;

    app(InvoiceService::class)->updateInvoice($invoice, [[
        'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100,
    ]], 60, 'fixed', 0, [
        ['description' => 'طلب خارجي', 'quantity' => 2, 'unit_amount' => 5, 'source_note' => 'غير متوفر بالمحل'],
    ], 5);

    $invoice->refresh();
    expect($invoice->collectibleTotal())->toBe('115.00')
        ->and((float) $invoice->total)->toBe(100.0)
        ->and($invoice->additionalLines()->sole()->source_note)->toBe('غير متوفر بالمحل')
        ->and($product->fresh()->quantity)->toBe($stockAfterSale);
});

test('cancellation and returns ignore pass through rows for stock', function () {
    ['user' => $user, 'partner' => $partner, 'product' => $product] = chargeFixture();
    $invoice = createChargedSale($user, $partner, $product, delivery: 5, additional: [[
        'description' => 'غرض خارجي', 'quantity' => 1, 'unit_amount' => 10, 'source_note' => null,
    ]], paid: 0);
    $stockAfterSale = $product->fresh()->quantity;
    $line = $invoice->lines()->sole();

    app(ReturnService::class)->processSalesReturn($invoice, 0, [$line->id => 1]);
    expect($invoice->fresh()->collectibleTotal())->toBe('115.00')
        ->and((float) $invoice->fresh()->additional_total)->toBe(10.0)
        ->and(InvoiceAdditionalLine::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and($product->fresh()->quantity)->toBe(Decimal::add($stockAfterSale, 1, 3));

    $second = createChargedSale($user, $partner, $product, delivery: 5, additional: [[
        'description' => 'غرض آخر', 'quantity' => 1, 'unit_amount' => 10, 'source_note' => null,
    ]], paid: 0);
    $beforeCancel = $product->fresh()->quantity;
    app(InvoiceCancellationService::class)->cancel($second, $user->id, 'إلغاء اختبار الرسوم');
    expect($product->fresh()->quantity)->toBe(Decimal::add($beforeCancel, 1, 3));
});

test('sales display and thermal receipt separate all customer charges', function () {
    ['user' => $user, 'partner' => $partner, 'product' => $product] = chargeFixture();
    $invoice = createChargedSale($user, $partner, $product, delivery: 5, additional: [[
        'description' => 'غرض خارجي', 'quantity' => 1, 'unit_amount' => 10, 'source_note' => 'من محل أبو أحمد',
    ]], paid: 50);

    foreach (['invoices.sales.show', 'invoices.receipt'] as $route) {
        $this->actingAs($user)->get(route($route, $invoice))->assertOk()
            ->assertSee('خانات إضافية')->assertSee('غرض خارجي')->assertSee('من محل أبو أحمد')
            ->assertSee('التوصيل')->assertSee('الإجمالي المطلوب')->assertSee('115.00');
    }
});
