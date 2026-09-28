<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\MoneyParty;
use App\Models\MoneyPartyMovement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseFundingSource;
use App\Models\User;
use App\Services\FinancialStatusService;
use App\Services\ReturnService;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

function financialProduct(float $cost = 5, int $quantity = 10): Product
{
    $category = Category::query()->create(['name' => 'تصنيف مالي']);

    return Product::query()->create([
        'category_id' => $category->id, 'name' => 'منتج مالي',
        'purchase_price' => $cost, 'sale_price' => 10, 'quantity' => $quantity,
    ]);
}

function financialInvoice(string $type, ?Partner $partner, float $total, float $paid, Product $product, int $quantity, ?string $date = null): Invoice
{
    $invoice = Invoice::query()->create([
        'partner_id' => $partner?->id, 'type' => $type, 'total' => $total,
        'initial_paid' => $paid, 'amount_paid' => $paid,
        'created_at' => $date ?? now(), 'updated_at' => $date ?? now(),
    ]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id, 'product_id' => $product->id,
        'product_name' => $product->name, 'quantity' => $quantity,
        'unit_price' => $total / $quantity, 'purchase_price' => $product->purchase_price,
        'line_total' => $total,
    ]);
    if ($date) {
        $invoice->forceFill(['created_at' => $date, 'updated_at' => $date])->save();
    }

    return $invoice;
}

test('financial status keeps profit separate from cash and does not deduct purchases from profit', function () {
    $product = financialProduct(5, 30);
    $customer = Partner::query()->create(['name' => 'عميل مالي', 'type' => Partner::TYPE_CUSTOMER]);
    $supplier = Partner::query()->create(['name' => 'مورد مالي', 'type' => Partner::TYPE_SUPPLIER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 100, 40, $product, 10);
    financialInvoice(Invoice::TYPE_PURCHASE, $supplier, 100, 100, $product, 10);
    Expense::query()->create(['amount' => 10, 'reason' => 'مصروف', 'date' => now()->toDateString()]);

    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($summary['net_sales'])->toBe(100.0)
        ->and($summary['cogs'])->toBe(50.0)
        ->and($summary['net_profit'])->toBe(40.0)
        ->and($summary['direct_cash_purchases'])->toBe(100.0)
        ->and($summary['cash_remaining'])->toBe(-70.0);
});

test('sales returns reduce net sales cogs inventory and cash only when a refund is paid', function () {
    $product = financialProduct(5, 0);
    $customer = Partner::query()->create(['name' => 'عميل مرتجع مالي', 'type' => Partner::TYPE_CUSTOMER]);
    $sale = financialInvoice(Invoice::TYPE_SALE, $customer, 20, 20, $product, 2);
    $line = $sale->lines()->first();

    app(ReturnService::class)->processSalesReturn($sale, 10, [$line->id => 1], (string) \Illuminate\Support\Str::uuid());
    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($sale->fresh()->total)->toBe('10.00')
        ->and($product->fresh()->quantity)->toBe(1)
        ->and($summary['net_sales'])->toBe(10.0)
        ->and($summary['cogs'])->toBe(5.0)
        ->and($summary['cash_remaining'])->toBe(10.0);
});

test('receivables and payables remain visible independently from collected cash', function () {
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل ذمة', 'type' => Partner::TYPE_CUSTOMER]);
    $supplier = Partner::query()->create(['name' => 'مورد ذمة', 'type' => Partner::TYPE_SUPPLIER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 100, 30, $product, 10);
    financialInvoice(Invoice::TYPE_PURCHASE, $supplier, 80, 20, $product, 8);

    $summary = app(FinancialStatusService::class)->summary();

    expect($summary['receivables'])->toBe(70.0)
        ->and($summary['payables'])->toBe(60.0)
        ->and($summary['sales_collected'])->toBe(30.0);
});

test('a purchase funded from money party balance is not deducted twice from project cash', function () {
    $product = financialProduct();
    $party = MoneyParty::query()->create(['name' => 'جهة شراء']);
    MoneyPartyMovement::query()->create([
        'money_party_id' => $party->id, 'direction' => MoneyPartyMovement::DIRECTION_TO_PARTY,
        'category' => MoneyPartyMovement::CATEGORY_PURCHASE_ADVANCE, 'amount' => 50,
        'currency' => 'SYP', 'exchange_rate_snapshot' => 1, 'amount_base_currency' => 50,
        'date' => now()->toDateString(), 'status' => MoneyPartyMovement::STATUS_ACTIVE,
    ]);
    $purchase = financialInvoice(Invoice::TYPE_PURCHASE, null, 50, 50, $product, 5);
    PurchaseFundingSource::query()->create(['invoice_id' => $purchase->id, 'money_party_id' => $party->id, 'amount_base_currency' => 50]);

    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($summary['direct_cash_purchases'])->toBe(0.0)
        ->and($summary['money_to_parties'])->toBe(50.0)
        ->and($summary['cash_remaining'])->toBe(-50.0)
        ->and($summary['money_at_parties'])->toBe(0.0);
});

test('direct cash purchase reduces cash once and money party cancelled or unclassified movements stay untrusted', function () {
    $product = financialProduct();
    financialInvoice(Invoice::TYPE_PURCHASE, null, 30, 30, $product, 3);
    $party = MoneyParty::query()->create(['name' => 'جهة تحذير']);
    foreach ([[MoneyPartyMovement::CATEGORY_UNCLASSIFIED, MoneyPartyMovement::STATUS_ACTIVE, 12], [MoneyPartyMovement::CATEGORY_PURCHASE_ADVANCE, MoneyPartyMovement::STATUS_CANCELLED, 20]] as [$category, $status, $amount]) {
        MoneyPartyMovement::query()->create([
            'money_party_id' => $party->id, 'direction' => MoneyPartyMovement::DIRECTION_TO_PARTY,
            'category' => $category, 'amount' => $amount, 'currency' => 'SYP',
            'exchange_rate_snapshot' => 1, 'amount_base_currency' => $amount,
            'date' => now()->toDateString(), 'status' => $status,
        ]);
    }

    $summary = app(FinancialStatusService::class)->summary();

    expect($summary['direct_cash_purchases'])->toBe(30.0)
        ->and($summary['cash_remaining'])->toBe(-30.0)
        ->and($summary['unclassified_count'])->toBe(1)
        ->and($summary['unclassified_amount'])->toBe(12.0)
        ->and($summary['money_at_parties'])->toBe(0.0);
});

test('financial status respects the selected date range and renders for authenticated user', function () {
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل تاريخ', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 40, 40, $product, 4, now()->subMonth()->startOfMonth()->toDateTimeString());
    financialInvoice(Invoice::TYPE_SALE, $customer, 30, 30, $product, 3, now()->startOfMonth()->toDateTimeString());

    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());
    $user = User::factory()->create();

    expect($summary['net_sales'])->toBe(30.0);
    $this->actingAs($user)->get(route('financial-status.index'))->assertOk()->assertSee('الوضع المالي');
});

test('login always starts at message to you instead of the intended dashboard', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->withSession(['url.intended' => route('dashboard')])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('message-to-you'));
});

