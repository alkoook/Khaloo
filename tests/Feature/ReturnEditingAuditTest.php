<?php

use App\Livewire\Invoices\Returns\Create as ReturnEditor;
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
use App\Services\ReturnService;
use App\Services\SalesAccountingService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    Http::fake();
});

function editableReturnSale(string $paid = '100.00', string $stock = '0.000'): array
{
    $partner = Partner::query()->create(['name' => 'السيد تدقيق تعديل المرتجع', 'type' => Partner::TYPE_CUSTOMER]);
    $category = Category::query()->create(['name' => 'تصنيف تعديل المرتجع']);
    $product = Product::query()->create([
        'category_id' => $category->id, 'name' => 'صنف تاريخي طويل للاختبار', 'quantity' => $stock,
        'purchase_price' => '4.0000', 'sale_price' => '10.00',
    ]);
    $sale = Invoice::query()->create([
        'partner_id' => $partner->id, 'type' => Invoice::TYPE_SALE, 'subtotal' => '100.00',
        'total' => '100.00', 'payable_total' => '100.00', 'initial_paid' => $paid, 'amount_paid' => $paid,
    ]);
    $line = InvoiceLine::query()->create([
        'invoice_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'quantity' => '10.000', 'unit_price' => '10.00', 'purchase_price' => '4.0000',
        'sale_price' => '10.00', 'line_total' => '100.00', 'discount_amount' => '0.00', 'net_line_total' => '100.00',
    ]);

    return compact('partner', 'product', 'sale', 'line');
}

test('editing a return down applies only the stock cash and account deltas', function () {
    ['partner' => $partner, 'product' => $product, 'sale' => $sale, 'line' => $line] = editableReturnSale();
    $service = app(ReturnService::class);
    $return = $service->processSalesReturn($sale, '30.00', [$line->id => '3.000'], null, today()->toDateString(), 'قبل التصحيح');
    $returnLineId = $return->lines()->sole()->id;

    $updated = $service->updateSalesReturn($return, '20.00', [$line->id => '2.000'], today()->toDateString(), 'بعد التصحيح');
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);

    expect($updated->id)->toBe($return->id)
        ->and($updated->lines()->sole()->id)->toBe($returnLineId)
        ->and($updated->lines()->sole()->quantity)->toBe('2.000')
        ->and($product->fresh()->quantity)->toBe('2.000')
        ->and($sale->fresh()->total)->toBe('100.00')
        ->and($sale->fresh()->lines()->sole()->quantity)->toBe('10.000')
        ->and(LedgerSettlement::query()->where('return_invoice_id', $return->id)->sole()->amount)->toBe('20.00')
        ->and(app(FinancialService::class)->signedBalance($balance))->toBe('0.00')
        ->and(AuditLog::query()->where('auditable_type', Invoice::class)->where('auditable_id', $return->id)->where('event', 'updated')->count())->toBe(1);
});

test('editing a return up excludes itself but includes every other active return', function () {
    ['product' => $product, 'sale' => $sale, 'line' => $line] = editableReturnSale();
    $service = app(ReturnService::class);
    $first = $service->processSalesReturn($sale, '20.00', [$line->id => '2.000']);
    $service->processSalesReturn($sale, '20.00', [$line->id => '2.000']);

    $rows = collect($service->returnableLines($sale->fresh(), $first->fresh()));
    expect($rows->sole()['returned_quantity'])->toBe('2.000')
        ->and($rows->sole()['available_quantity'])->toBe('8.000');

    $service->updateSalesReturn($first->fresh(), '40.00', [$line->id => '4.000'], today()->toDateString());
    expect($product->fresh()->quantity)->toBe('6.000')
        ->and($sale->fresh()->lines()->sole()->quantity)->toBe('10.000');

    expect(fn () => $service->updateSalesReturn($first->fresh(), '90.00', [$line->id => '9.000'], today()->toDateString()))
        ->toThrow(Exception::class, 'إجمالي المرتجعات سيتجاوز الكمية المباعة');
    expect($product->fresh()->quantity)->toBe('6.000');
});

