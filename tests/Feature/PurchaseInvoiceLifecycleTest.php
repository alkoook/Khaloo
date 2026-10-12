<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\InvoiceCancellationService;
use App\Services\InvoiceService;
use App\Services\ReportService;
use Illuminate\Validation\ValidationException;

function purchaseLifecycleFixture(): array
{
    $user = User::factory()->create(['is_admin' => true]);
    $category = Category::query()->create(['name' => 'دورة شراء']);
    $supplier = Partner::query()->create(['name' => 'مورد دورة الشراء', 'type' => Partner::TYPE_SUPPLIER]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'صنف دورة الشراء',
        'quantity' => 10,
        'purchase_price' => 5,
        'sale_price' => 10,
    ]);
    $invoice = Invoice::query()->create([
        'partner_id' => $supplier->id,
        'type' => Invoice::TYPE_PURCHASE,
        'subtotal' => 10,
        'total' => 10,
        'initial_paid' => 0,
        'amount_paid' => 0,
    ]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 2,
        'unit_price' => 5,
        'purchase_price' => 5,
        'line_total' => 10,
        'net_line_total' => 10,
    ]);

    return compact('user', 'supplier', 'product', 'invoice');
}

test('safe purchase exposes edit and cancellation on list and details', function () {
    ['user' => $user, 'invoice' => $invoice] = purchaseLifecycleFixture();

    $this->actingAs($user)->get(route('invoices.purchases.index'))->assertOk()
        ->assertSee('تعديل')->assertSee('إلغاء الفاتورة');
    $this->actingAs($user)->get(route('invoices.purchases.show', $invoice))->assertOk()
        ->assertSee(route('invoices.edit', $invoice), false)
        ->assertSee(route('invoices.purchases.cancel', $invoice), false);
    $this->actingAs($user)->get(route('invoices.edit', $invoice))->assertOk();
});

test('purchase edit and cancellation preserve stock supplier balance and reports', function () {
    ['user' => $user, 'supplier' => $supplier, 'product' => $product, 'invoice' => $invoice] = purchaseLifecycleFixture();

    app(InvoiceService::class)->updateInvoice($invoice, [[
        'product_id' => $product->id, 'quantity' => 3, 'unit_price' => 5, 'sale_price' => 10,
    ]], 0);
    expect($product->fresh()->quantity)->toBe('11.000')
        ->and((float) $invoice->fresh()->total)->toBe(15.0);

    $this->actingAs($user)->post(route('invoices.purchases.cancel', $invoice), [
        'cancellation_reason' => 'إلغاء توريد اختباري آمن',
    ])->assertRedirect(route('invoices.purchases.show', $invoice));

    $invoice->refresh();
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($supplier->id);
    expect($invoice->isCancelled())->toBeTrue()
        ->and($invoice->cancelled_at)->not->toBeNull()
        ->and($invoice->cancelled_by)->toBe($user->id)
        ->and($product->fresh()->quantity)->toBe('8.000')
        ->and(app(FinancialService::class)->payable($balance))->toBe('0.00')
        ->and(app(ReportService::class)->preview('purchases', [])['total'])->toBe('0.00');

    expect(fn () => app(InvoiceCancellationService::class)->cancel($invoice->fresh(), $user->id, 'محاولة إلغاء مكررة'))
        ->toThrow(ValidationException::class);
    expect($product->fresh()->quantity)->toBe('8.000');
    $this->actingAs($user)->get(route('invoices.edit', $invoice))->assertStatus(409)->assertSee('لا يمكن تعديل فاتورة ملغاة');
});

test('later or consumed purchase stock keeps actions visible but safely disabled', function () {
    ['user' => $user, 'product' => $product, 'invoice' => $invoice] = purchaseLifecycleFixture();
    $later = Invoice::query()->create(['type' => Invoice::TYPE_SALE, 'subtotal' => 5, 'total' => 5, 'initial_paid' => 5, 'amount_paid' => 5]);
    InvoiceLine::query()->create([
        'invoice_id' => $later->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'quantity' => 1, 'unit_price' => 5, 'purchase_price' => 5, 'line_total' => 5, 'net_line_total' => 5,
    ]);
    $product->decrement('quantity');

    expect(app(InvoiceService::class)->editBlockReason($invoice))->toContain('حركات مخزون لاحقة')
        ->and(app(InvoiceCancellationService::class)->blockReason($invoice))->toContain('حركات مخزون لاحقة');

    foreach (['invoices.purchases.index', 'invoices.purchases.show'] as $route) {
        $this->actingAs($user)->get(route($route, $route === 'invoices.purchases.show' ? $invoice : []))->assertOk()
            ->assertSee('تعديل')->assertSee('إلغاء الفاتورة')->assertSee('حركات مخزون لاحقة');
    }
    $this->actingAs($user)->get(route('invoices.edit', $invoice))->assertStatus(409)->assertSee('حركات مخزون لاحقة');
    expect(fn () => app(InvoiceCancellationService::class)->cancel($invoice, $user->id, 'محاولة غير آمنة'))
        ->toThrow(ValidationException::class);
    expect($product->fresh()->quantity)->toBe('9.000');
});

test('consumed purchase quantity blocks edit and cancellation without changing stock', function () {
    ['user' => $user, 'product' => $product, 'invoice' => $invoice] = purchaseLifecycleFixture();
    $product->update(['quantity' => 1]);

    expect(app(InvoiceService::class)->editBlockReason($invoice))->toContain('تم بيعه أو استهلاكه')
        ->and(app(InvoiceCancellationService::class)->blockReason($invoice))->toContain('تم بيعه أو استهلاكه');
    $this->actingAs($user)->get(route('invoices.purchases.show', $invoice))->assertOk()
        ->assertSee('تعديل')->assertSee('إلغاء الفاتورة')->assertSee('تم بيعه أو استهلاكه');
    expect(fn () => app(InvoiceCancellationService::class)->cancel($invoice, $user->id, 'إلغاء كمية مستهلكة'))
        ->toThrow(ValidationException::class);
    expect($product->fresh()->quantity)->toBe('1.000');
});
