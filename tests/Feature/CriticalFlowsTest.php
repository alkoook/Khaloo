<?php

use App\Livewire\Invoices\Purchases\Index as PurchaseIndex;
use App\Livewire\Invoices\Sales\Create;
use App\Livewire\Ledger\Index as LedgerIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\ReturnService;
use App\Services\SettingsService;
use Livewire\Livewire;

function saleFixture(float $paid = 20): array
{
    $category = Category::create(['name' => 'اختبار']);
    $partner = Partner::create(['name' => 'عميل اختبار', 'type' => Partner::TYPE_CUSTOMER]);
    $product = Product::create(['category_id' => $category->id, 'name' => 'منتج', 'quantity' => 0, 'purchase_price' => 4, 'sale_price' => 10]);
    $invoice = Invoice::create(['partner_id' => $partner->id, 'type' => Invoice::TYPE_SALE, 'total' => 100, 'initial_paid' => $paid, 'amount_paid' => $paid]);
    $line = InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => 'منتج', 'quantity' => 10, 'unit_price' => 10, 'purchase_price' => 4, 'line_total' => 100]);

    return compact('partner', 'product', 'invoice', 'line');
}

test('authenticated user can log out safely', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('later settlement and partial return produce one authoritative receivable', function () {
    ['partner' => $partner,'product' => $product,'invoice' => $invoice,'line' => $line] = saleFixture();
    LedgerSettlement::create(['partner_id' => $partner->id, 'amount' => 30, 'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()]);
    app(ReturnService::class)->processSalesReturn($invoice, 0, [$line->id => 2]);
    $balancePartner = app(FinancialService::class)->partnerBalancesQuery()->find($partner->id);
    expect(app(FinancialService::class)->receivable($balancePartner))->toBe('30.00')
        ->and($product->fresh()->quantity)->toBe('2.000')
        ->and((float) $invoice->fresh()->total)->toBe(100.0);
});

test('cash refund is recorded and does not throw a missing column exception', function () {
    ['partner' => $partner,'invoice' => $invoice,'line' => $line] = saleFixture(100);
    $return = app(ReturnService::class)->processSalesReturn($invoice, 20, [$line->id => 2]);
    expect($return->isReturn())->toBeTrue()
        ->and(LedgerSettlement::where('partner_id', $partner->id)->where('type', 'outgoing')->sum('amount'))->toEqual(20);
});

test('purchase deletion is blocked when stock was already consumed', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $category = Category::create(['name' => 'اختبار']);
    $supplier = Partner::create(['name' => 'مورد', 'type' => Partner::TYPE_SUPPLIER]);
    $product = Product::create(['category_id' => $category->id, 'name' => 'منتج', 'quantity' => 3]);
    $invoice = Invoice::create(['partner_id' => $supplier->id, 'type' => Invoice::TYPE_PURCHASE, 'total' => 50]);
    InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => 'منتج', 'quantity' => 5, 'unit_price' => 10, 'line_total' => 50]);
    Livewire::actingAs($user)->test(PurchaseIndex::class)->call('openCancellation', $invoice->id)->set('cancellationReason', 'إلغاء اختباري')->call('cancel')->assertHasErrors('cancellationReason');
    expect($invoice->fresh())->not->toBeNull()->and($product->fresh()->quantity)->toBe('3.000');
});

test('admin can open settings and pos receipt', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice] = saleFixture();
    $this->actingAs($user)->get(route('settings.index'))->assertOk();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('invoices.sales.show', $invoice))->assertOk();
    $this->actingAs($user)->get(route('invoices.receipt', $invoice))->assertOk()->assertSee('فاتورة مبيعات');
});

test('ledger search uses partner name without duplicate joins and paginates', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['partner' => $partner] = saleFixture();
    Livewire::actingAs($user)->test(LedgerIndex::class)->set('search', 'عميل اختبار')->assertSee('عميل اختبار');
});

test('theme setting persists through the livewire settings screen', function () {
    $user = User::factory()->create(['is_admin' => true]);
    Livewire::actingAs($user)->test(SettingsIndex::class)->set('theme', 'marine-night')->set('font_family', 'changa')->call('saveTheme')->assertDispatched('appearance-changed');
    expect(Setting::where('key', 'theme')->value('value'))->toBe('marine-night')
        ->and(Setting::where('key', 'font_family')->value('value'))->toBe('changa');
    Livewire::actingAs($user)->test(SettingsIndex::class)->set('theme', 'obsidian-ruby')->set('font_family', 'cairo')->call('saveTheme')->assertDispatched('appearance-changed');
    expect(Setting::where('key', 'theme')->value('value'))->toBe('obsidian-ruby');
});

test('pos receipt uses the persisted interface font with a safe fallback', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice] = saleFixture();
    Setting::query()->updateOrCreate(['key' => 'font_family'], ['value' => 'reem-kufi', 'type' => 'string', 'group' => 'appearance']);
    $this->actingAs($user)->get(route('invoices.receipt', $invoice))->assertOk()->assertSee('--receipt-font: "Reem Kufi", Tahoma, Arial, sans-serif', false);
});

