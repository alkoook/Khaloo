<?php

use App\Livewire\Ledger\Index as LedgerIndex;
use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\User;
use App\Services\FinancialService;
use Livewire\Livewire;

test('ledger payable tab records a partial supplier payment and refreshes its balance', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $supplier = Partner::query()->create(['name' => 'مورد دفعة جزئية', 'type' => Partner::TYPE_SUPPLIER]);
    Invoice::query()->create([
        'partner_id' => $supplier->id,
        'type' => Invoice::TYPE_PURCHASE,
        'subtotal' => '100.00',
        'total' => '100.00',
        'initial_paid' => '0.00',
        'amount_paid' => '0.00',
    ]);

    Livewire::actingAs($user)->test(LedgerIndex::class)
        ->call('setTabPayable')
        ->assertSee('إضافة دفعة')
        ->call('openAddPaymentModal')
        ->assertSet('showAddPaymentModal', true)
        ->set('paymentPartnerId', $supplier->id)
        ->set('paymentAmount', '40.00')
        ->set('paymentDate', '2026-11-16')
        ->set('paymentNotes', 'دفعة جزئية للمورد')
        ->call('addPayment')
        ->assertHasNoErrors()
        ->assertSet('showAddPaymentModal', false);

    $settlement = LedgerSettlement::query()->sole();
    expect($settlement->partner_id)->toBe($supplier->id)
        ->and($settlement->type)->toBe(LedgerSettlement::TYPE_OUTGOING)
        ->and($settlement->amount)->toBe('40.00')
        ->and($settlement->settlement_date->toDateString())->toBe('2026-11-16')
        ->and($settlement->notes)->toBe('دفعة جزئية للمورد');

    $supplierBalance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($supplier->id);
    $statement = app(FinancialService::class)->statementData($supplier);

    expect(app(FinancialService::class)->payable($supplierBalance))->toBe('60.00')
        ->and($statement['closing'])->toBe('60.00')
        ->and($statement['rows']->where('settlement_id', $settlement->id)->count())->toBe(1);
});

test('ledger payable tab keeps full supplier settlement available', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $supplier = Partner::query()->create(['name' => 'مورد تسوية كاملة', 'type' => Partner::TYPE_SUPPLIER]);
    Invoice::query()->create([
        'partner_id' => $supplier->id,
        'type' => Invoice::TYPE_PURCHASE,
        'subtotal' => '100.00',
        'total' => '100.00',
        'initial_paid' => '0.00',
        'amount_paid' => '0.00',
    ]);

    Livewire::actingAs($user)->test(LedgerIndex::class)
        ->call('setTabPayable')
        ->call('settle', $supplier->id, 'payable')
        ->assertHasNoErrors();

    $settlement = LedgerSettlement::query()->sole();
    $supplierBalance = app(FinancialService::class)->partnerBalancesQuery()->findOrFail($supplier->id);
    $statement = app(FinancialService::class)->statementData($supplier);

    expect($settlement->type)->toBe(LedgerSettlement::TYPE_OUTGOING)
        ->and($settlement->amount)->toBe('100.00')
        ->and(app(FinancialService::class)->payable($supplierBalance))->toBe('0.00')
        ->and($statement['closing'])->toBe('0.00');
});
