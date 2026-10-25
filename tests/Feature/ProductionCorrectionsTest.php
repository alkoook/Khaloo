<?php

use App\Livewire\Invoices\Edit as InvoiceEdit;
use App\Livewire\Invoices\Returns\Create as ReturnCreate;
use App\Livewire\Payments\Register as PaymentRegister;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\FinancialStatusService;
use App\Services\InvoiceService;
use App\Support\Decimal as D;
use Livewire\Livewire;

function correctionProduct(string $name, string $stock = '0.000', string $cost = '4.00', string $price = '10.00'): Product
{
    return Product::query()->create([
        'name' => $name,
        'category_id' => Category::query()->firstOrCreate(['name' => 'تصحيحات الإنتاج'])->id,
        'quantity' => $stock,
        'purchase_price' => $cost,
        'sale_price' => $price,
    ]);
}

function correctionSale(Partner $partner, array $items, string $paid = '0.00'): Invoice
{
    $total = D::sum(collect($items)->map(fn (array $item) => D::mul($item['quantity'], $item['price'])));
    $invoice = Invoice::query()->create([
        'partner_id' => $partner->id,
        'type' => Invoice::TYPE_SALE,
        'subtotal' => $total,
        'total' => $total,
        'payable_total' => $total,
        'initial_paid' => $paid,
        'amount_paid' => $paid,
    ]);
    foreach ($items as $item) {
        InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $item['product']->id,
            'product_name' => $item['product']->name,
            'quantity' => $item['quantity'],
            'unit_price' => $item['price'],
            'purchase_price' => $item['product']->purchase_price,
            'sale_price' => $item['price'],
            'line_total' => D::mul($item['quantity'], $item['price']),
            'discount_amount' => '0.00',
            'net_line_total' => D::mul($item['quantity'], $item['price']),
        ]);
    }

    return $invoice;
}

test('the actual Livewire return form submits a partial return and redirects with the required success message', function () {
    $user = User::factory()->create();
    $partner = Partner::query()->create(['name' => 'السيد مرتجع جزئي', 'type' => Partner::TYPE_CUSTOMER]);
    $product = correctionProduct('مادة مرتجعة', '0.000', '4.00', '10.00');
    $invoice = correctionSale($partner, [['product' => $product, 'quantity' => '3.000', 'price' => '10.00']]);
    $line = $invoice->lines()->sole();

    $component = Livewire::actingAs($user)->test(ReturnCreate::class, ['invoice' => $invoice])
        ->assertSet('amountPaid', '0.00')
        ->assertSeeHtml('wire:submit="saveReturn"')
        ->assertSee('السعر الإفرادي')
        ->set('returnQuantities.'.$line->id, '2.000')
        ->call('saveReturn');

    $return = Invoice::query()->where('type', Invoice::TYPE_RETURN)->sole();
    $component->assertRedirect(route('invoices.sales.show', $return));
    $component->call('saveReturn');
    expect(session('success'))->toBe('تم إنشاء فاتورة المرتجع بنجاح.')
        ->and(Invoice::query()->where('type', Invoice::TYPE_RETURN)->count())->toBe(1)
        ->and($return->total)->toBe('20.00')
        ->and($product->fresh()->quantity)->toBe('2.000');
});

test('the Livewire return form handles multiple products and a full return', function () {
    $user = User::factory()->create();
    $partner = Partner::query()->create(['name' => 'السيد مرتجع كامل', 'type' => Partner::TYPE_CUSTOMER]);
    $first = correctionProduct('المادة الأولى');
    $second = correctionProduct('المادة الثانية', '0.000', '6.00', '20.00');
    $invoice = correctionSale($partner, [
        ['product' => $first, 'quantity' => '2.000', 'price' => '10.00'],
        ['product' => $second, 'quantity' => '3.000', 'price' => '20.00'],
    ], '80.00');
    $lines = $invoice->lines->keyBy('product_id');

    Livewire::actingAs($user)->test(ReturnCreate::class, ['invoice' => $invoice])
        ->set('returnQuantities.'.$lines[$first->id]->id, '2.000')
        ->set('returnQuantities.'.$lines[$second->id]->id, '3.000')
        ->set('amountPaid', '80.00')
        ->call('saveReturn')
        ->assertHasNoErrors();

    expect(Invoice::query()->where('type', Invoice::TYPE_RETURN)->sole()->lines)->toHaveCount(2)
        ->and($first->fresh()->quantity)->toBe('2.000')
        ->and($second->fresh()->quantity)->toBe('3.000');
});

