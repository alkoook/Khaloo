<?php

use App\Livewire\Invoices\Edit;
use App\Livewire\Invoices\Purchases\Create as PurchaseCreate;
use App\Livewire\Invoices\Sales\Create as SaleCreate;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\FinancialStatusService;
use App\Services\InvoiceCancellationService;
use App\Services\InvoicePricingService;
use App\Services\InvoiceService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SalesAccountingService;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

function decimalProduct(string $stock = '10.000', string $cost = '4.0000', string $price = '10.00'): Product
{
    return Product::create(['name' => 'منتج بالوزن', 'category_id' => Category::create(['name' => 'وزنيات'])->id,
        'quantity' => $stock, 'purchase_price' => $cost, 'sale_price' => $price]);
}

function decimalSale(Product $product, string $qty = '1.250', string $paid = '0.00', string $delivery = '0.00', array $additional = []): Invoice
{
    $customer = Partner::create(['name' => 'عميل الأوزان', 'type' => 'customer']);
    Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test(SaleCreate::class)
        ->set('partner_id', $customer->id)->call('selectProduct', $product->id)->set('lines.0.quantity', $qty)
        ->set('hasDelivery', D::cmp($delivery, 0) > 0)->set('deliveryAmount', $delivery)
        ->set('additionalLines', $additional)->set('amount_paid', $paid)->call('save')->assertHasNoErrors();

    return Invoice::where('type', 'sale')->latest('id')->firstOrFail();
}

test('decimal pricing is exact at large values and conserves discount cents', function () {
    expect(D::add('9999999999.99', '0.01'))->toBe('10000000000.00');
    $pricing = app(InvoicePricingService::class)->calculate([
        ['quantity' => '0.500', 'unit_price' => '0.10'], ['quantity' => '1.250', 'unit_price' => '10.00'],
        ['quantity' => '2.750', 'unit_price' => '3.33'],
    ], 'percentage', '12.50');
    expect($pricing['subtotal'])->toBe('21.71')->and($pricing['discount_amount'])->toBe('2.71')
        ->and(D::sum(array_column($pricing['lines'], 'net_line_total')))->toBe('19.00')
        ->and(D::sum(array_column($pricing['lines'], 'discount_amount')))->toBe('2.71');
    $tiny = app(InvoicePricingService::class)->calculate(array_fill(0, 100, ['quantity' => '0.001', 'unit_price' => '10.00']), 'fixed', '0.05');
    expect(D::sum(array_column($tiny['lines'], 'discount_amount')))->toBe('0.05')
        ->and(D::sum(array_column($tiny['lines'], 'net_line_total')))->toBe('0.95');
});

test('existing decimal schema upgrade is non destructive and can be rerun', function () {
    Storage::fake('local');
    $product = decimalProduct('2.750');
    $this->artisan('khaloo:decimal-quantities')->assertSuccessful();
    $this->artisan('khaloo:decimal-quantities --apply')->assertSuccessful();
    $this->artisan('khaloo:decimal-quantities --apply')->assertSuccessful();
    expect($product->fresh()->quantity)->toBe('2.750')->and(Product::count())->toBe(1);
});

test('decimal sale create edit return and receipt preserve quantity and historical cogs', function () {
    $product = decimalProduct();
    $sale = decimalSale($product, '1.250', '0.00', '2.00', [
        ['description' => 'غرض عابر', 'quantity' => '0.500', 'unit_amount' => '6.00', 'source_note' => 'من محل مجاور'],
    ]);
    expect($product->fresh()->quantity)->toBe('8.750')->and($sale->collectibleTotal())->toBe('17.50');
    $product->update(['purchase_price' => '100.0000']);
    $profit = app(SalesAccountingService::class)->profitability($sale);
    expect($profit['cogs'])->toBe('5.00')->and($profit['profit'])->toBe('9.50')->and($profit['margin'])->toBe('65.52');
    Livewire::test(Edit::class, ['invoice' => $sale])->set('lines.0.quantity', '1.750')->set('amountPaid', '0')->call('saveEdit')->assertHasNoErrors();
    expect($product->fresh()->quantity)->toBe('8.250')->and($sale->fresh()->lines()->sole()->purchase_price)->toBe('4.0000');
    $line = $sale->fresh()->lines()->sole();
    app(ReturnService::class)->processSalesReturn($sale->fresh(), '0.00', [$line->id => '0.500']);
    expect($product->fresh()->quantity)->toBe('8.750')->and($line->fresh()->quantity)->toBe('1.250')
        ->and(app(SalesAccountingService::class)->profitability($sale->fresh())['profit'])->toBe('9.50');
    $this->get(route('invoices.sales.show', $sale))->assertOk()->assertSee('ربحية الفاتورة')->assertSee('1.25');
    $this->get(route('invoices.receipt', $sale))->assertOk()->assertSee('1.25');
});

