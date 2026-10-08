<?php

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Livewire;

function phoneSelectionInvoice(): Invoice
{
    $invoice = Invoice::query()->create([
        'type' => Invoice::TYPE_SALE,
        'subtotal' => 10,
        'total' => 10,
        'initial_paid' => 5,
        'amount_paid' => 5,
    ]);
    InvoiceLine::query()->create([
        'invoice_id' => $invoice->id,
        'product_name' => 'منتج اختبار طويل وواضح للطباعة',
        'quantity' => 1,
        'unit_price' => 10,
        'line_total' => 10,
        'net_line_total' => 10,
    ]);

    return $invoice;
}

test('settings persist both company phones and every print selection', function () {
    $user = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)->test(SettingsIndex::class)
        ->set('company_phone', '0911111111')
        ->set('company_phone_secondary', '0922222222')
        ->call('saveGeneral')
        ->assertHasNoErrors();

    foreach (['primary', 'secondary', 'both'] as $selection) {
        Livewire::actingAs($user)->test(SettingsIndex::class)
            ->set('invoice_phone_display', $selection)
            ->call('savePrinting')
            ->assertHasNoErrors();
        expect(Setting::query()->where('key', 'invoice_phone_display')->value('value'))->toBe($selection);
    }

    expect(Setting::query()->where('key', 'company_phone')->value('value'))->toBe('0911111111')
        ->and(Setting::query()->where('key', 'company_phone_secondary')->value('value'))->toBe('0922222222');
});

test('browser and thermal invoices obey phone selection without empty separators', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $invoice = phoneSelectionInvoice();
    $settings = app(SettingsService::class);
    $settings->set('company_phone', '0911111111');
    $settings->set('company_phone_secondary', '0922222222');

    foreach (['primary' => '0911111111', 'secondary' => '0922222222', 'both' => '0911111111 / 0922222222'] as $selection => $expected) {
        $settings->set('invoice_phone_display', $selection, 'printing');
        $this->actingAs($user)->get(route('invoices.sales.show', $invoice))->assertOk()->assertSee($expected);
        $receipt = $this->actingAs($user)->get(route('invoices.receipt', $invoice))->assertOk();
        if ($selection === 'both') {
            $receipt->assertSee('0911111111')->assertSee('0922222222')->assertDontSee('0911111111 / 0922222222');
        } else {
            $receipt->assertSee($expected);
        }
    }

    $settings->set('company_phone_secondary', '');
    $settings->set('invoice_phone_display', 'both', 'printing');
    $this->actingAs($user)->get(route('invoices.receipt', $invoice))->assertOk()->assertSee('0911111111')->assertDontSee('0911111111 /');
});

test('invoice rows are numbered in browser and thermal documents', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $invoice = phoneSelectionInvoice();

    $this->actingAs($user)->get(route('invoices.sales.show', $invoice))->assertOk()->assertSee('class="seq">1', false);
    $this->actingAs($user)->get(route('invoices.receipt', $invoice))->assertOk()->assertSee('class="receipt-sequence">1', false);
});

test('invoice documents render dense item and payment scenarios', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $scenarios = [
        [Invoice::TYPE_SALE, 1, 100, 100],
        [Invoice::TYPE_PURCHASE, 10, 1000, 400],
        [Invoice::TYPE_RETURN, 35, 3500, 0],
    ];

    foreach ($scenarios as [$type, $lineCount, $subtotal, $paid]) {
        $invoice = Invoice::query()->create([
            'type' => $type,
            'subtotal' => $subtotal,
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'discount_amount' => 10,
            'total' => $subtotal - 10,
            'initial_paid' => $paid,
            'amount_paid' => $paid,
        ]);
        foreach (range(1, $lineCount) as $number) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'product_name' => "اسم مادة عربي طويل لاختبار التفاف السطر رقم {$number}",
                'quantity' => 1,
                'unit_price' => 100,
                'line_total' => 100,
                'discount_amount' => $number === 1 ? 10 : 0,
                'net_line_total' => $number === 1 ? 90 : 100,
            ]);
        }

        $receipt = $this->actingAs($user)->get(route('invoices.receipt', $invoice));
        $receipt->assertOk()->assertSee('receipt-sequence', false)->assertSee((string) $lineCount);
        $showRoute = $type === Invoice::TYPE_PURCHASE ? 'invoices.purchases.show' : 'invoices.sales.show';
        $this->actingAs($user)->get(route($showRoute, $invoice))->assertOk()->assertSee('invoice-sheet', false)->assertSee("السطر رقم {$lineCount}");
    }
});
