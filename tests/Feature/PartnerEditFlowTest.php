<?php

use App\Livewire\Partners\Create as CreatePartner;
use App\Livewire\Partners\Edit as EditPartner;
use App\Models\Partner;
use App\Models\User;
use Livewire\Livewire;

test('supplier and customer partner forms update once and redirect through Livewire', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $supplier = Partner::query()->create(['name' => 'مورد', 'type' => Partner::TYPE_SUPPLIER]);
    $customer = Partner::query()->create(['name' => 'عميل', 'type' => Partner::TYPE_CUSTOMER]);

    $this->actingAs($user)->get(route('partners.edit', $supplier))
        ->assertOk()
        ->assertSeeLivewire(EditPartner::class);

    Livewire::actingAs($user)->test(EditPartner::class, ['partner' => $supplier])
        ->set('name', 'مورد محدّث')
        ->set('phone', '0999999999')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('partners.index'));

    expect($supplier->fresh()->name)->toBe('مورد محدّث')
        ->and($supplier->fresh()->phone)->toBe('0999999999');

    Livewire::actingAs($user)->test(EditPartner::class, ['partner' => $customer])
        ->set('name', 'عميل محدّث')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('partners.index'));

    expect($customer->fresh()->name)->toBe('عميل محدّث');
});

test('supplier creation redirects through Livewire without a native submit', function () {
    $user = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)->test(CreatePartner::class)
        ->set('name', 'مورد جديد')
        ->set('type', Partner::TYPE_SUPPLIER)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('partners.index'));

    expect(Partner::query()->where('name', 'مورد جديد')->where('type', Partner::TYPE_SUPPLIER)->exists())->toBeTrue();
});
