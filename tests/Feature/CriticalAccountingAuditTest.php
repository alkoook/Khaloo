<?php

use App\Livewire\Invoices\Purchases\Create as CreatePurchase;
use App\Livewire\Invoices\Sales\Create as CreateSale;
use App\Livewire\Products\Create as CreateProduct;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\FinancialStatusService;
use App\Services\InvoicePricingService;
use App\Services\InvoiceService;
use App\Services\ReturnService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function auditMasterData(): array
{
    $user = User::factory()->create(['is_admin' => true]);
    $category = Category::query()->create(['name' => 'تصنيف التدقيق']);
    $customer = Partner::query()->create(['name' => 'عميل التدقيق', 'type' => Partner::TYPE_CUSTOMER]);
    $supplier = Partner::query()->create(['name' => 'مورد التدقيق', 'type' => Partner::TYPE_SUPPLIER]);

    return compact('user', 'category', 'customer', 'supplier');
}

test('product master creation has no purchase stock ledger or financial side effects', function () {
    ['user' => $user, 'category' => $category, 'supplier' => $supplier] = auditMasterData();

    Livewire::actingAs($user)->test(CreateProduct::class)
        ->set('name', 'منتج تعريفي فقط')->set('category_id', $category->id)->set('partner_id', $supplier->id)
        ->call('save')->assertHasNoErrors();

    $product = Product::query()->sole();
    expect($product->quantity)->toBe('0.000')
        ->and($product->purchase_price)->toBeNull()
        ->and($product->sale_price)->toBeNull()
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceLine::query()->count())->toBe(0)
        ->and(LedgerSettlement::query()->count())->toBe(0);
});

test('fixed and percentage pricing are validated and allocated to exact cents', function () {
    $service = app(InvoicePricingService::class);
    $fixed = $service->calculate([
        ['quantity' => 1, 'unit_price' => 33.33], ['quantity' => 1, 'unit_price' => 66.67],
    ], 'fixed', 10);
    $percentage = $service->calculate([['quantity' => 2, 'unit_price' => 25]], 'percentage', 10);

    expect($fixed['subtotal'])->toBe('100.00')->and($fixed['discount_amount'])->toBe('10.00')->and($fixed['total'])->toBe('90.00')
        ->and(collect($fixed['lines'])->sum('discount_amount'))->toBe(10.0)
        ->and(collect($fixed['lines'])->sum('net_line_total'))->toBe(90.0)
        ->and($percentage['discount_amount'])->toBe('5.00')->and($percentage['total'])->toBe('45.00');
});

test('sales discount reduces net sales and receivable but not cogs', function () {
    ['user' => $user, 'category' => $category, 'customer' => $customer] = auditMasterData();
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'سلعة مبيع', 'quantity' => 20, 'purchase_price' => 5, 'sale_price' => 10]);

    Livewire::actingAs($user)->test(CreateSale::class)->call('selectProduct', $product->id)
        ->set('lines.0.quantity', 10)->set('discount_type', 'fixed')->set('discount_value', '10')
        ->set('partner_id', $customer->id)->set('amount_paid', '30')->call('save')->assertHasNoErrors();

    $invoice = Invoice::query()->sole();
    $balancePartner = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($customer->id);
    $summary = app(FinancialStatusService::class)->summary();
    expect((float) $invoice->subtotal)->toBe(100.0)->and((float) $invoice->discount_amount)->toBe(10.0)->and((float) $invoice->total)->toBe(90.0)
        ->and(app(FinancialService::class)->receivable($balancePartner))->toBe('60.00')
        ->and($summary['net_sales'])->toBe('90.00')->and($summary['cogs'])->toBe('50.00')->and($summary['net_profit'])->toBe('40.00');
});

test('purchase discount becomes net inventory cost and supplier debt', function () {
    ['user' => $user, 'category' => $category, 'supplier' => $supplier] = auditMasterData();
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'سلعة شراء', 'quantity' => 10, 'purchase_price' => 10, 'sale_price' => 15]);

    Livewire::actingAs($user)->test(CreatePurchase::class)->call('selectProduct', $product->id)
        ->set('lines.0.quantity', 10)->set('lines.0.unit_price', 10)->set('discount_type', 'fixed')->set('discount_value', '20')
        ->set('partner_id', $supplier->id)->set('amount_paid', '30')->call('save')->assertHasNoErrors();

    $invoice = Invoice::query()->sole();
    $line = $invoice->lines()->sole();
    $balancePartner = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($supplier->id);
    expect((float) $invoice->total)->toBe(80.0)->and((float) $line->net_line_total)->toBe(80.0)
        ->and((float) $line->purchase_price)->toBe(8.0)->and((float) $product->fresh()->purchase_price)->toBe(9.0)
        ->and(app(FinancialService::class)->payable($balancePartner))->toBe('50.00');
});