test('supplier settlements and genuine supplier cash returns are dated cash movements', function () {
    $product = financialProduct(5, 100);
    $supplier = Partner::query()->create(['name' => 'مورد تسوية', 'type' => Partner::TYPE_SUPPLIER]);
    financialInvoice(Invoice::TYPE_PURCHASE, $supplier, 500, 200, $product, 50);
    LedgerSettlement::query()->create([
        'partner_id' => $supplier->id, 'amount' => 300,
        'type' => LedgerSettlement::TYPE_OUTGOING, 'settlement_date' => now()->toDateString(),
    ]);

    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($summary['payables'])->toBe(0.0)
        ->and($summary['supplier_payments'])->toBe(300.0)
        ->and($summary['cash_remaining'])->toBe(-500.0);

    LedgerSettlement::query()->create([
        'partner_id' => $supplier->id, 'amount' => 20,
        'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString(),
    ]);
    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($summary['supplier_refunds'])->toBe(20.0)
        ->and($summary['cash_remaining'])->toBe(-480.0)
        ->and($summary['payables'])->toBe(0.0);
});

test('cash sales returned without a customer reduce cash without creating a partner settlement', function () {
    $product = financialProduct(5, 0);
    $sale = financialInvoice(Invoice::TYPE_SALE, null, 20, 20, $product, 2, now()->subDays(2)->toDateTimeString());
    $line = $sale->lines()->firstOrFail();

    app(ReturnService::class)->processSalesReturn($sale, 10, [$line->id => 1]);
    $summary = app(FinancialStatusService::class)->summary(now()->toDateString(), now()->toDateString());

    expect($summary['anonymous_refunds'])->toBe(10.0)
        ->and($summary['refunds'])->toBe(10.0)
        ->and($summary['cash_remaining'])->toBe(-10.0)
        ->and(LedgerSettlement::query()->count())->toBe(0);
});

