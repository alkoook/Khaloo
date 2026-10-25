<?php

use App\Exceptions\BusinessRuleException;
use App\Livewire\Invoices\Returns\Create as ReturnCreate;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\FinancialService;
use App\Services\FinancialStatusService;
use App\Services\ReturnService;
use App\Services\TelegramService;
use App\Support\Decimal as D;
use Livewire\Livewire;

function productionSale(Partner $partner, Product $product, string $total, string $paid, string $quantity = '10.000'): Invoice
{
    $invoice = Invoice::create(['partner_id' => $partner->id, 'type' => 'sale', 'subtotal' => $total, 'total' => $total, 'payable_total' => $total, 'initial_paid' => $paid, 'amount_paid' => $paid]);
    InvoiceLine::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => D::div($total, $quantity), 'purchase_price' => '4.0000', 'sale_price' => D::div($total, $quantity), 'line_total' => $total, 'discount_amount' => 0, 'net_line_total' => $total]);

    return $invoice;
}

test('later settlements permit consistent historical editing and never block a legitimate dated return', function () {
    $this->travelTo(now()->startOfDay()->subDays(2));
    $user = User::factory()->create(['is_admin' => true]);
    $partner = Partner::create(['name' => 'السيد صاحب التسوية', 'type' => 'customer']);
    $product = Product::create(['name' => 'صنف المرتجع', 'category_id' => Category::create(['name' => 'فحص المرتجع'])->id, 'quantity' => 0, 'purchase_price' => 9, 'sale_price' => 10]);
    $paidSale = productionSale($partner, $product, '100.00', '100.00');
    productionSale($partner, $product, '50.00', '0.00', '5.000');
    $saleDate = today()->toDateString();
    $this->travel(2)->days();
    app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '20.00', today()->toDateString());

    $this->actingAs($user)->get(route('invoices.edit', $paidSale))->assertOk()->assertSee('حفظ التعديلات');
    $this->actingAs($user)->get(route('invoices.returns.create', $paidSale))->assertOk()->assertSee('المنتجات القابلة للإرجاع');
    $return = app(ReturnService::class)->processSalesReturn($paidSale, '0.00', [$paidSale->lines()->sole()->id => '3.000']);

    expect($return->created_at->toDateString())->toBe(today()->toDateString())
        ->and($return->lines()->sole()->purchase_price)->toBe('4.0000')
        ->and($paidSale->fresh()->created_at->toDateString())->toBe($saleDate)
        ->and($paidSale->fresh()->initial_paid)->toBe('100.00')
        ->and(LedgerSettlement::count())->toBe(1)
        ->and($product->fresh()->quantity)->toBe('3.000');
    $today = app(FinancialStatusService::class)->summary(today()->toDateString(), today()->toDateString());
    expect($today['net_sales'])->toBe('-30.00')->and($today['cogs'])->toBe('-12.00');
    $this->travelBack();
});

test('debt invoice supports multiple partial returns and rejects excess quantity with a friendly business message', function () {
    $user = User::factory()->create();
    $partner = Partner::create(['name' => 'السيد متعدد المرتجعات', 'type' => 'customer']);
    $product = Product::create(['name' => 'صنف متعدد', 'category_id' => Category::create(['name' => 'مرتجعات متعددة'])->id, 'quantity' => 0, 'purchase_price' => 4, 'sale_price' => 10]);
    $sale = productionSale($partner, $product, '100.00', '20.00');
    app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '10.00', today()->toDateString());
    $line = $sale->lines()->sole();
    app(ReturnService::class)->processSalesReturn($sale, 0, [$line->id => '2.000']);
    app(ReturnService::class)->processSalesReturn($sale->fresh(), 0, [$line->id => '3.000']);

    expect($sale->fresh()->lines()->sole()->quantity)->toBe('10.000')->and($product->fresh()->quantity)->toBe('5.000');
    expect(fn () => app(ReturnService::class)->processSalesReturn($sale->fresh(), 0, [$line->id => '5.001']))
        ->toThrow(BusinessRuleException::class, 'الكمية المتبقية القابلة للإرجاع');
    Livewire::actingAs($user)->test(ReturnCreate::class, ['invoice' => $sale->fresh()])
        ->set('amountPaid', '0')->set('returnQuantities.'.$line->id, '5.001')->call('saveReturn')
        ->assertSee('الكمية المتبقية القابلة للإرجاع')->assertStatus(200);
});