test('decimal purchase weighted average edit and cancellation preserve stock value', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $product = decimalProduct('1.250', '4.0000');
    $supplier = Partner::create(['name' => 'مورد أوزان', 'type' => 'supplier']);
    Livewire::actingAs($user)->test(PurchaseCreate::class)->set('partner_id', $supplier->id)
        ->call('selectProduct', $product->id)->set('lines.0.quantity', '0.750')->set('lines.0.unit_price', '8.00')
        ->set('amount_paid', '0')->call('save')->assertHasNoErrors();
    $purchase = Invoice::where('type', 'purchase')->sole();
    expect($product->fresh()->quantity)->toBe('2.000')->and($product->fresh()->purchase_price)->toBe('5.5000');
    Livewire::test(Edit::class, ['invoice' => $purchase])->set('lines.0.quantity', '1.250')->set('amountPaid', '0')->call('saveEdit')->assertHasNoErrors();
    expect($product->fresh()->quantity)->toBe('2.500')->and($product->fresh()->purchase_price)->toBe('6.0000');
    app(InvoiceCancellationService::class)->cancel($purchase->fresh(), $user->id, 'إلغاء شراء اختباري');
    expect($product->fresh()->quantity)->toBe('1.250')->and($product->fresh()->purchase_price)->toBe('4.0000');
    expect(fn () => app(InvoiceCancellationService::class)->cancel($purchase->fresh(), $user->id, 'إلغاء مرة أخرى'))->toThrow(ValidationException::class);
    expect($product->fresh()->quantity)->toBe('1.250');
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['purchases'])->toBe('0.00')->and($summary['inventory_value'])->toBe('5.00')->and($summary['payables'])->toBe('0.00');
});

test('fractional stock below one remains selectable but overselling and excessive precision are rejected', function () {
    $product = decimalProduct('0.500');
    $user = User::factory()->create(['is_admin' => true]);
    Livewire::actingAs($user)->test(SaleCreate::class)->call('selectProduct', $product->id)->assertSet('lines.0.quantity', '0.500')
        ->set('amount_paid', '5.00')->call('save')->assertHasNoErrors();
    $sale = Invoice::where('type', 'sale')->sole();
    expect($product->fresh()->quantity)->toBe('0.000');
    Livewire::test(Edit::class, ['invoice' => $sale])->assertSet('originalQuantities.'.$product->id, '0.500');
    expect(fn () => app(InvoiceService::class)->updateInvoice($sale, [['product_id' => $product->id, 'quantity' => '0.501', 'unit_price' => '10.00']], '0.00'))->toThrow(Exception::class);
    Livewire::test(SaleCreate::class)->set('lines', [['product_id' => $product->id, 'product_name' => $product->name, 'available' => '0.000', 'quantity' => '0.0001', 'unit_price' => '10.00']])->call('save')->assertHasErrors('lines.0.quantity');
});

test('pass through advance and partial then full reimbursement net to zero without revenue or profit inflation', function () {
    $product = decimalProduct('10.000', '40000.0000', '100000.00');
    $sale = decimalSale($product, '1.000', '50000.00', '5000.00', [
        ['description' => 'طلب خارجي', 'quantity' => '1.000', 'unit_amount' => '10000.00', 'source_note' => 'محل مجاور'],
    ]);
    $financials = app(FinancialService::class);
    $summary = app(FinancialStatusService::class)->summary();
    expect($sale->collectibleTotal())->toBe('115000.00')->and($summary['cash_remaining'])->toBe('40000.00')
        ->and($summary['receivables'])->toBe('65000.00')->and($summary['gross_profit'])->toBe('65000.00');
    $financials->recordSettlement($sale->partner_id, 'receivable', '15000.00', now()->toDateString());
    expect(app(FinancialStatusService::class)->summary()['receivables'])->toBe('50000.00');
    $financials->recordSettlement($sale->partner_id, 'receivable', '50000.00', now()->toDateString());
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['cash_inflows'])->toBe('115000.00')->and($summary['cash_outflows'])->toBe('10000.00')
        ->and($summary['cash_remaining'])->toBe('105000.00')->and($summary['net_sales'])->toBe('100000.00')
        ->and($summary['delivery_income'])->toBe('5000.00')->and($summary['gross_profit'])->toBe('65000.00')
        ->and($summary['receivables'])->toBe('0.00')->and(InvoiceLine::where('invoice_id', $sale->id)->count())->toBe(1);
});