test('customer cash and receivable stay split across the invoice and later settlements', function () {
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل تسويات متتابعة', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 100, 40, $product, 10);

    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['sales_collected'])->toBe(40.0)
        ->and($summary['cash_remaining'])->toBe(40.0)
        ->and($summary['receivables'])->toBe(60.0);

    LedgerSettlement::query()->create(['partner_id' => $customer->id, 'amount' => 30, 'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString()]);
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['sales_collected'])->toBe(70.0)
        ->and($summary['cash_remaining'])->toBe(70.0)
        ->and($summary['receivables'])->toBe(30.0);

    LedgerSettlement::query()->create(['partner_id' => $customer->id, 'amount' => 30, 'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString()]);
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['sales_collected'])->toBe(100.0)
        ->and($summary['cash_remaining'])->toBe(100.0)
        ->and($summary['receivables'])->toBe(0.0);
});

test('owner withdrawal is a dated cash outflow and never an operating expense', function () {
    $party = MoneyParty::query()->create(['name' => 'صاحب المشروع']);
    MoneyPartyMovement::query()->create([
        'money_party_id' => $party->id, 'direction' => MoneyPartyMovement::DIRECTION_TO_PARTY,
        'category' => MoneyPartyMovement::CATEGORY_OWNER_WITHDRAWAL, 'amount' => 50,
        'currency' => 'SYP', 'exchange_rate_snapshot' => 1, 'amount_base_currency' => 50,
        'date' => now()->toDateString(), 'status' => MoneyPartyMovement::STATUS_ACTIVE,
    ]);

    $summary = app(FinancialStatusService::class)->summary(now()->toDateString(), now()->toDateString());

    expect($summary['owner_withdrawals'])->toBe(50.0)
        ->and($summary['cash_remaining'])->toBe(-50.0)
        ->and($summary['expenses'])->toBe(0.0)
        ->and($summary['net_profit'])->toBe(0.0);
});

test('cash position uses the settlement date while receivables remain independent', function () {
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل دفعة مؤرخة', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 100, 0, $product, 10, now()->subDays(2)->toDateTimeString());
    LedgerSettlement::query()->create([
        'partner_id' => $customer->id, 'amount' => 30,
        'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString(),
    ]);

    $summary = app(FinancialStatusService::class)->summary(now()->toDateString(), now()->toDateString());

    expect($summary['net_sales'])->toBe(0.0)
        ->and($summary['sales_collected'])->toBe(30.0)
        ->and($summary['cash_remaining'])->toBe(30.0)
        ->and($summary['receivables'])->toBe(70.0);
});

test('payment register refuses a settlement larger than the current balance', function () {
    $user = User::factory()->create();
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل حماية التسوية', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 30, 0, $product, 3);

    Livewire::actingAs($user)->test(\App\Livewire\Payments\Register::class)
        ->call('openAddModal')
        ->set('settlementType', LedgerSettlement::TYPE_INCOMING)
        ->set('partnerId', $customer->id)
        ->set('amount', '30.01')
        ->set('settlementDate', now()->toDateString())
        ->call('addPayment')
        ->assertHasErrors(['amount']);

    expect(LedgerSettlement::query()->count())->toBe(0);
});

test('account statement carries the opening balance into a date-filtered closing balance', function () {
    foreach (glob(storage_path('app/private/exports/كشف-حساب-العميل-عميل-كشف-حساب-مؤرخ-*')) ?: [] as $staleExport) {
        @unlink($staleExport);
    }

    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل كشف حساب مؤرخ', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 100, 0, $product, 10, now()->subDays(2)->toDateTimeString());
    LedgerSettlement::query()->create([
        'partner_id' => $customer->id, 'amount' => 30,
        'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString(),
    ]);

    $path = app(\App\Services\ReportService::class)->statement($customer, now()->toDateString(), now()->toDateString());
    try {
        $sheet = IOFactory::load($path)->getActiveSheet();

        expect($sheet->getCell('C4')->getValue())->toBe('الرصيد الافتتاحي')
            ->and((float) $sheet->getCell('F4')->getValue())->toBe(100.0)
            ->and((float) $sheet->getCell('F5')->getValue())->toBe(70.0)
            ->and((float) $sheet->getCell('F6')->getValue())->toBe(70.0);
    } finally {
        @unlink($path);
    }
});
