<?php

use App\Livewire\FinancialStatus;
use App\Livewire\Payments\Register;
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
use App\Services\FinancialService;
use App\Services\FinancialStatusService;
use App\Services\InvoiceCancellationService;
use App\Services\ReportService;
use App\Services\ReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    expect($summary['net_sales'])->toBe('100.00')
        ->and($summary['cogs'])->toBe('50.00')
        ->and($summary['net_profit'])->toBe('40.00')
        ->and($summary['direct_cash_purchases'])->toBe('100.00')
        ->and($summary['cash_remaining'])->toBe('-70.00');
});

test('sales returns reduce net sales cogs inventory and cash only when a refund is paid', function () {
    $product = financialProduct(5, 0);
    $customer = Partner::query()->create(['name' => 'عميل مرتجع مالي', 'type' => Partner::TYPE_CUSTOMER]);
    $sale = financialInvoice(Invoice::TYPE_SALE, $customer, 20, 20, $product, 2);
    $line = $sale->lines()->first();

    app(ReturnService::class)->processSalesReturn($sale, 10, [$line->id => 1], (string) Str::uuid());
    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($sale->fresh()->total)->toBe('20.00')
        ->and($product->fresh()->quantity)->toBe('1.000')
        ->and($summary['net_sales'])->toBe('10.00')
        ->and($summary['cogs'])->toBe('5.00')
        ->and($summary['cash_remaining'])->toBe('10.00');
});

test('receivables and payables remain visible independently from collected cash', function () {
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل ذمة', 'type' => Partner::TYPE_CUSTOMER]);
    $supplier = Partner::query()->create(['name' => 'مورد ذمة', 'type' => Partner::TYPE_SUPPLIER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 100, 30, $product, 10);
    financialInvoice(Invoice::TYPE_PURCHASE, $supplier, 80, 20, $product, 8);

    $summary = app(FinancialStatusService::class)->summary();

    expect($summary['receivables'])->toBe('70.00')
        ->and($summary['payables'])->toBe('60.00')
        ->and($summary['sales_collected'])->toBe('30.00');
});

test('financial status ignores funding metadata and uses the purchase payment once', function () {
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

    expect($summary['direct_cash_purchases'])->toBe('50.00')
        ->and($summary['cash_remaining'])->toBe('-50.00')
        ->and($summary)->not->toHaveKeys(['money_to_parties', 'money_at_parties']);
});

test('financial status excludes all money party movements including unclassified and cancelled records', function () {
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

    expect($summary['direct_cash_purchases'])->toBe('30.00')
        ->and($summary['cash_remaining'])->toBe('-30.00')
        ->and($summary)->not->toHaveKeys(['unclassified_count', 'unclassified_amount', 'money_at_parties']);
});

test('financial status respects the selected date range and renders for authenticated user', function () {
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل تاريخ', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 40, 40, $product, 4, now()->subMonth()->startOfMonth()->toDateTimeString());
    financialInvoice(Invoice::TYPE_SALE, $customer, 30, 30, $product, 3, now()->startOfMonth()->toDateTimeString());

    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());
    $user = User::factory()->create();

    expect($summary['net_sales'])->toBe('30.00');
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

    expect($summary['payables'])->toBe('0.00')
        ->and($summary['supplier_payments'])->toBe('300.00')
        ->and($summary['cash_remaining'])->toBe('-500.00');

    LedgerSettlement::query()->create([
        'partner_id' => $supplier->id, 'amount' => 20,
        'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString(),
    ]);
    $summary = app(FinancialStatusService::class)->summary(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

    expect($summary['supplier_refunds'])->toBe('20.00')
        ->and($summary['cash_remaining'])->toBe('-480.00')
        ->and($summary['payables'])->toBe('0.00');
});

test('cash sales returned without a customer reduce cash without creating a partner settlement', function () {
    $product = financialProduct(5, 0);
    $sale = financialInvoice(Invoice::TYPE_SALE, null, 20, 20, $product, 2, now()->subDays(2)->toDateTimeString());
    $line = $sale->lines()->firstOrFail();

    app(ReturnService::class)->processSalesReturn($sale, 10, [$line->id => 1]);
    $summary = app(FinancialStatusService::class)->summary(now()->toDateString(), now()->toDateString());

    expect($summary['anonymous_refunds'])->toBe('10.00')
        ->and($summary['refunds'])->toBe('10.00')
        ->and($summary['cash_remaining'])->toBe('-10.00')
        ->and(LedgerSettlement::query()->count())->toBe(0);
});

test('customer cash and receivable stay split across the invoice and later settlements', function () {
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل تسويات متتابعة', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 100, 40, $product, 10);

    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['sales_collected'])->toBe('40.00')
        ->and($summary['cash_remaining'])->toBe('40.00')
        ->and($summary['receivables'])->toBe('60.00');

    LedgerSettlement::query()->create(['partner_id' => $customer->id, 'amount' => 30, 'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString()]);
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['sales_collected'])->toBe('70.00')
        ->and($summary['cash_remaining'])->toBe('70.00')
        ->and($summary['receivables'])->toBe('30.00');

    LedgerSettlement::query()->create(['partner_id' => $customer->id, 'amount' => 30, 'type' => LedgerSettlement::TYPE_INCOMING, 'settlement_date' => now()->toDateString()]);
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['sales_collected'])->toBe('100.00')
        ->and($summary['cash_remaining'])->toBe('100.00')
        ->and($summary['receivables'])->toBe('0.00');
});