test('historical sale period and return period use separate events and reconcile statement balances', function () {
    $this->travelTo(now()->startOfDay()->subDays(5));
    $product = decimalProduct();
    $sale = decimalSale($product, '2.750');
    $saleDate = now()->toDateString();
    $this->travel(3)->days();
    $returnDate = now()->toDateString();
    $line = $sale->lines()->sole();
    app(ReturnService::class)->processSalesReturn($sale, '0.00', [$line->id => '1.250']);
    $finance = app(FinancialService::class);
    expect($finance->signedBalance($finance->partnerBalancesQuery(null, null, $saleDate)->findOrFail($sale->partner_id)))->toBe('27.50');
    $rows = $finance->statementRows($sale->partner, $returnDate, $returnDate);
    expect($rows)->toHaveCount(1)->and($rows->last()['balance'])->toBe('15.00');
    $old = app(FinancialStatusService::class)->summary($saleDate, $saleDate);
    $returned = app(FinancialStatusService::class)->summary($returnDate, $returnDate);
    expect($old['net_sales'])->toBe('27.50')->and($old['cogs'])->toBe('11.00')->and($old['gross_profit'])->toBe('16.50')
        ->and($returned['net_sales'])->toBe('-12.50')->and($returned['cogs'])->toBe('-5.00')
        ->and($returned['gross_profit'])->toBe('-7.50')->and($returned['cash_remaining'])->toBe('0.00')
        ->and(app(FinancialStatusService::class)->summary()['net_sales'])->toBe('15.00');
    foreach ([[$saleDate, '27.50', '2.750'], [$returnDate, '-12.50', '-1.250']] as [$day, $expected, $quantity]) {
        $path = app(ReportService::class)->export('sales', ['from' => $day, 'to' => $day]);
        try {
            $book = IOFactory::load($path);
            expect(D::scale($book->getSheet(0)->getCell('I6')->getValue()))->toBe($expected)
                ->and(D::scale($book->getSheet(0)->getCell('L6')->getValue()))->toBe($expected)
                ->and(D::sum(collect($book->getSheet(1)->toArray())->skip(1)->pluck(5), 3))->toBe($quantity);
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }
    $this->travelBack();
});

test('cash settlements expenses and current position do not move into the original invoice period', function () {
    $this->travelTo(now()->startOfDay()->subDays(2));
    $product = decimalProduct();
    $sale = decimalSale($product, '10.000');
    $supplier = Partner::create(['name' => 'مورد نقد', 'type' => 'supplier']);
    Invoice::create(['type' => 'purchase', 'partner_id' => $supplier->id, 'total' => '80.00', 'amount_paid' => '0.00', 'initial_paid' => '0.00']);
    $oldDate = now()->toDateString();
    $this->travel(2)->days();
    app(FinancialService::class)->recordSettlement($sale->partner_id, 'receivable', '30.25', now()->toDateString());
    app(FinancialService::class)->recordSettlement($supplier->id, 'payable', '20.15', now()->toDateString());
    Expense::create(['amount' => '2.10', 'reason' => 'تشغيل', 'date' => now()->toDateString()]);
    $summary = app(FinancialStatusService::class)->summary(now()->toDateString(), now()->toDateString());
    expect($summary['net_sales'])->toBe('0.00')->and($summary['purchases'])->toBe('0.00')
        ->and($summary['net_profit'])->toBe('-2.10')->and($summary['cash_remaining'])->toBe('8.00')
        ->and($summary['receivables'])->toBe('69.75')->and($summary['payables'])->toBe('59.85');
    $historical = app(FinancialStatusService::class)->summary($oldDate, $oldDate);
    expect($historical['cash_remaining'])->toBe('0.00')->and($historical['current_cash_position'])->toBe('8.00');
    $this->travelBack();
});

test('editing cannot backdate a later cash payment into the original invoice date', function () {
    $this->travelTo(now()->startOfDay()->subDays(2));
    $product = decimalProduct();
    $sale = decimalSale($product);
    $originalDate = now()->toDateString();
    $this->travel(2)->days();
    $lines = [['product_id' => $product->id, 'quantity' => '1.250', 'unit_price' => '10.00']];
    expect(fn () => app(InvoiceService::class)->updateInvoice($sale, $lines, '1.00'))->toThrow(Exception::class);
    expect($sale->fresh()->amount_paid)->toBe('0.00')->and($product->fresh()->quantity)->toBe('8.750');
    app(FinancialService::class)->recordSettlement($sale->partner_id, 'receivable', '1.00', now()->toDateString());
    expect(app(FinancialStatusService::class)->summary($originalDate, $originalDate)['cash_inflows'])->toBe('0.00')
        ->and(app(FinancialStatusService::class)->summary(now()->toDateString(), now()->toDateString())['cash_inflows'])->toBe('1.00');
    $this->travelBack();
});

test('fractional returns conserve every cent even after several very small returns', function () {
    $product = decimalProduct('0.006', '1.0000', '3.33');
    $sale = decimalSale($product, '0.006', '0.02');
    $line = $sale->lines()->sole();
    $service = app(ReturnService::class);
    $first = $service->processSalesReturn($sale, '0.01', [$line->id => '0.002']);
    $second = $service->processSalesReturn($sale->fresh(), '0.01', [$line->id => '0.002']);
    $third = $service->processSalesReturn($sale->fresh(), '0.00', [$line->id => '0.002']);
    expect(D::sum([$first->total, $second->total, $third->total]))->toBe('0.02')
        ->and($sale->fresh()->subtotal)->toBe('0.00')->and($sale->fresh()->total)->toBe('0.00')
        ->and($line->fresh()->quantity)->toBe('0.000')->and($product->fresh()->quantity)->toBe('0.006');
});

test('cancelled sales exclude revenue cash and cogs and restore only merchandise stock', function () {
    $product = decimalProduct();
    $sale = decimalSale($product, '0.500', '8.00', '1.00', [
        ['description' => 'خارجي', 'quantity' => '1.000', 'unit_amount' => '2.00'],
    ]);
    app(InvoiceCancellationService::class)->cancel($sale, auth()->id(), 'إلغاء فاتورة اختبار');
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['net_sales'])->toBe('0.00')->and($summary['cash_remaining'])->toBe('0.00')
        ->and($summary['cogs'])->toBe('0.00')->and($product->fresh()->quantity)->toBe('10.000')
        ->and(app(SalesAccountingService::class)->profitability($sale->fresh())['profit'])->toBe('0.00');
});

