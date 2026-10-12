<?php

use App\Livewire\Settings\Index;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\WindowsReceiptCutter;

test('receipt exposes one post print raw cut endpoint without changing its capture target', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $invoice = Invoice::query()->create(['type' => Invoice::TYPE_SALE, 'subtotal' => 10, 'total' => 10, 'initial_paid' => 10, 'amount_paid' => 10]);
    InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'product_name' => 'اختبار القص', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10, 'net_line_total' => 10]);

    $this->actingAs($user)->get(route('invoices.receipt', $invoice))->assertOk()
        ->assertSee('data-auto-cut="1"', false)
        ->assertSee(route('invoices.receipt.cut', $invoice), false)
        ->assertSee('window.requestReceiptCut?.(completedPrintToken)', false);

    $script = file_get_contents(base_path('scripts/send-receipt-cut.ps1'));
    $browserScript = file_get_contents(resource_path('js/receipt.js'));
    expect($script)->toContain('0x1B, 0x64')->toContain('0x1D, 0x56')
        ->and($script)->toContain('pDataType = "RAW"')
        ->and($browserScript)->toContain("document.querySelector('.receipt')")
        ->and($browserScript)->toContain('sentCutTokens.has(printToken)');
});

test('server accepts each print cut token only once', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $invoice = Invoice::query()->create(['type' => Invoice::TYPE_SALE, 'subtotal' => 10, 'total' => 10, 'initial_paid' => 10, 'amount_paid' => 10]);
    $token = '123e4567-e89b-42d3-a456-426614174000';
    $cutter = Mockery::mock(WindowsReceiptCutter::class);
    $cutter->shouldReceive('cut')->once()->andReturn(['enabled' => true, 'printer' => 'POS-80']);
    app()->instance(WindowsReceiptCutter::class, $cutter);

    $this->actingAs($user)->postJson(route('invoices.receipt.cut', $invoice), ['print_token' => $token])
        ->assertOk()->assertJson(['status' => 'cut', 'printer' => 'POS-80']);
    $this->actingAs($user)->postJson(route('invoices.receipt.cut', $invoice), ['print_token' => $token])
        ->assertOk()->assertJson(['status' => 'duplicate']);
});

test('printer cut settings persist with validated feed and cut mode', function () {
    $user = User::factory()->create(['is_admin' => true]);

    Livewire\Livewire::actingAs($user)->test(Index::class)
        ->set('receipt_auto_cut', true)
        ->set('receipt_printer_name', 'POS-80')
        ->set('receipt_cut_mode', 'partial')
        ->set('receipt_cut_feed_lines', 3)
        ->call('savePrinting')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    expect($settings->get('receipt_auto_cut'))->toBeTrue()
        ->and($settings->get('receipt_printer_name'))->toBe('POS-80')
        ->and($settings->get('receipt_cut_mode'))->toBe('partial')
        ->and($settings->get('receipt_cut_feed_lines'))->toBe(3);
});