test('return validation and business failures stay visible inside the Livewire form', function () {
    $user = User::factory()->create();
    $partner = Partner::query()->create(['name' => 'السيد فحص الأخطاء', 'type' => Partner::TYPE_CUSTOMER]);
    $product = correctionProduct('مادة محمية');
    $invoice = correctionSale($partner, [['product' => $product, 'quantity' => '1.000', 'price' => '10.00']]);
    $line = $invoice->lines()->sole();

    Livewire::actingAs($user)->test(ReturnCreate::class, ['invoice' => $invoice])
        ->set('amountPaid', '')
        ->call('saveReturn')
        ->assertHasErrors(['amountPaid'])
        ->assertSee('أدخل المبلغ المردود نقداً');

    Livewire::actingAs($user)->test(ReturnCreate::class, ['invoice' => $invoice])
        ->call('saveReturn')
        ->assertHasErrors(['return'])
        ->assertSee('يجب تحديد كمية لمنتج واحد على الأقل');

    Livewire::actingAs($user)->test(ReturnCreate::class, ['invoice' => $invoice])
        ->set('returnQuantities.'.$line->id, '2.000')
        ->call('saveReturn')
        ->assertHasErrors(['return'])
        ->assertSee('الكمية المتبقية القابلة للإرجاع');
});

test('a later settlement does not block the actual Livewire return submission', function () {
    $user = User::factory()->create();
    $partner = Partner::query()->create(['name' => 'السيد ذو التسوية', 'type' => Partner::TYPE_CUSTOMER]);
    $product = correctionProduct('مادة بعد التسوية');
    $invoice = correctionSale($partner, [['product' => $product, 'quantity' => '3.000', 'price' => '10.00']]);
    app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '10.00', today()->toDateString());

    Livewire::actingAs($user)->test(ReturnCreate::class, ['invoice' => $invoice])
        ->set('returnQuantities.'.$invoice->lines()->sole()->id, '1.000')
        ->call('saveReturn')
        ->assertHasNoErrors();

    expect(Invoice::query()->where('type', Invoice::TYPE_RETURN)->count())->toBe(1)
        ->and(LedgerSettlement::query()->count())->toBe(1);
});

test('editing 900 payment to 1900 recalculates invoice partner cash and statement without revenue duplication', function () {
    $user = User::factory()->create();
    $partner = Partner::query()->create(['name' => 'السيد تصحيح دفعة', 'type' => Partner::TYPE_CUSTOMER]);
    $product = correctionProduct('مادة دفعة');
    $invoice = correctionSale($partner, [['product' => $product, 'quantity' => '300.000', 'price' => '10.00']]);
    $settlement = app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '900.00', today()->toDateString(), 'القيمة قبل التصحيح', 'cash', $invoice->id);
    $salesBefore = app(FinancialStatusService::class)->summary()['net_sales'];

    Livewire::actingAs($user)->test(PaymentRegister::class)
        ->call('openEditModal', $settlement->id)
        ->assertSee('المبلغ المسجل حاليًا')
        ->set('editAmount', '1900.00')
        ->set('editSettlementDate', today()->toDateString())
        ->set('editPaymentMethod', 'bank_transfer')
        ->set('editInvoiceId', (string) $invoice->id)
        ->set('editNotes', 'تصحيح المبلغ الفعلي')
        ->call('savePaymentEdit')
        ->assertHasNoErrors()
        ->assertDispatched('flash', message: 'تم تعديل الدفعة بنجاح.', type: 'success');

    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    $status = app(FinancialStatusService::class)->summary();
    $audit = AuditLog::query()->where('auditable_type', LedgerSettlement::class)->where('auditable_id', $settlement->id)->where('event', 'updated')->sole();
    expect($settlement->fresh()->amount)->toBe('1900.00')
        ->and($settlement->fresh()->payment_method)->toBe('bank_transfer')
        ->and($invoice->fresh()->remaining)->toBe('1100.00')
        ->and(app(FinancialService::class)->receivable($balance))->toBe('1100.00')
        ->and($status['cash_inflows'])->toBe('1900.00')
        ->and($status['net_sales'])->toBe($salesBefore)
        ->and($audit->old_values['amount'])->toBe('900.00')
        ->and($audit->new_values['amount'])->toBe('1900.00');

    Livewire::actingAs($user)->test(PaymentRegister::class)->call('cancelPayment', $settlement->id)
        ->assertDispatched('flash', message: 'تم حذف الدفعة وإعادة احتساب الرصيد.', type: 'success');
    expect(LedgerSettlement::query()->count())->toBe(0)
        ->and(LedgerSettlement::withCancelled()->findOrFail($settlement->id)->isCancelled())->toBeTrue()
        ->and($invoice->fresh()->remaining)->toBe('3000.00')
        ->and(app(FinancialStatusService::class)->summary()['cash_inflows'])->toBe('0.00');
});