test('owner withdrawal is outside financial status cash and profit calculations', function () {
    $party = MoneyParty::query()->create(['name' => 'صاحب المشروع']);
    MoneyPartyMovement::query()->create([
        'money_party_id' => $party->id, 'direction' => MoneyPartyMovement::DIRECTION_TO_PARTY,
        'category' => MoneyPartyMovement::CATEGORY_OWNER_WITHDRAWAL, 'amount' => 50,
        'currency' => 'SYP', 'exchange_rate_snapshot' => 1, 'amount_base_currency' => 50,
        'date' => now()->toDateString(), 'status' => MoneyPartyMovement::STATUS_ACTIVE,
    ]);

    $summary = app(FinancialStatusService::class)->summary(now()->toDateString(), now()->toDateString());

    expect($summary)->not->toHaveKey('owner_withdrawals')
        ->and($summary['cash_remaining'])->toBe('0.00')
        ->and($summary['expenses'])->toBe('0.00')
        ->and($summary['net_profit'])->toBe('0.00');
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

    expect($summary['net_sales'])->toBe('0.00')
        ->and($summary['sales_collected'])->toBe('30.00')
        ->and($summary['cash_remaining'])->toBe('30.00')
        ->and($summary['receivables'])->toBe('70.00');
});

test('payment register preserves an overpayment as customer credit', function () {
    $user = User::factory()->create();
    $product = financialProduct();
    $customer = Partner::query()->create(['name' => 'عميل حماية التسوية', 'type' => Partner::TYPE_CUSTOMER]);
    financialInvoice(Invoice::TYPE_SALE, $customer, 30, 0, $product, 3);

    Livewire::actingAs($user)->test(Register::class)
        ->call('openAddModal')
        ->set('settlementType', LedgerSettlement::TYPE_INCOMING)
        ->set('partnerId', $customer->id)
        ->set('amount', '30.01')
        ->set('settlementDate', now()->toDateString())
        ->call('addPayment')
        ->assertHasNoErrors();

    $row = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($customer->id);
    expect(LedgerSettlement::query()->count())->toBe(1)
        ->and(app(FinancialService::class)->signedBalance($row))->toBe('-0.01');
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

    $path = app(ReportService::class)->statement($customer, now()->toDateString(), now()->toDateString());
    try {
        $sheet = IOFactory::load($path)->getActiveSheet();

        expect($sheet->getCell('D4')->getValue())->toBe('الرصيد الافتتاحي')
            ->and((float) $sheet->getCell('G4')->getValue())->toBe(100.0)
            ->and((float) $sheet->getCell('G5')->getValue())->toBe(70.0)
            ->and((float) $sheet->getCell('G6')->getValue())->toBe(70.0);
    } finally {
        @unlink($path);
    }
});