test('independent return preserves the issued sale and credits the customer balance', function () {
    $partner = Partner::create(['name' => 'السيد دورة المرتجع المستقل', 'type' => 'customer']);
    $product = Product::create(['name' => 'صنف تاريخي', 'category_id' => Category::create(['name' => 'اختبار تاريخي'])->id, 'quantity' => 0, 'purchase_price' => 40, 'sale_price' => 100]);
    $sale = productionSale($partner, $product, '1000.00', '500.00', '10.000');
    $line = $sale->lines()->sole();

    $return = app(ReturnService::class)->processSalesReturn($sale, 0, [$line->id => '2.000']);
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    $statement = app(FinancialService::class)->thermalStatementData($partner);

    expect($sale->fresh()->total)->toBe('1000.00')
        ->and($line->fresh()->quantity)->toBe('10.000')
        ->and($return->total)->toBe('200.00')
        ->and($return->return_preserves_original)->toBeTrue()
        ->and(app(FinancialService::class)->signedBalance($balance))->toBe('300.00')
        ->and($statement['closing'])->toBe('300.00')
        ->and($statement['rows']->pluck('amount')->all())->toBe(['1000.00', '-500.00', '-200.00']);
});

test('payment correction after a return and a later payment supports overpayment and cancellation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $partner = Partner::create(['name' => 'السيد تصحيح الدفعة', 'type' => 'customer']);
    $product = Product::create(['name' => 'صنف تصحيح الدفعة', 'category_id' => Category::create(['name' => 'تصحيح الدفعة'])->id, 'quantity' => 0, 'purchase_price' => 40, 'sale_price' => 100]);
    $sale = productionSale($partner, $product, '1000.00', '0.00', '10.000');
    app(ReturnService::class)->processSalesReturn($sale, 0, [$sale->lines()->sole()->id => '2.000']);
    $payment = app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '500.00', today()->toDateString(), invoiceId: $sale->id);
    app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '100.00', today()->toDateString());

    app(FinancialService::class)->updateSettlement($payment, '1500.00', today()->toDateString(), 'cash', invoiceId: $sale->id);
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect($payment->fresh()->amount)->toBe('1500.00')
        ->and(app(FinancialService::class)->signedBalance($balance))->toBe('-800.00')
        ->and($payment->audits()->where('event', 'updated')->exists())->toBeTrue();

    app(FinancialService::class)->cancelSettlement($payment->fresh(), 'إلغاء دفعة مصححة للاختبار');
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->signedBalance($balance))->toBe('700.00')
        ->and(LedgerSettlement::withCancelled()->find($payment->id)->isCancelled())->toBeTrue();
});

test('sale correction after return applies net stock delta and rejects quantity below returned', function () {
    $partner = Partner::create(['name' => 'السيد تصحيح الفاتورة', 'type' => 'customer']);
    $product = Product::create(['name' => 'صنف تصحيح الفاتورة', 'category_id' => Category::create(['name' => 'تصحيح الفاتورة'])->id, 'quantity' => 5, 'purchase_price' => 40, 'sale_price' => 100]);
    $sale = productionSale($partner, $product, '500.00', '0.00', '5.000');
    app(ReturnService::class)->processSalesReturn($sale, 0, [$sale->lines()->sole()->id => '2.000']);

    expect(fn () => app(\App\Services\InvoiceService::class)->updateInvoice($sale, [[
        'product_id' => $product->id, 'quantity' => '1.000', 'unit_price' => '100.00', 'sale_price' => '100.00',
    ]], 0))->toThrow(BusinessRuleException::class, 'هناك 2.000 وحدة مسجلة ضمن مرتجعات سابقة');

    app(\App\Services\InvoiceService::class)->updateInvoice($sale, [[
        'product_id' => $product->id, 'quantity' => '5.000', 'unit_price' => '120.00', 'sale_price' => '120.00',
    ]], '1500.00');
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect($sale->fresh()->total)->toBe('600.00')
        ->and($sale->fresh()->amount_paid)->toBe('1500.00')
        ->and($sale->fresh()->lines()->sole()->quantity)->toBe('5.000')
        ->and($product->fresh()->quantity)->toBe('7.000')
        ->and(app(FinancialService::class)->signedBalance($balance))->toBe('-1100.00');
});