test('sales invoice with later settlement edits by stock deltas and records the audit trail', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $partner = Partner::query()->create(['name' => 'السيد تصحيح فاتورة', 'type' => Partner::TYPE_CUSTOMER]);
    $first = correctionProduct('مادة الفاتورة', '5.000', '4.00', '10.00');
    $second = correctionProduct('مادة بديلة', '8.000', '6.00', '12.00');
    $invoice = correctionSale($partner, [['product' => $first, 'quantity' => '5.000', 'price' => '10.00']]);
    app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '20.00', today()->toDateString(), 'تسوية لاحقة');

    expect(app(InvoiceService::class)->editBlockReason($invoice))->toBeNull();
    Livewire::actingAs($user)->test(InvoiceEdit::class, ['invoice' => $invoice])
        ->assertSet('invoice.id', $invoice->id)
        ->set('lines.0.quantity', '3.000')
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($first->fresh()->quantity)->toBe('7.000');

    Livewire::actingAs($user)->test(InvoiceEdit::class, ['invoice' => $invoice->fresh()])
        ->set('lines.0.quantity', '6.000')
        ->call('saveEdit')
        ->assertHasNoErrors();
    expect($first->fresh()->quantity)->toBe('4.000');

    Livewire::actingAs($user)->test(InvoiceEdit::class, ['invoice' => $invoice->fresh()])
        ->set('lines.0.product_id', $second->id)
        ->set('lines.0.quantity', '2.000')
        ->set('lines.0.unit_price', '12.00')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect($first->fresh()->quantity)->toBe('10.000')
        ->and($second->fresh()->quantity)->toBe('6.000')
        ->and($invoice->fresh()->total)->toBe('24.00')
        ->and(app(FinancialService::class)->receivable($balance))->toBe('4.00')
        ->and(AuditLog::query()->where('auditable_type', Invoice::class)->where('auditable_id', $invoice->id)->where('event', 'updated')->count())->toBe(3);
});

test('thermal documents share the configured geometry typography and reusable primitives', function () {
    $resolver = app(\App\Services\ThermalPrintSettings::class)->resolve();
    $styles = file_get_contents(resource_path('views/thermal/_styles.blade.php'));
    $quotation = file_get_contents(resource_path('views/quotations/thermal.blade.php'));
    $statement = file_get_contents(resource_path('views/statements/thermal.blade.php'));

    expect($resolver['paper_width_mm'])->toBe(80)
        ->and($resolver['content_width_mm'])->toBe(76)
        ->and($resolver['safe_margin_mm'])->toBe(2)
        ->and($styles)->toContain("--thermal-paper: {{ \$thermal['paper_width_mm'] }}mm", 'overflow: hidden', 'word-break: break-word', 'table-layout: fixed')
        ->and($quotation)->toContain("@include('thermal._styles')", "@include('thermal._header')", "@include('thermal._footer')")
        ->and($statement)->toContain("@include('thermal._styles')", "@include('thermal._header')", 'statement-movement__money');
});