test('financial status reconciles merchandise delivery advances partial and full collections without double counting', function () {
    $product = financialProduct(4000, 20);
    $customer = Partner::create(['name' => 'عميل مبالغ عابرة', 'type' => 'customer']);
    $supplier = Partner::create(['name' => 'مورد مبالغ عابرة', 'type' => 'supplier']);
    $sale = financialInvoice('sale', $customer, 100000, 50000, $product, 10);
    $sale->update(['delivery_amount' => '5000.00', 'additional_total' => '10000.00', 'payable_total' => '115000.00']);
    foreach ([['غرض أول', 1, 4000, 4000], ['غرض ثان', 2, 3000, 6000]] as [$description, $quantity, $unit, $total]) {
        $sale->additionalLines()->create(['description' => $description, 'quantity' => $quantity, 'unit_amount' => $unit, 'row_total' => $total, 'source_note' => 'طلب خاص']);
    }
    financialInvoice('purchase', $supplier, 80000, 20000, $product, 20);
    Expense::create(['amount' => 2000, 'reason' => 'تشغيل', 'date' => today()]);
    app(FinancialService::class)->recordSettlement($customer->id, 'receivable', '30000.00', today()->toDateString());
    app(FinancialService::class)->recordSettlement($supplier->id, 'payable', '10000.00', today()->toDateString());

    $summary = app(FinancialStatusService::class)->summary();
    foreach ([
        'net_sales' => '100000.00', 'delivery_income' => '5000.00', 'cogs' => '40000.00',
        'gross_profit' => '65000.00', 'net_profit' => '63000.00', 'expenses' => '2000.00',
        'purchases' => '80000.00', 'purchase_payments' => '30000.00', 'purchase_credit_created' => '60000.00',
        'cash_inflows' => '80000.00', 'cash_outflows' => '42000.00', 'cash_remaining' => '38000.00',
        'customer_invoiced' => '115000.00', 'customer_collected' => '80000.00', 'receivables' => '35000.00',
        'supplier_invoiced' => '80000.00', 'supplier_paid' => '30000.00', 'payables' => '50000.00',
        'pass_through_advances' => '10000.00', 'inventory_value' => '80000.00',
    ] as $key => $value) {
        expect($summary[$key], $key)->toBe($value);
    }
    app(FinancialService::class)->recordSettlement($customer->id, 'receivable', '35000.00', today()->toDateString());
    $settled = app(FinancialStatusService::class)->summary();
    expect($settled['customer_collected'])->toBe('115000.00')
        ->and($settled['receivables'])->toBe('0.00')->and($settled['cash_inflows'])->toBe('115000.00')
        ->and($settled['cash_remaining'])->toBe('73000.00') // 100000 merchandise + 5000 delivery - 30000 purchases - 2000 expenses.
        ->and($settled['gross_profit'])->toBe('65000.00')->and($settled['inventory_value'])->toBe('80000.00')
        ->and($product->fresh()->quantity)->toBe('20.000')->and($sale->fresh()->amount_paid)->toBe('50000.00');
});

test('financial status shows real payment dates and keeps all current balances independent of the period', function () {
    $this->travelTo(now()->startOfDay()->subDays(2));
    $product = financialProduct(5, 10);
    $customer = Partner::create(['name' => 'عميل فصل الفترات', 'type' => 'customer']);
    $supplier = Partner::create(['name' => 'مورد فصل الفترات', 'type' => 'supplier']);
    financialInvoice('sale', $customer, 100, 10, $product, 10);
    financialInvoice('purchase', $supplier, 100, 20, $product, 10);
    $issued = today()->toDateString();
    $this->travel(2)->days();
    app(FinancialService::class)->recordSettlement($customer->id, 'receivable', '30.00', today()->toDateString());
    app(FinancialService::class)->recordSettlement($supplier->id, 'payable', '50.00', today()->toDateString());
    Expense::create(['amount' => 5, 'reason' => 'مصروف اليوم', 'date' => today()]);
    // Future-dated payments must not leak into today's position.
    LedgerSettlement::create(['partner_id' => $customer->id, 'amount' => 10, 'type' => 'incoming', 'settlement_date' => today()->addDay()]);
    $service = app(FinancialStatusService::class);
    $old = $service->summary($issued, $issued);
    $today = $service->summary(today()->toDateString(), today()->toDateString());
    expect($old['cash_remaining'])->toBe('-10.00')->and($old['purchases'])->toBe('100.00')
        ->and($old['purchase_credit_created'])->toBe('80.00')->and($old['purchase_payments'])->toBe('20.00')
        ->and($today['cash_inflows'])->toBe('30.00')->and($today['cash_outflows'])->toBe('55.00')
        ->and($today['cash_remaining'])->toBe('-25.00')->and($today['net_sales'])->toBe('0.00')
        ->and($today['purchases'])->toBe('0.00')->and($today['purchase_payments'])->toBe('50.00');
    foreach (['receivables' => '60.00', 'payables' => '30.00', 'current_cash_position' => '-35.00',
        'customer_invoiced' => '100.00', 'customer_collected' => '40.00', 'supplier_invoiced' => '100.00',
        'supplier_paid' => '70.00', 'inventory_value' => '50.00'] as $key => $value) {
        expect($old[$key], $key)->toBe($value)->and($today[$key], $key)->toBe($value);
    }
    $this->travelBack();
});

