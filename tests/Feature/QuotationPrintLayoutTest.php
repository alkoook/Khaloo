<?php

use App\Models\Partner;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\Setting;
use App\Models\User;

test('quotation print renders the A4 client-facing document with dynamic details', function () {
    $user = User::factory()->create();
    $partner = Partner::query()->create(['name' => 'عميل عرض السعر', 'phone' => '0991234567', 'type' => Partner::TYPE_CUSTOMER]);
    $quotation = Quotation::query()->create([
        'number' => 'Q-202609-00001', 'partner_id' => $partner->id,
        'quotation_date' => '2026-09-30', 'expires_at' => '2026-10-15',
        'subtotal' => 100, 'discount' => 10, 'total' => 90, 'notes' => 'ملاحظة اختبارية',
    ]);
    QuotationLine::query()->create(['quotation_id' => $quotation->id, 'product_name' => 'منتج باسم طويل للاختبار', 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]);
    Setting::query()->updateOrCreate(['key' => 'company_name'], ['value' => 'منشأة خلو', 'type' => 'string', 'group' => 'general']);
    Setting::query()->updateOrCreate(['key' => 'company_phone'], ['value' => '0932222222', 'type' => 'string', 'group' => 'general']);

    $this->actingAs($user)->get(route('quotations.print', $quotation))
        ->assertOk()
        ->assertSee('quotation-document', false)
        ->assertSee('quotation-masthead', false)
        ->assertSee('quotation-client-card', false)
        ->assertSee('quotation-totals-card', false)
        ->assertSee('Q-202609-00001')
        ->assertSee('منشأة خلو')
        ->assertSee('0991234567')
        ->assertSee('ملاحظة اختبارية');
});
