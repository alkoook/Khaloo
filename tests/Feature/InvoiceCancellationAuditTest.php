<?php

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\FinancialStatusService;
use App\Services\InvoiceCancellationService;
use App\Services\ReportService;
use Illuminate\Validation\ValidationException;

function cancellationFixture(string $type, int $stock, float $cost = 4): array
{
    $category = Category::query()->create(['name' => 'تدقيق الإلغاء']);
    $partner = Partner::query()->create([
        'name' => $type === Invoice::TYPE_SALE ? 'عميل التدقيق' : 'مورد التدقيق',
        'type' => $type === Invoice::TYPE_SALE ? Partner::TYPE_CUSTOMER : Partner::TYPE_SUPPLIER,
    ]);
    $product = Product::query()->create(['category_id' => $category->id, 'name' => 'صنف تدقيق', 'quantity' => $stock, 'purchase_price' => $cost, 'sale_price' => 10]);
    $invoice = Invoice::query()->create(['partner_id' => $partner->id, 'type' => $type, 'subtotal' => 20, 'total' => 20, 'initial_paid' => 5, 'amount_paid' => 5]);
    $line = InvoiceLine::query()->create([
        'invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'quantity' => 2, 'unit_price' => 10, 'purchase_price' => $cost, 'line_total' => 20, 'net_line_total' => 20,
    ]);

    return compact('partner', 'product', 'invoice', 'line');
}

test('sale cancellation preserves identity and lines and reverses stock exactly once', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice, 'line' => $line, 'product' => $product] = cancellationFixture(Invoice::TYPE_SALE, 8);

    app(InvoiceCancellationService::class)->cancel($invoice, $user->id, 'إدخال مكرر من المستخدم');

    $invoice->refresh();
    expect($invoice->isCancelled())->toBeTrue()
        ->and($invoice->cancelled_by)->toBe($user->id)
        ->and($invoice->cancellation_reason)->toBe('إدخال مكرر من المستخدم')
        ->and($invoice->cancelled_at)->not->toBeNull()
        ->and(Invoice::query()->find($invoice->id))->not->toBeNull()
        ->and(InvoiceLine::query()->find($line->id))->not->toBeNull()
        ->and($product->fresh()->quantity)->toBe('10.000');

    expect(fn () => app(InvoiceCancellationService::class)->cancel($invoice, $user->id, 'محاولة إلغاء ثانية'))
        ->toThrow(ValidationException::class);
    expect($product->fresh()->quantity)->toBe('10.000');
});

test('cancelled sale is excluded from receivables financial totals and active reports', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice, 'partner' => $partner] = cancellationFixture(Invoice::TYPE_SALE, 8);
    app(InvoiceCancellationService::class)->cancel($invoice, $user->id, 'إلغاء مالي موثق');

    $balance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($partner->id);
    $summary = app(FinancialStatusService::class)->summary();
    $report = app(ReportService::class)->preview('sales', []);
    expect(app(FinancialService::class)->receivable($balance))->toBe('0.00')
        ->and($summary['net_sales'])->toBe('0.00')
        ->and($report['count'])->toBe(0)
        ->and($report['total'])->toBe('0.00')
        ->and(Invoice::query()->active()->count())->toBe(0);

    $this->actingAs($user)->get(route('invoices.sales.show', $invoice))
        ->assertOk()->assertSee('هذه الفاتورة ملغاة')->assertSee('إلغاء مالي موثق');
});

test('purchase cancellation preserves the invoice and reverses stock and cost', function () {
    $user = User::factory()->create(['is_admin' => true]);
    ['invoice' => $invoice, 'line' => $line, 'product' => $product] = cancellationFixture(Invoice::TYPE_PURCHASE, 2, 10);

    app(InvoiceCancellationService::class)->cancel($invoice, $user->id, 'إلغاء توريد غير صحيح');

    expect($invoice->fresh()->isCancelled())->toBeTrue()
        ->and(InvoiceLine::query()->find($line->id))->not->toBeNull()
        ->and($product->fresh()->quantity)->toBe('0.000')
        ->and($product->fresh()->purchase_price)->toBeNull()
        ->and(app(FinancialStatusService::class)->summary()['purchases'])->toBe('0.00');
});
