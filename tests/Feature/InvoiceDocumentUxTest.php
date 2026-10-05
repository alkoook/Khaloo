<?php

use App\Livewire\Payments\Register;
use App\Livewire\Ledger\Index as LedgerIndex;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

function documentInvoice(string $type, ?Invoice $original = null): Invoice
{
    $category = Category::query()->create(['name' => 'تصنيف مستندات']);
    $partner = Partner::query()->create([
        'name' => $type === Invoice::TYPE_PURCHASE ? 'مورد المستند' : 'عميل المستند',
        'type' => $type === Invoice::TYPE_PURCHASE ? Partner::TYPE_SUPPLIER : Partner::TYPE_CUSTOMER,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'منتج المستند',
        'quantity' => 10,
        'purchase_price' => 5,
        'sale_price' => 10,
    ]);
    $invoice = Invoice::query()->create([
        'partner_id' => $partner->id,
        'original_invoice_id' => $original?->id,
        'type' => $type,
        'subtotal' => 20,
        'discount_amount' => 2,
        'total' => 18,
        'initial_paid' => $type === Invoice::TYPE_RETURN ? 8 : 10,
        'amount_paid' => $type === Invoice::TYPE_RETURN ? 8 : 10,
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
        'discount_amount' => 2,
        'net_line_total' => 18,
    ]);

    return $invoice;
}

test('sales purchases and returns expose invoice-only screenshot controls', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $sale = documentInvoice(Invoice::TYPE_SALE);
    $purchase = documentInvoice(Invoice::TYPE_PURCHASE);
    $return = documentInvoice(Invoice::TYPE_RETURN, $sale);

    foreach ([$sale, $purchase, $return] as $invoice) {
        $response = $this->actingAs($user)->get(route('invoices.receipt', $invoice));

        $response->assertOk()
            ->assertSee('لقطة شاشة')
            ->assertSee('captureReceipt(this)', false)
            ->assertSee(($invoice->isReturn() ? 'return' : 'invoice').'-'.$invoice->id.'.png', false)
            ->assertSee('receipt-end-feed', false);
    }
});

test('return receipt shows its business identity original invoice items and stored totals', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $sale = documentInvoice(Invoice::TYPE_SALE);
    $return = documentInvoice(Invoice::TYPE_RETURN, $sale);

    $this->actingAs($user)->get(route('invoices.receipt', $return))
        ->assertOk()
        ->assertSee('فاتورة مرتجع مبيعات')
        ->assertSee('أصل الفاتورة #'.$sale->id)
        ->assertSee('منتج المستند')
        ->assertSee('18.00')
        ->assertSee('المسترد نقدًا')
        ->assertSee('8.00')
        ->assertSee('طباعة المرتجع');
});

test('cancelled return remains printable with a visible audit warning', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $sale = documentInvoice(Invoice::TYPE_SALE);
    $return = documentInvoice(Invoice::TYPE_RETURN, $sale);
    $return->update([
        'status' => Invoice::STATUS_CANCELLED,
        'cancelled_at' => now(),
        'cancelled_by' => $user->id,
        'cancellation_reason' => 'إلغاء اختباري',
    ]);

    $this->actingAs($user)->get(route('invoices.receipt', $return))
        ->assertOk()
        ->assertSee('فاتورة ملغاة');
});

test('both payment modals have a constrained scrollable body and reachable actions', function () {
    $user = User::factory()->create(['is_admin' => true]);

    foreach ([
        [Register::class, 'openAddModal', 'showAddModal'],
        [LedgerIndex::class, 'openAddPaymentModal', 'showAddPaymentModal'],
    ] as [$component, $openMethod, $visibilityProperty]) {
        Livewire::actingAs($user)->test($component)
            ->call($openMethod)
            ->assertSet($visibilityProperty, true)
            ->assertSeeHtml('payment-modal-backdrop')
            ->assertSeeHtml('payment-modal-panel')
            ->assertSeeHtml('payment-modal-header')
            ->assertSeeHtml('payment-modal-form')
            ->assertSeeHtml('payment-modal-body')
            ->assertSeeHtml('payment-modal-actions');
    }
});
