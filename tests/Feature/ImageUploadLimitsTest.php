<?php

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('branding accepts a 10MB image and preserves image-only validation', function () {
    Storage::fake('public');
    $user = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)->test(SettingsIndex::class)
        ->set('logo', UploadedFile::fake()->image('logo.png')->size(10240))
        ->call('saveBranding')
        ->assertHasNoErrors();

    $path = Setting::query()->where('key', 'logo')->value('value');
    expect($path)->toStartWith('branding/');
    Storage::disk('public')->assertExists($path);

    Livewire::actingAs($user)->test(SettingsIndex::class)
        ->set('logo', UploadedFile::fake()->create('not-an-image.txt', 1, 'text/plain'))
        ->call('saveBranding')
        ->assertHasErrors(['logo']);
});