test('professional statements calculate opening filtered movements totals and running balance', function () {
    $user = User::factory()->create();
    $partner = Partner::create(['name' => 'السيد صاحب الكشف', 'phone' => '0990000000', 'type' => 'customer']);
    $product = Product::create(['name' => 'صنف كشف', 'category_id' => Category::create(['name' => 'كشف'])->id, 'quantity' => 0, 'purchase_price' => 4, 'sale_price' => 10]);
    $this->travelTo(now()->startOfDay()->subDays(3));
    $sale = productionSale($partner, $product, '100.00', '20.00');
    $this->travel(2)->days();
    app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '30.00', today()->toDateString());
    app(ReturnService::class)->processSalesReturn($sale, 0, [$sale->lines()->sole()->id => '2.000']);
    $from = today()->toDateString();
    $data = app(FinancialService::class)->statementData($partner, $from, $from);
    $thermal = app(FinancialService::class)->thermalStatementData($partner, $from, $from);
    expect($data['opening'])->toBe('80.00')->and($data['debit_total'])->toBe('0.00')
        ->and($data['credit_total'])->toBe('50.00')->and($data['closing'])->toBe('30.00')
        ->and($data['rows'])->toHaveCount(2)->and($data['rows']->last()['balance'])->toBe('30.00')
        ->and($thermal['opening'])->toBe('80.00')->and($thermal['closing'])->toBe('30.00')
        ->and($thermal['invoices_total'])->toBe('0.00')->and($thermal['payments_total'])->toBe('30.00')
        ->and($thermal['returns_total'])->toBe('20.00')->and($thermal['rows'])->toHaveCount(2);
    $this->actingAs($user)->get(route('statements.show', ['partner' => $partner, 'from' => $from, 'to' => $from]))
        ->assertOk()->assertSee('كشف حساب السيد')->assertSee('الرصيد الافتتاحي')->assertSee('0990000000');
    $this->actingAs($user)->get(route('statements.thermal', ['partner' => $partner, 'from' => $from, 'to' => $from]))
        ->assertOk()->assertSee('--thermal-paper: 80mm', false)->assertSee('--thermal-content: 76mm', false)->assertSee('الرصيد الحالي')
        ->assertSee('الحركات')->assertSee('المبلغ')->assertDontSee('إجمالي المدين')->assertDontSee('المرجع');
    $telegram = Mockery::mock(TelegramService::class);
    $telegram->shouldReceive('sendDocument')->once()->withArgs(fn ($path, $caption) => is_file($path) && str_contains($caption, $partner->name))->andReturnTrue();
    app()->instance(TelegramService::class, $telegram);
    $this->actingAs($user)->post(route('statements.telegram', $partner), ['from' => $from, 'to' => $from])->assertSessionHas('success');
    $this->travelBack();
});