test('partial cash refund leaves only the remainder as credit and edits reconcile it', function () {
    ['partner' => $partner, 'sale' => $sale, 'line' => $line] = editableReturnSale();
    $service = app(ReturnService::class);
    $return = $service->processSalesReturn($sale, '5.00', [$line->id => '2.000']);
    $partnerRow = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->signedBalance($partnerRow))->toBe('-15.00');

    $service->updateSalesReturn($return, '10.00', [$line->id => '3.000'], today()->toDateString());
    $partnerRow = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->signedBalance($partnerRow))->toBe('-20.00')
        ->and(LedgerSettlement::query()->where('return_invoice_id', $return->id)->sole()->amount)->toBe('10.00')
        ->and(app(FinancialStatusService::class)->summary()['refunds'])->toBe('10.00');
});

test('return edits preserve historical sale price discount allocation and cost snapshots', function () {
    ['product' => $product, 'sale' => $sale, 'line' => $line] = editableReturnSale();
    $sale->update(['subtotal' => '100.00', 'discount_type' => 'fixed', 'discount_value' => '10.00', 'discount_amount' => '10.00', 'total' => '90.00', 'payable_total' => '90.00', 'initial_paid' => '90.00', 'amount_paid' => '90.00']);
    $line->update(['discount_amount' => '10.00', 'net_line_total' => '90.00']);
    $service = app(ReturnService::class);
    $return = $service->processSalesReturn($sale->fresh(), '18.00', [$line->id => '2.000']);
    $product->update(['sale_price' => '99.00', 'purchase_price' => '20.0000']);

    $updated = $service->updateSalesReturn($return, '27.00', [$line->id => '3.000'], today()->toDateString());
    $returnLine = $updated->lines()->sole();
    expect($updated->total)->toBe('27.00')
        ->and($returnLine->unit_price)->toBe('10.00')
        ->and($returnLine->purchase_price)->toBe('4.0000')
        ->and($returnLine->discount_amount)->toBe('3.00')
        ->and($product->fresh()->sale_price)->toBe('99.00');
});

test('sale price correction keeps an existing return unchanged and rejects impossible economics', function () {
    ['product' => $product, 'sale' => $sale, 'line' => $line] = editableReturnSale();
    $return = app(ReturnService::class)->processSalesReturn($sale, '20.00', [$line->id => '2.000']);
    $returnBefore = $return->only(['id', 'total', 'amount_paid']);
    $returnLineBefore = $return->lines()->sole()->only(['id', 'quantity', 'unit_price', 'net_line_total']);

    app(InvoiceService::class)->updateInvoice($sale, [[
        'product_id' => $product->id, 'quantity' => '10.000', 'unit_price' => '12.00', 'sale_price' => '12.00',
    ]], '100.00');

    expect($return->fresh()->only(['id', 'total', 'amount_paid']))->toBe($returnBefore)
        ->and($return->fresh()->lines()->sole()->only(['id', 'quantity', 'unit_price', 'net_line_total']))->toBe($returnLineBefore)
        ->and($sale->fresh()->total)->toBe('120.00');

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('invoices.sales.show', $sale))
        ->assertOk()->assertSee('href="'.route('invoices.edit', $sale).'"', false);

    expect(fn () => app(InvoiceService::class)->updateInvoice($sale->fresh(), [[
        'product_id' => $product->id, 'quantity' => '10.000', 'unit_price' => '1.00', 'sale_price' => '1.00',
    ]], '10.00'))->toThrow(Exception::class, 'أقل من قيمة المرتجعات النشطة');

    expect($sale->fresh()->total)->toBe('120.00')
        ->and($return->fresh()->total)->toBe('20.00');
});

test('return accounting belongs to the selected return date in statements reports and status', function () {
    ['partner' => $partner, 'sale' => $sale, 'line' => $line] = editableReturnSale();
    $sale->forceFill(['created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'])->save();
    app(ReturnService::class)->processSalesReturn($sale->fresh(), '20.00', [$line->id => '2.000'], null, '2026-10-25');

    $octoberFirst = app(SalesAccountingService::class)->activity('2026-10-01', '2026-10-01');
    $octoberTwentyFifth = app(SalesAccountingService::class)->activity('2026-10-25', '2026-10-25');
    $statement = app(FinancialService::class)->statementData($partner, '2026-10-25', '2026-10-25');
    $status = app(FinancialStatusService::class)->summary('2026-10-25', '2026-10-25');

    expect($octoberFirst)->toHaveCount(1)->and($octoberFirst->sole()['invoice']->isSale())->toBeTrue()
        ->and($octoberTwentyFifth)->toHaveCount(1)->and($octoberTwentyFifth->sole()['invoice']->isReturn())->toBeTrue()
        ->and($statement['rows']->where('category', 'return'))->toHaveCount(1)
        ->and($status['returns_total'])->toBe('20.00');
});