test('financial status reverses returns on the return date using historical costs and refunds only once', function () {
    $this->travelTo(now()->startOfDay()->subDays(3));
    $product = financialProduct(5, 0);
    $customer = Partner::create(['name' => 'عميل مرتجع مؤرخ', 'type' => 'customer']);
    $sale = financialInvoice('sale', $customer, 100, 100, $product, 10);
    $issued = today()->toDateString();
    $this->travel(3)->days();
    $product->update(['purchase_price' => 9]); // Must not rewrite the sale's historical COGS.
    app(ReturnService::class)->processSalesReturn($sale, '20.00', [$sale->lines()->sole()->id => 2]);
    $service = app(FinancialStatusService::class);
    $old = $service->summary($issued, $issued);
    $returned = $service->summary(today()->toDateString(), today()->toDateString());
    expect($old['net_sales'])->toBe('100.00')->and($old['cogs'])->toBe('50.00')
        ->and($old['cash_inflows'])->toBe('100.00')->and($old['return_count'])->toBe(0)
        ->and($returned['net_sales'])->toBe('-20.00')->and($returned['cogs'])->toBe('-10.00')
        ->and($returned['gross_profit'])->toBe('-10.00')->and($returned['cash_outflows'])->toBe('20.00')
        ->and($returned['return_count'])->toBe(1)->and($returned['returns_total'])->toBe('20.00')
        ->and($returned['customer_invoiced'])->toBe('80.00')->and($returned['customer_collected'])->toBe('80.00')
        ->and($returned['receivables'])->toBe('0.00')->and($returned['current_cash_position'])->toBe('80.00')
        ->and($service->summary()['net_sales'])->toBe('80.00')->and($service->summary()['cogs'])->toBe('40.00')
        ->and(LedgerSettlement::count())->toBe(1);
    $this->travelBack();
});

test('financial status excludes cancelled invoices charges payments and debt without changing cancellation behavior', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $customer = Partner::create(['name' => 'عميل إلغاء مالي', 'type' => 'customer']);
    $supplier = Partner::create(['name' => 'مورد إلغاء مالي', 'type' => 'supplier']);
    $sale = financialInvoice('sale', $customer, 100, 40, financialProduct(5, 0), 10);
    $sale->update(['delivery_amount' => 5, 'additional_total' => 10, 'payable_total' => 115]);
    $purchase = financialInvoice('purchase', $supplier, 80, 20, financialProduct(8, 10), 10);
    app(InvoiceCancellationService::class)->cancel($sale, $user->id, 'إلغاء لاختبار الوضع المالي');
    app(InvoiceCancellationService::class)->cancel($purchase, $user->id, 'إلغاء شراء لاختبار الوضع المالي');
    $summary = app(FinancialStatusService::class)->summary();
    foreach (['net_sales', 'delivery_income', 'cogs', 'gross_profit', 'purchases', 'cash_inflows', 'cash_outflows',
        'pass_through_advances', 'customer_invoiced', 'customer_collected', 'supplier_invoiced', 'supplier_paid', 'receivables', 'payables'] as $key) {
        expect($summary[$key], $key)->toBe('0.00');
    }
    expect($summary['sale_count'])->toBe(0)->and($summary['purchase_count'])->toBe(0)->and(Invoice::count())->toBe(2);
});