test('supplier statement records purchase and payments without creating a second purchase', function () {
    $supplier = Partner::create(['name' => 'مورد الكشف', 'phone' => '0980000000', 'type' => 'supplier']);
    $product = Product::create(['name' => 'صنف مورد', 'category_id' => Category::create(['name' => 'مورد'])->id, 'quantity' => 10, 'purchase_price' => 5, 'sale_price' => 10]);
    $purchase = Invoice::create(['partner_id' => $supplier->id, 'type' => 'purchase', 'subtotal' => 100, 'total' => 100, 'initial_paid' => 25, 'amount_paid' => 25]);
    InvoiceLine::create(['invoice_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 10, 'unit_price' => 10, 'purchase_price' => 10, 'line_total' => 100]);
    app(FinancialService::class)->recordSettlement($supplier->id, 'payable', '35.00', today()->toDateString());
    $data = app(FinancialService::class)->statementData($supplier);
    $thermal = app(FinancialService::class)->thermalStatementData($supplier);
    expect($data['debit_total'])->toBe('100.00')->and($data['credit_total'])->toBe('60.00')
        ->and($data['closing'])->toBe('40.00')->and(Invoice::where('type', 'purchase')->count())->toBe(1)
        ->and($thermal['invoices_total'])->toBe('100.00')->and($thermal['payments_total'])->toBe('60.00')
        ->and($thermal['closing'])->toBe('40.00')->and($thermal['rows'])->toHaveCount(3);
});

test('quotation exposes thermal word telegram and screenshot outputs', function () {
    $user = User::factory()->create();
    $partner = Partner::create(['name' => 'السيد صاحب العرض', 'phone' => '0970000000', 'type' => 'customer']);
    $quote = Quotation::create(['number' => 'Q-2026-LONG-123456789', 'partner_id' => $partner->id, 'quotation_date' => today(), 'subtotal' => 100, 'discount_type' => 'fixed', 'discount_value' => 0, 'discount_amount' => 0, 'discount' => 0, 'total' => 100, 'notes' => 'ملاحظة عربية طويلة للاختبار']);
    QuotationLine::create(['quotation_id' => $quote->id, 'product_name' => str_repeat('مادة طويلة ', 8), 'quantity' => '2.500', 'unit_price' => 40, 'line_total' => 100]);
    $this->actingAs($user)->get(route('quotations.print', $quote))->assertOk()->assertSee('captureQuotation')->assertSee('تنزيل Word')->assertSee('طباعة حرارية');
    $this->actingAs($user)->get(route('quotations.thermal', $quote))->assertOk()->assertSee('--thermal-content: 76mm', false)
        ->assertSee('Q-2026-LONG-123456789')->assertSee('الصنف')->assertSee('الإجمالي النهائي');
    $response = $this->actingAs($user)->get(route('quotations.word', $quote));
    $response->assertOk()->assertDownload();
    $file = $response->baseResponse->getFile()->getPathname();
    expect(file_get_contents($file, false, null, 0, 2))->toBe('PK');
    $telegram = Mockery::mock(TelegramService::class);
    $telegram->shouldReceive('sendDocument')->once()->withArgs(fn ($path, $caption) => is_file($path) && str_contains($caption, $quote->number) && str_contains($caption, $partner->name))->andReturnTrue();
    app()->instance(TelegramService::class, $telegram);
    $this->actingAs($user)->post(route('quotations.telegram', $quote))->assertSessionHas('success');
});

test('receipt uses physical roll width with symmetric safe margins and real feed line command', function () {
    $css = file_get_contents(resource_path('css/receipt.css'));
    $view = file_get_contents(resource_path('views/invoices/receipt.blade.php'));
    $settings = file_get_contents(resource_path('views/livewire/settings/index.blade.php'));
    $script = file_get_contents(base_path('scripts/send-receipt-cut.ps1'));
    expect($css)->toContain('--receipt-safe-margin: 2mm', '--receipt-content-width', 'margin: 0 var(--receipt-safe-margin)')
        ->not->toContain('translateX(-')
        ->and($view)->toContain('@page { size: {{ $width }}mm auto; margin: 0; }')
        ->and($settings)->toContain('أسطر تغذية الورق بعد الفاتورة', 'تضيف مسافة فارغة بعد نهاية الفاتورة')
        ->and($script)->toContain('0x1B, 0x64', '$FeedLines', '0x1D, 0x56');
});

test('backup resolver prefers an explicit executable path and never exposes arbitrary exception messages', function () {
    $fake = tempnam(sys_get_temp_dir(), 'mysqldump-test-');
    config()->set('backup.mysqldump_path', $fake);
    expect(app(DatabaseBackupService::class)->detectedExecutable())->toBe(realpath($fake));
    @unlink($fake);
    $admin = User::factory()->create(['is_admin' => true]);
    $mock = Mockery::mock(DatabaseBackupService::class);
    $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('password=must-not-leak'));
    app()->instance(DatabaseBackupService::class, $mock);
    $this->actingAs($admin)->post(route('settings.database-backup'))->assertSessionHas('error', 'تعذر إنشاء النسخة الاحتياطية بسبب خطأ غير متوقع. حاول مجددًا.');
});
