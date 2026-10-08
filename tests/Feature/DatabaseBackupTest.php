<?php

use App\Models\InAppNotification;
use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Carbon;

test('only an administrator can see and request the database backup', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($admin)->get(route('settings.index'))
        ->assertOk()->assertSee('نسخ احتياطي لقاعدة البيانات');
    $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
    $this->actingAs($user)->post(route('settings.database-backup'))->assertForbidden();
});

test('mysqldump failure gives a safe Arabic error without exposing credentials', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $backups = Mockery::mock(DatabaseBackupService::class);
    $backups->shouldReceive('create')->once()->andThrow(new RuntimeException('password=very-secret-token'));
    app()->instance(DatabaseBackupService::class, $backups);

    $response = $this->actingAs($admin)->from(route('settings.index'))
        ->post(route('settings.database-backup'));

    $response->assertRedirect(route('settings.index'));
    $message = session('error');
    expect($message)->toContain('تعذر إنشاء النسخة الاحتياطية')
        ->not->toContain('very-secret-token')
        ->not->toContain('password=');
});

test('backup filename has the requested timestamp and sql extension', function () {
    $this->travelTo(Carbon::parse('2026-09-24 13:25:00'));
    expect(app(DatabaseBackupService::class)->filename())->toBe('khaloo-backup-2026-09-24-1325.sql');
});

test('successful backup is downloaded and its private temporary file is removed', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $file = tempnam(storage_path('app/private'), 'backup-test-');
    file_put_contents($file, '-- isolated test backup');
    $backups = Mockery::mock(DatabaseBackupService::class);
    $backups->shouldReceive('create')->once()->andReturn($file);
    $backups->shouldReceive('filename')->once()->andReturn('khaloo-backup-2026-09-24-1325.sql');
    app()->instance(DatabaseBackupService::class, $backups);

    $response = $this->actingAs($admin)->post(route('settings.database-backup'));
    $response->assertOk()->assertDownload('khaloo-backup-2026-09-24-1325.sql');
    expect($file)->not->toStartWith(public_path());
    ob_start();
    $response->baseResponse->sendContent();
    ob_end_clean();
    expect(is_file($file))->toBeFalse();
});

test('Thursday reminder is created once and stays dismissed after reading', function () {
    $this->travelTo(Carbon::parse('2026-09-24 09:00:00'));
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('notifications.index'))->assertOk();
    $reminder = InAppNotification::query()->where('type', 'backup_reminder')->sole();
    expect($reminder->message)->toBe('تذكير: حان وقت أخذ نسخة احتياطية لقاعدة البيانات.');
    $reminder->markRead();

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('notifications.index'))->assertOk();
    expect(InAppNotification::query()->where('type', 'backup_reminder')->count())->toBe(1)
        ->and($reminder->fresh()->read_at)->not->toBeNull();

    $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
    $this->get(route('dashboard'))->assertOk();
    expect(InAppNotification::query()->where('type', 'backup_reminder')->count())->toBe(2);
});

test('Thursday reminder has a visible dismissible banner without creating another reminder', function () {
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00'));
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('backup-reminder', false)->assertSee('إغلاق تذكير النسخ الاحتياطي');
    $reminder = InAppNotification::query()->where('type', 'backup_reminder')->sole();

    $this->post(route('notifications.backup-dismiss', $reminder))->assertRedirect();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('إغلاق تذكير النسخ الاحتياطي');
    expect($reminder->fresh()->read_at)->not->toBeNull()
        ->and(InAppNotification::query()->where('type', 'backup_reminder')->count())->toBe(1);
});