test('returns and cancellations restore inventory at historical cost after the average cost changes', function () {
    $product = decimalProduct('2.000', '4.0000');
    $sale = decimalSale($product, '1.250', '12.50');
    // Represents a later purchase changing the current weighted average.
    $product->refresh()->update(['quantity' => '2.000', 'purchase_price' => '8.0000']);
    app(ReturnService::class)->processSalesReturn($sale, '5.00', [$sale->lines()->sole()->id => '0.500']);
    expect($product->fresh()->quantity)->toBe('2.500')->and($product->fresh()->purchase_price)->toBe('7.2000')
        ->and(app(SalesAccountingService::class)->profitability($sale->fresh())['cogs'])->toBe('3.00');
    $other = decimalProduct('2.000', '4.0000');
    $cancelled = decimalSale($other, '0.500', '5.00');
    $other->refresh()->update(['quantity' => '2.000', 'purchase_price' => '8.0000']);
    app(InvoiceCancellationService::class)->cancel($cancelled, auth()->id(), 'إلغاء بعد تغير المتوسط');
    expect($other->fresh()->quantity)->toBe('2.500')->and($other->fresh()->purchase_price)->toBe('7.2000');
});

test('Arabic report filenames include actual filters and profit exports agree with accounting events', function () {
    $product = decimalProduct();
    $sale = decimalSale($product, '1.250', '0.00', '2.00');
    $service = app(ReportService::class);
    $filters = ['from' => now()->toDateString(), 'to' => now()->toDateString(), 'partner_id' => $sale->partner_id];
    $path = $service->export('profit', $filters);
    try {
        expect(basename($path))->toContain('تقرير الأرباح', 'العميل عميل الأوزان', 'من تاريخ', 'إلى تاريخ')
            ->not->toMatch('/partner_id|customer_id|from_date|[A-Za-z]{2,}(?=\-)/');
        $sheet = IOFactory::load($path)->getActiveSheet();
        expect(D::scale($sheet->getCell('B6')->getValue()))->toBe('12.50')
            ->and(D::scale($sheet->getCell('C6')->getValue()))->toBe('2.00')
            ->and(D::scale($sheet->getCell('D6')->getValue()))->toBe('5.00')
            ->and(D::scale($sheet->getCell('E6')->getValue()))->toBe('9.50');
    } finally {
        @unlink($path);
    }
});
