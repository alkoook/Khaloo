<?php

namespace App\Http\Middleware;

use App\Models\InAppNotification;
use App\Models\Setting;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureWeeklyBackupReminder
{
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = app(SettingsService::class)->get('timezone', config('app.timezone'));
        $today = now($timezone);

        if ($today->isThursday()) {
            DB::transaction(function () use ($today): void {
                $marker = Setting::query()->createOrFirst(
                    ['key' => 'backup_reminder_'.$today->format('Y-m-d')],
                    ['value' => '1', 'type' => 'string', 'group' => 'reminders'],
                );
                if ($marker->wasRecentlyCreated) {
                    InAppNotification::query()->create([
                        'type' => 'backup_reminder',
                        'title' => 'تذكير النسخ الاحتياطي',
                        'message' => 'تذكير: حان وقت أخذ نسخة احتياطية لقاعدة البيانات.',
                        'severity' => 'info',
                        'data' => ['date' => $today->format('Y-m-d')],
                    ]);
                }
            });
        }

        return $next($request);
    }
}