test('the return editor exposes full width layout edit actions and duplicate submission protection', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['sale' => $sale, 'line' => $line] = editableReturnSale();
    $return = app(ReturnService::class)->processSalesReturn($sale, '10.00', [$line->id => '1.000']);

    Livewire::actingAs($user)->test(ReturnEditor::class, ['returnInvoice' => $return])
        ->assertSet('returnInvoice.id', $return->id)
        ->assertSee('تعديل المرتجع')
        ->assertSee('حفظ التعديلات')
        ->assertSee('جارٍ حفظ التعديلات...')
        ->assertSeeHtml('return-editor-form')
        ->assertSeeHtml('return-products-card')
        ->assertSeeHtml('return-details-card');

    $this->actingAs($user)->get(route('invoices.returns.index'))->assertOk()->assertSee('تعديل المرتجع');
    $this->actingAs($user)->get(route('invoices.sales.show', $return))->assertOk()->assertSee('تعديل المرتجع');
});

test('customer and supplier statements expose a document only high resolution screenshot', function () {
    $user = User::factory()->create();
    $customer = Partner::query()->create(['name' => 'السيد لقطة', 'type' => Partner::TYPE_CUSTOMER]);
    $supplier = Partner::query()->create(['name' => 'مورد لقطة', 'type' => Partner::TYPE_SUPPLIER]);

    foreach ([[$customer, 'كشف-حساب-السيد'], [$supplier, 'كشف-حساب-المورد']] as [$partner, $prefix]) {
        $this->actingAs($user)->get(route('statements.show', $partner))->assertOk()
            ->assertSee('لقطة شاشة')->assertSee('data-statement-document', false)->assertSee($prefix, false);
    }
    $script = file_get_contents(resource_path('js/document-screenshot.js'));
    expect($script)->toContain('Math.max(2', 'element.scrollHeight', "backgroundColor: '#ffffff'");
});

test('overpayment credit remains intact through return and payment correction workflows', function () {
    ['partner' => $partner, 'sale' => $sale, 'line' => $line] = editableReturnSale('150.00');
    $return = app(ReturnService::class)->processSalesReturn($sale, '0.00', [$line->id => '2.000']);
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->signedBalance($balance))->toBe('-70.00');

    $settlement = app(FinancialService::class)->recordSettlement($partner->id, 'receivable', '5.00', today()->toDateString());
    app(FinancialService::class)->updateSettlement($settlement, '15.00', today()->toDateString(), 'cash');
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect(app(FinancialService::class)->signedBalance($balance))->toBe('-85.00')
        ->and($return->fresh()->total)->toBe('20.00')
        ->and($sale->fresh()->total)->toBe('100.00');
});

test('cancelling a return reverses its own stock refund and credit without touching the sale', function () {
    ['partner' => $partner, 'product' => $product, 'sale' => $sale, 'line' => $line] = editableReturnSale();
    $service = app(ReturnService::class);
    $return = $service->processSalesReturn($sale, '20.00', [$line->id => '2.000']);

    $service->cancelSalesReturn($return, 'إدخال المرتجع بالخطأ');
    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    expect($return->fresh()->isCancelled())->toBeTrue()
        ->and($product->fresh()->quantity)->toBe('0.000')
        ->and($sale->fresh()->total)->toBe('100.00')
        ->and($sale->fresh()->lines()->sole()->quantity)->toBe('10.000')
        ->and(LedgerSettlement::query()->where('return_invoice_id', $return->id)->count())->toBe(0)
        ->and(LedgerSettlement::withCancelled()->where('return_invoice_id', $return->id)->sole()->isCancelled())->toBeTrue()
        ->and(app(FinancialService::class)->signedBalance($balance))->toBe('0.00')
        ->and(AuditLog::query()->where('auditable_id', $return->id)->where('event', 'cancelled')->count())->toBe(1);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('invoices.returns.index'))
        ->assertOk()->assertSee('#'.$return->id)->assertSee('ملغى')->assertDontSee('href="'.route('invoices.returns.edit', $return).'"', false);
});
