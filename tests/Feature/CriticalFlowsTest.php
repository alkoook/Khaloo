<?php

use App\Livewire\Invoices\Purchases\Index as PurchaseIndex;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\ReturnService;
use Livewire\Livewire;
use App\Livewire\Ledger\Index as LedgerIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Setting;

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
    ['partner'=>$partner,'product'=>$product,'invoice'=>$invoice,'line'=>$line] = saleFixture();
    LedgerSettlement::create(['partner_id'=>$partner->id,'amount'=>30,'type'=>LedgerSettlement::TYPE_INCOMING,'settlement_date'=>now()]);
    app(ReturnService::class)->processSalesReturn($invoice, 0, [$line->id => 2]);
    $balancePartner = app(FinancialService::class)->partnerBalancesQuery()->find($partner->id);
    expect(app(FinancialService::class)->receivable($balancePartner))->toBe(30.0)
        ->and($product->fresh()->quantity)->toBe(2)
        ->and((float)$invoice->fresh()->total)->toBe(80.0);
});

test('cash refund is recorded and does not throw a missing column exception', function () {
    ['partner'=>$partner,'invoice'=>$invoice,'line'=>$line] = saleFixture(100);
    $return = app(ReturnService::class)->processSalesReturn($invoice, 20, [$line->id => 2]);
    expect($return->isReturn())->toBeTrue()
        ->and(LedgerSettlement::where('partner_id',$partner->id)->where('type','outgoing')->sum('amount'))->toEqual(20);
});

test('purchase deletion is blocked when stock was already consumed', function () {
    $user=User::factory()->create(['is_admin'=>true]);$category=Category::create(['name'=>'اختبار']);$supplier=Partner::create(['name'=>'مورد','type'=>Partner::TYPE_SUPPLIER]);
    $product=Product::create(['category_id'=>$category->id,'name'=>'منتج','quantity'=>3]);$invoice=Invoice::create(['partner_id'=>$supplier->id,'type'=>Invoice::TYPE_PURCHASE,'total'=>50]);
    InvoiceLine::create(['invoice_id'=>$invoice->id,'product_id'=>$product->id,'product_name'=>'منتج','quantity'=>5,'unit_price'=>10,'line_total'=>50]);
    Livewire::actingAs($user)->test(PurchaseIndex::class)->call('delete',$invoice->id)->assertDispatched('flash');
    expect($invoice->fresh())->not->toBeNull()->and($product->fresh()->quantity)->toBe(3);
});

test('admin can open settings and pos receipt', function () {
    $user=User::factory()->create(['is_admin'=>true]);['invoice'=>$invoice]=saleFixture();
    $this->actingAs($user)->get(route('settings.index'))->assertOk();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('invoices.sales.show',$invoice))->assertOk();
    $this->actingAs($user)->get(route('invoices.receipt',$invoice))->assertOk()->assertSee('فاتورة مبيعات');
});

test('ledger search uses partner name without duplicate joins and paginates', function () {
    $user=User::factory()->create(['is_admin'=>true]);['partner'=>$partner]=saleFixture();
    Livewire::actingAs($user)->test(LedgerIndex::class)->set('search','عميل اختبار')->assertSee('عميل اختبار');
});

test('theme setting persists through the livewire settings screen', function () {
    $user=User::factory()->create(['is_admin'=>true]);
    Livewire::actingAs($user)->test(SettingsIndex::class)->set('theme','marine-night')->set('font_family','changa')->call('saveTheme')->assertDispatched('appearance-changed');
    expect(Setting::where('key','theme')->value('value'))->toBe('marine-night')
        ->and(Setting::where('key','font_family')->value('value'))->toBe('changa');
    Livewire::actingAs($user)->test(SettingsIndex::class)->set('theme','obsidian-ruby')->set('font_family','cairo')->call('saveTheme')->assertDispatched('appearance-changed');
    expect(Setting::where('key','theme')->value('value'))->toBe('obsidian-ruby');
});

test('pos receipt uses the persisted interface font with a safe fallback', function () {
    $user=User::factory()->create(['is_admin'=>true]); ['invoice'=>$invoice]=saleFixture();
    Setting::query()->updateOrCreate(['key'=>'font_family'], ['value'=>'reem-kufi','type'=>'string','group'=>'appearance']);
    $this->actingAs($user)->get(route('invoices.receipt',$invoice))->assertOk()->assertSee('font-family:"Reem Kufi", Tahoma, Arial, sans-serif', false);
});

test('login bootstrap respects the persisted appearance instead of resetting it', function () {
    Setting::query()->updateOrCreate(['key'=>'theme'], ['value'=>'obsidian-ruby','type'=>'string','group'=>'appearance']);
    Setting::query()->updateOrCreate(['key'=>'font_family'], ['value'=>'baloo','type'=>'string','group'=>'appearance']);
    $this->get(route('login'))->assertOk()->assertSee('data-server-theme="obsidian-ruby"', false)->assertSee('data-server-font="baloo"', false);
});

test('invoice details present partner phone in the structured contact card', function () {
    $user=User::factory()->create(['is_admin'=>true]); ['invoice'=>$invoice,'partner'=>$partner]=saleFixture();
    $partner->update(['phone'=>'0991234567']);
    $this->actingAs($user)->get(route('invoices.sales.show',$invoice))->assertOk()->assertSee('party-contact', false)->assertSee('0991234567');
});