test('financial status reports unknown cost honestly and keeps historical cogs distinct from current inventory', function () {
    $product = financialProduct(5, 10);
    financialInvoice('sale', null, 100, 100, $product, 10);
    $product->update(['purchase_price' => '9.0000', 'quantity' => '1.250']);
    $zero = financialProduct(0, 0);
    $zero->update(['purchase_price' => null]);
    $service = app(FinancialStatusService::class);
    $known = $service->summary();
    expect($known['cogs'])->toBe('50.00')->and($known['gross_profit'])->toBe('50.00')
        ->and($known['inventory_value'])->toBe('11.25')->and($known['out_of_stock_count'])->toBe(1)
        ->and($known['product_count'])->toBe(2)->and($known['unknown_inventory_count'])->toBe(0);
    $product->update(['purchase_price' => null]);
    $unknownInventory = $service->summary();
    expect($unknownInventory['inventory_value'])->toBeNull()->and($unknownInventory['unknown_inventory_count'])->toBe(1)
        ->and($unknownInventory['cogs'])->toBe('50.00');
    InvoiceLine::query()->update(['purchase_price' => null]);
    $unknownSale = $service->summary();
    expect($unknownSale['cogs'])->toBeNull()->and($unknownSale['gross_profit'])->toBeNull()
        ->and($unknownSale['net_profit'])->toBeNull()->and($unknownSale['unknown_cost_lines'])->toBe(1);
});

test('financial status keeps customer credits separate and matches the existing supplier credit convention', function () {
    $product = financialProduct(5, 0);
    $customer = Partner::create(['name' => 'عميل له رصيد', 'type' => 'customer']);
    $debtor = Partner::create(['name' => 'عميل مدين', 'type' => 'customer']);
    $supplier = Partner::create(['name' => 'مورد تسوية دائنة', 'type' => 'supplier']);
    $prepaidSupplier = Partner::create(['name' => 'مورد له دفعة زائدة قديمة', 'type' => 'supplier']);
    $sale = financialInvoice('sale', $customer, 100, 100, $product, 10);
    app(ReturnService::class)->processSalesReturn($sale, 0, [$sale->lines()->sole()->id => 2]);
    financialInvoice('sale', $debtor, 70, 0, $product, 7);
    financialInvoice('purchase', $supplier, 150, 100, $product, 10);
    financialInvoice('purchase', $prepaidSupplier, 100, 100, $product, 10);
    LedgerSettlement::create(['partner_id' => $supplier->id, 'amount' => 20, 'type' => 'incoming', 'settlement_date' => today()]);
    LedgerSettlement::create(['partner_id' => $prepaidSupplier->id, 'amount' => 50, 'type' => 'outgoing', 'settlement_date' => today()]);
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['customer_invoiced'])->toBe('150.00')->and($summary['customer_collected'])->toBe('100.00')
        ->and($summary['receivables'])->toBe('70.00')->and($summary['customer_credits'])->toBe('20.00')
        ->and($summary['supplier_invoiced'])->toBe('250.00')->and($summary['supplier_paid'])->toBe('250.00')
        ->and($summary['supplier_adjustments'])->toBe('20.00')->and($summary['payables'])->toBe('30.00')
        ->and($summary['supplier_credits'])->toBe('50.00');
});

test('financial status uses the existing discounted merchandise amount without altering discount calculations', function () {
    $product = financialProduct(5, 10);
    $sale = financialInvoice('sale', null, 90, 90, $product, 10);
    $sale->update(['subtotal' => 100, 'discount_type' => 'fixed', 'discount_amount' => 10, 'discount_value' => 10]);
    $sale->lines()->update(['line_total' => 100, 'net_line_total' => 90, 'discount_amount' => 10]);
    $summary = app(FinancialStatusService::class)->summary();
    expect($summary['net_sales'])->toBe('90.00')->and($summary['cogs'])->toBe('50.00')
        ->and($summary['gross_profit'])->toBe('40.00')->and($summary['cash_inflows'])->toBe('90.00');
});

