<?php

use App\Livewire\Invoices\Purchases\Create as PurchaseCreate;
use App\Models\Category;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\QuotationService;
use App\Services\TelegramService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('purchase invoice modal creates only a product master and selects it', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $category = Category::query()->create(['name' => 'أدوات']);
    $supplier = Partner::query()->create(['name' => 'مورد السياق', 'type' => Partner::TYPE_SUPPLIER]);
    $this->mock(TelegramService::class)->shouldNotReceive('notifyCreated');

    $component = Livewire::actingAs($user)->test(PurchaseCreate::class)
        ->set('partner_id', $supplier->id)
        ->set('productSearch', 'منتج جديد')
        ->call('openProductModal')
        ->set('newProductName', 'منتج من الفاتورة')
        ->set('newProductCategoryId', $category->id)
        ->call('createProduct')
        ->assertSet('showProductModal', false);

    $product = Product::query()->where('name', 'منتج من الفاتورة')->sole();
    expect($product->category_id)->toBe($category->id)
        ->and($product->partner_id)->toBe($supplier->id)
        ->and($product->quantity)->toBe('0.000')
        ->and($product->purchase_price)->toBeNull()
        ->and(Invoice::query()->count())->toBe(0)
        ->and(InvoiceLine::query()->count())->toBe(0)
        ->and(InAppNotification::query()->count())->toBe(0)
        ->and($component->get('lines.0.product_id'))->toBe($product->id);
});

test('quotation fixed and percentage discounts have no financial effect and conversion preserves pricing', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $category = Category::query()->create(['name' => 'عروض']);
    $customer = Partner::query()->create(['name' => 'عميل عرض', 'type' => Partner::TYPE_CUSTOMER]);
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'منتج عرض', 'quantity' => 10, 'purchase_price' => 20, 'sale_price' => 50]);
    $service = app(QuotationService::class);
    $base = ['partner_id' => $customer->id, 'quotation_date' => now()->toDateString(), 'expires_at' => null];
    $lines = [['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => 50]];

    $fixed = $service->save($base + ['discount_type' => 'fixed', 'discount_value' => 10], $lines);
    $percentage = $service->save($base + ['discount_type' => 'percentage', 'discount_value' => 10], $lines);
    expect((float) $fixed->subtotal)->toBe(50.0)->and((float) $fixed->discount_amount)->toBe(10.0)->and((float) $fixed->total)->toBe(40.0)
        ->and((float) $percentage->discount_amount)->toBe(5.0)->and((float) $percentage->total)->toBe(45.0)
        ->and(Invoice::query()->count())->toBe(0)->and(LedgerSettlement::query()->count())->toBe(0)
        ->and($product->fresh()->quantity)->toBe('10.000');

    $this->actingAs($user)->get(route('quotations.print', $percentage))->assertOk()->assertSee('الحسم (10.00%)')->assertSee('45.00');
    $invoice = $service->convertToSale($percentage, 20);
    expect($invoice->discount_type)->toBe('percentage')->and((float) $invoice->discount_value)->toBe(10.0)
        ->and((float) $invoice->discount_amount)->toBe(5.0)->and((float) $invoice->total)->toBe(45.0)
        ->and((float) $invoice->amount_paid)->toBe(20.0)->and($product->fresh()->quantity)->toBe('9.000');
});

test('customer and supplier settlements reconcile through one service and reject overpayment', function () {
    $category = Category::query()->create(['name' => 'ذمم']);
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'صنف', 'quantity' => 1, 'purchase_price' => 10]);
    $customer = Partner::query()->create(['name' => 'عميل', 'type' => Partner::TYPE_CUSTOMER]);
    $supplier = Partner::query()->create(['name' => 'مورد', 'type' => Partner::TYPE_SUPPLIER]);
    foreach ([[Invoice::TYPE_SALE, $customer, 100, 30], [Invoice::TYPE_PURCHASE, $supplier, 80, 20]] as [$type, $partner, $total, $paid]) {
        $invoice = Invoice::query()->create(['partner_id' => $partner->id, 'type' => $type, 'subtotal' => $total, 'total' => $total, 'initial_paid' => $paid, 'amount_paid' => $paid]);
        InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => $total, 'purchase_price' => 10, 'line_total' => $total, 'net_line_total' => $total]);
    }
    $financials = app(FinancialService::class);
    $financials->recordSettlement($customer->id, 'receivable', 20, now()->toDateString(), 'دفعة جزئية');
    $financials->recordSettlement($supplier->id, 'payable', 60, now()->toDateString(), 'تسوية كاملة');

    $customerRow = $financials->partnerBalancesQuery()->findOrFail($customer->id);
    $supplierRow = $financials->partnerBalancesQuery()->findOrFail($supplier->id);
    expect($financials->receivable($customerRow))->toBe('50.00')
        ->and($financials->payable($supplierRow))->toBe('0.00')
        ->and($financials->statementRows($customer)->last()['balance'])->toBe('50.00')
        ->and($financials->statementRows($supplier)->last()['balance'])->toBe('0.00');

    expect(fn () => $financials->recordSettlement($customer->id, 'receivable', 51, now()->toDateString()))
        ->toThrow(ValidationException::class);
    expect(LedgerSettlement::query()->where('partner_id', $customer->id)->count())->toBe(1);
});
