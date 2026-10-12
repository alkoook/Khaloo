<?php

use App\Livewire\Invoices\Edit;
use App\Livewire\Invoices\Sales\Create;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceService;
use Livewire\Livewire;

function availabilityProduct(string $name, int $quantity): Product
{
    $category = Category::query()->firstOrCreate(['name' => 'اختبار توفر البيع']);

    return Product::query()->create([
        'category_id' => $category->id,
        'name' => $name,
        'quantity' => $quantity,
        'purchase_price' => 5,
        'sale_price' => 10,
    ]);
}

test('out of stock product stays visible but disabled and cannot be selected on sale creation', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $product = availabilityProduct('منتج نافد للإنشاء', 0);

    Livewire::actingAs($user)->test(Create::class)
        ->set('productSearch', 'منتج نافد')
        ->assertSee($product->name)
        ->assertSee('نفد المخزون')
        ->assertSeeHtml('disabled')
        ->call('selectProduct', $product->id)
        ->assertHasErrors(['lines'])
        ->assertSet('lines', []);
});

test('server rejects a forged sale line for an out of stock product', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $product = availabilityProduct('منتج نافد للتحايل', 0);

    Livewire::actingAs($user)->test(Create::class)
        ->set('lines', [[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'available' => 0,
            'quantity' => 1,
            'unit_price' => 10,
        ]])
        ->set('amount_paid', '10')
        ->call('save');

    expect(Invoice::query()->where('type', Invoice::TYPE_SALE)->count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe('0.000');
});

test('sale editing restores original quantity to availability without allowing an extra unit', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $product = availabilityProduct('منتج قديم أصبح صفرًا', 0);
    $invoice = Invoice::query()->create([
        'type' => Invoice::TYPE_SALE,
        'subtotal' => 20,
        'total' => 20,
        'initial_paid' => 20,
        'amount_paid' => 20,
    ]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 2,
        'unit_price' => 10,
        'purchase_price' => 5,
        'sale_price' => 10,
        'line_total' => 20,
        'net_line_total' => 20,
    ]);

    Livewire::actingAs($user)->test(Edit::class, ['invoice' => $invoice])
        ->assertSet('originalQuantities.'.$product->id, 2)
        ->call('selectProduct', $product->id)
        ->assertHasErrors(['lines'])
        ->assertSet('lines.0.quantity', 2);

    $updated = app(InvoiceService::class)->updateInvoice($invoice, [[
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 10,
        'sale_price' => 10,
    ]], 20);

    expect($updated->lines->first()->quantity)->toBe('2.000')
        ->and($product->fresh()->quantity)->toBe('0.000');
});

test('sale editing rejects a newly injected zero stock product', function () {
    $original = availabilityProduct('منتج الفاتورة الأصلي', 0);
    $unavailable = availabilityProduct('منتج نافد جديد', 0);
    $invoice = Invoice::query()->create([
        'type' => Invoice::TYPE_SALE,
        'subtotal' => 10,
        'total' => 10,
        'initial_paid' => 10,
        'amount_paid' => 10,
    ]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $original->id,
        'product_name' => $original->name,
        'quantity' => 1,
        'unit_price' => 10,
        'purchase_price' => 5,
        'sale_price' => 10,
        'line_total' => 10,
        'net_line_total' => 10,
    ]);

    expect(fn () => app(InvoiceService::class)->updateInvoice($invoice, [[
        'product_id' => $unavailable->id,
        'quantity' => 1,
        'unit_price' => 10,
        'sale_price' => 10,
    ]], 10))->toThrow(Exception::class);

    expect($invoice->fresh()->lines()->first()->product_id)->toBe($original->id)
        ->and($original->fresh()->quantity)->toBe('0.000')
        ->and($unavailable->fresh()->quantity)->toBe('0.000');
});