test('receipt font size persists and scales the same preview and print stylesheet', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice] = saleFixture();

    Livewire::actingAs($user)->test(SettingsIndex::class)
        ->set('receipt_font_size', 15)
        ->call('savePrinting')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'receipt_font_size')->value('value'))->toBe('15');

    $this->actingAs($user)->get(route('invoices.receipt', $invoice))
        ->assertOk()
        ->assertSee('--receipt-font-scale: 1.25', false);

    $styles = file_get_contents(resource_path('css/receipt.css'));
    expect($styles)->toContain('var(--receipt-font-scale, 1)')
        ->and($styles)->toContain('white-space: nowrap !important;');
});

test('receipt width accepts only 72mm or 80mm and renders the selected print width', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice] = saleFixture();

    Livewire::actingAs($user)->test(SettingsIndex::class)
        ->set('receipt_width', '72')
        ->call('savePrinting')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'receipt_width')->value('value'))->toBe('72');
    $this->actingAs($user)->get(route('invoices.receipt', $invoice))
        ->assertOk()
        ->assertSee('--receipt-width: 72mm', false)
        ->assertSee('receipt--72', false);

    Livewire::actingAs($user)->test(SettingsIndex::class)
        ->set('receipt_width', '58')
        ->call('savePrinting')
        ->assertHasErrors(['receipt_width']);
});

test('thermal receipt renders dynamic company facts and the invoice number fallback', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice, 'partner' => $partner] = saleFixture();
    $partner->update(['phone' => '0991234567', 'address' => 'عربين']);

    $settings = app(SettingsService::class);
    $settings->set('company_name', 'شركة خلو التجريبية');
    $settings->set('company_phone', '0932222981');
    $settings->set('company_address', 'ريف دمشق - شارع الصاغة');

    $this->actingAs($user)->get(route('invoices.receipt', $invoice))
        ->assertOk()
        ->assertSee('receipt-header', false)
        ->assertSee('شركة خلو التجريبية')
        ->assertSee('0932222981')
        ->assertSee($partner->name)
        ->assertSee('ريف دمشق - شارع الصاغة')
        ->assertSee('0991234567')
        ->assertSee('#'.$invoice->id)
        ->assertSee($invoice->created_at->format('Y-m-d H:i'));
});

test('thermal roll renders long invoices as one continuously numbered table', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice] = saleFixture();

    foreach (range(2, 15) as $number) {
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'product_name' => 'صنف متسلسل '.$number,
            'quantity' => 1,
            'unit_price' => 1,
            'line_total' => 1,
            'net_line_total' => 1,
        ]);
    }

    $content = $this->actingAs($user)->get(route('invoices.receipt', $invoice))
        ->assertOk()
        ->getContent();

    expect(substr_count($content, '<table>'))->toBe(1)
        ->and(substr_count($content, '<thead>'))->toBe(1)
        ->and($content)->toContain('class="receipt-sequence">13</td>')
        ->and($content)->toContain('class="receipt-sequence">15</td>');

    $styles = file_get_contents(resource_path('css/receipt.css'));
    expect($styles)->toContain('display: table-row-group !important;')
        ->and($styles)->not->toContain('page-break-')
        ->and($styles)->not->toContain('break-before:')
        ->and($styles)->not->toContain('break-after:')
        ->and($styles)->not->toContain('break-inside:');
});

test('invoice product selection clears search and keeps the keyboard focus event for sales and purchases', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $category = Category::create(['name' => 'تصنيف البحث']);
    $product = Product::create(['category_id' => $category->id, 'name' => 'منتج لوحة المفاتيح', 'quantity' => 12, 'purchase_price' => 4, 'sale_price' => 8]);

    foreach ([Create::class, App\Livewire\Invoices\Purchases\Create::class] as $component) {
        Livewire::actingAs($user)->test($component)
            ->set('productSearch', $product->name)
            ->call('selectProduct', $product->id)
            ->assertSet('productSearch', '')
            ->assertDispatched('focus-product-search')
            ->call('selectProduct', $product->id)
            ->assertSet('lines.0.quantity', 2)
            ->assertDispatched('focus-product-search');
    }
});

test('login bootstrap respects the persisted appearance instead of resetting it', function () {
    Setting::query()->updateOrCreate(['key' => 'theme'], ['value' => 'obsidian-ruby', 'type' => 'string', 'group' => 'appearance']);
    Setting::query()->updateOrCreate(['key' => 'font_family'], ['value' => 'baloo', 'type' => 'string', 'group' => 'appearance']);
    $this->get(route('login'))->assertOk()->assertSee('data-server-theme="obsidian-ruby"', false)->assertSee('data-server-font="baloo"', false);
});

test('invoice details present partner phone in the structured contact card', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice,'partner' => $partner] = saleFixture();
    $partner->update(['phone' => '0991234567']);
    $this->actingAs($user)->get(route('invoices.sales.show', $invoice))->assertOk()->assertSee('party-contact', false)->assertSee('0991234567');
});

test('invoice masthead renders its dynamic company facts', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice] = saleFixture();

    $settings = app(SettingsService::class);
    $settings->set('company_name', 'شركة خلو التجريبية');
    $settings->set('company_phone', '0991234567');
    $settings->set('company_address', 'ريف دمشق - عربين');

    $this->actingAs($user)->get(route('invoices.sales.show', $invoice))
        ->assertOk()
        ->assertSee('invoice-document__masthead', false)
        ->assertSee('شركة خلو التجريبية')
        ->assertSee('0991234567')
        ->assertSee('ريف دمشق - عربين')
        ->assertSee('#'.$invoice->id)
        ->assertSee($invoice->created_at->format('Y-m-d H:i'));
});
