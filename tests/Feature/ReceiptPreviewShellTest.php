<?php

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;

test('receipt preview shell stays outside the unchanged capture target', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $invoice = Invoice::query()->create([
        'type' => Invoice::TYPE_SALE,
        'subtotal' => 10,
        'total' => 10,
        'initial_paid' => 10,
        'amount_paid' => 10,
    ]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id,
        'product_name' => 'صنف اختبار المعاينة',
        'quantity' => 1,
        'unit_price' => 10,
        'line_total' => 10,
        'net_line_total' => 10,
    ]);

    $response = $this->actingAs($user)->get(route('invoices.receipt', $invoice));

    $response->assertOk()
        ->assertSee('receipt-preview__ambient', false)
        ->assertSee('receipt-preview__stage', false)
        ->assertSee('class="receipt receipt--', false)
        ->assertSee('receipt-screen-actions__print', false)
        ->assertSee('receipt-screen-actions__screenshot', false)
        ->assertSee('receipt-screen-actions__close', false);

    $screenshotScript = file_get_contents(resource_path('js/receipt.js'));
    $previewStyles = file_get_contents(resource_path('css/receipt-preview.css'));

    expect($screenshotScript)->toContain("document.querySelector('.receipt')")
        ->and($screenshotScript)->toContain('downloadElementAsPng(receipt')
        ->and($previewStyles)->toContain('@media print')
        ->and($previewStyles)->toContain('.receipt-preview__ambient { display: none !important; }')
        ->and($previewStyles)->toContain('.receipt-preview__stage { display: contents !important; }')
        ->and($previewStyles)->toContain('@media (prefers-reduced-motion: reduce)');
});