test('discounted partial and multiple returns use net line value', function () {
    ['category' => $category, 'customer' => $customer] = auditMasterData();
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'سلعة مرتجع', 'quantity' => 0, 'purchase_price' => 4, 'sale_price' => 10]);
    $invoice = Invoice::query()->create(['partner_id' => $customer->id, 'type' => Invoice::TYPE_SALE, 'subtotal' => 100, 'discount_type' => 'fixed', 'discount_value' => 10, 'discount_amount' => 10, 'total' => 90, 'initial_paid' => 90, 'amount_paid' => 90]);
    $line = InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 10, 'unit_price' => 10, 'purchase_price' => 4, 'line_total' => 100, 'discount_amount' => 10, 'net_line_total' => 90]);

    $first = app(ReturnService::class)->processSalesReturn($invoice, 18, [$line->id => 2]);
    $second = app(ReturnService::class)->processSalesReturn($invoice->fresh(), 27, [$line->id => 3]);

    expect((float) $first->total)->toBe(18.0)->and((float) $second->total)->toBe(27.0)
        ->and((float) $invoice->fresh()->total)->toBe(90.0)->and($line->fresh()->quantity)->toBe('10.000')
        ->and((float) $line->fresh()->net_line_total)->toBe(90.0)->and($product->fresh()->quantity)->toBe('5.000');
});

test('a later settlement does not recreate revenue and no longer blocks a consistent invoice correction', function () {
    ['category' => $category, 'customer' => $customer] = auditMasterData();
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'سلعة تسوية', 'quantity' => 10, 'purchase_price' => 4, 'sale_price' => 10]);
    $invoice = Invoice::query()->create(['partner_id' => $customer->id, 'type' => Invoice::TYPE_SALE, 'subtotal' => 100, 'total' => 100, 'initial_paid' => 40, 'amount_paid' => 40]);
    InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 10, 'unit_price' => 10, 'purchase_price' => 4, 'line_total' => 100, 'net_line_total' => 100]);
    LedgerSettlement::query()->create(['partner_id' => $customer->id, 'amount' => 20, 'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString()]);

    app(InvoiceService::class)->updateInvoice($invoice, [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10]], 40, 'fixed', 5);
    expect((float) Invoice::query()->sum('total'))->toBe(95.0)
        ->and((float) LedgerSettlement::query()->sum('amount'))->toBe(20.0)
        ->and((float) $product->fresh()->quantity)->toBe(10.0);
});

test('unknown acquisition cost blocks sale and marks financial profit untrusted', function () {
    ['user' => $user, 'category' => $category] = auditMasterData();
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'تكلفة مجهولة', 'quantity' => 5, 'purchase_price' => null, 'sale_price' => 10]);

    Livewire::actingAs($user)->test(CreateSale::class)->call('selectProduct', $product->id)->assertHasErrors(['lines']);

    $invoice = Invoice::query()->create(['type' => Invoice::TYPE_SALE, 'subtotal' => 10, 'total' => 10, 'initial_paid' => 10, 'amount_paid' => 10]);
    InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 10, 'purchase_price' => null, 'line_total' => 10, 'net_line_total' => 10]);
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['cogs'])->toBeNull()->and($summary['net_profit'])->toBeNull()->and($summary['inventory_value'])->toBeNull();
});

test('database telegram settings override config and connection test calls official endpoints', function () {
    config(['telegram.bot_token' => 'env-token', 'telegram.chat_id' => 'env-chat']);
    app(SettingsService::class)->set('telegram_bot_token', encrypt('db-token'), 'telegram');
    app(SettingsService::class)->set('telegram_chat_id', 'db-chat', 'telegram');
    Http::fake([
        'https://api.telegram.org/botdb-token/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'khaloo_bot']]),
        'https://api.telegram.org/botdb-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    $result = app(TelegramService::class)->testConnection();
    expect($result['ok'])->toBeTrue()->and($result['message'])->not->toContain('db-token');
    Http::assertSentCount(2);
    expect(collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]->url(), 'env-token')))->toBeFalse();
});

test('invalid telegram token is handled in arabic without exposing the secret', function () {
    app(SettingsService::class)->set('telegram_bot_token', encrypt('very-secret-token'), 'telegram');
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 401)]);
    $result = app(TelegramService::class)->testConnection();
    expect($result['ok'])->toBeFalse()->and($result['message'])->toContain('غير صالح')->and($result['message'])->not->toContain('very-secret-token');
});