test('financial status neither loads nor displays money parties and does not show empty operational sections', function () {
    $user = User::factory()->create();
    $party = MoneyParty::create(['name' => 'جهة يجب عدم تحميلها']);
    foreach (['purchase_advance', 'purchase_compensation', 'owner_withdrawal', 'returned_from_party', 'other', 'unclassified'] as $category) {
        foreach (['to_party', 'from_party'] as $direction) {
            MoneyPartyMovement::create(['money_party_id' => $party->id, 'direction' => $direction, 'category' => $category,
                'amount' => 100, 'currency' => 'SYP', 'exchange_rate_snapshot' => 1, 'amount_base_currency' => 100,
                'date' => today(), 'status' => 'active']);
        }
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $summary = app(FinancialStatusService::class)->summary();
        Livewire::actingAs($user)->test(FinancialStatus::class)
            ->assertSee('نشاط الفترة المحددة')->assertSee('الأرصدة الحالية')->assertSee('الرصيد النقدي المحسوب')
            ->assertDontSee('أموال إلى الجهات')->assertDontSee('أموال عائدة من الجهات')->assertDontSee('سحوبات المالك')
            ->assertDontSee('أرصدة جهات المال الحالية')->assertDontSee('أحدث حركات جهات المال')
            ->assertDontSee('تسجيل حركة جهة مال')->assertDontSee('لا توجد جهات مال مسجلة.')
            ->assertDontSee('لا توجد حركات ضمن الفترة.')->assertDontSee('الشراء الممول من جهة مال')
            ->assertDontSee('<h3>المبالغ العابرة</h3>', false)->assertDontSee('تسويات دائنة أخرى للموردين');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        expect($queries)->not->toContain('money_parties', 'money_party_movements', 'purchase_funding_sources');
        expect($summary['cash_remaining'])->toBe('0.00')->and($summary['current_cash_position'])->toBe('0.00')
            ->and($summary['net_profit'])->toBe('0.00')
            ->and($summary)->not->toHaveKeys(['money_to_parties', 'money_from_parties', 'owner_withdrawals', 'money_party_rows', 'net_financial_position']);
        expect(method_exists(FinancialStatus::class, 'saveMovement'))->toBeFalse();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('financial status renders period purchases and pass through data separately from current balances', function () {
    $product = financialProduct();
    $customer = Partner::create(['name' => 'عميل عرض', 'type' => 'customer']);
    $supplier = Partner::create(['name' => 'مورد عرض', 'type' => 'supplier']);
    $sale = financialInvoice('sale', $customer, 100, 10, $product, 10);
    $sale->update(['delivery_amount' => 5, 'additional_total' => 10, 'payable_total' => 115]);
    financialInvoice('purchase', $supplier, 100, 20, $product, 10);
    Livewire::actingAs(User::factory()->create())->test(FinancialStatus::class)
        ->assertSee('المبالغ العابرة')->assertSee('المشتريات')->assertSee('المتبقي عند إنشاء فواتير الفترة')
        ->assertSee('الربحية')->assertSee('السيولة والحركة النقدية')->assertSee('ذمم السادة')->assertSee('ذمم الموردين')
        ->assertSee('المخزون')->assertSee('النشاط خلال الفترة')->assertSee('105.00')->assertSee('80.00');
});

test('financial status handles invalid and reversed custom dates without reporting misleading totals', function () {
    $component = Livewire::actingAs(User::factory()->create())->test(FinancialStatus::class)
        ->set('period', 'custom')->set('from', 'not-a-date')
        ->assertSee('أدخل تاريخاً صحيحاً')->assertDontSee('الرصيد النقدي المحسوب');
    $component->set('from', '2026-10-10')->set('to', '2026-10-09')
        ->assertSee('تاريخ النهاية يجب ألا يسبق تاريخ البداية.')->assertDontSee('الرصيد النقدي المحسوب')
        ->set('to', '2026-10-11')->assertSee('الرصيد النقدي المحسوب')
        ->set('period', 'today')->assertSet('from', today()->toDateString())->assertSet('to', today()->toDateString());
});
