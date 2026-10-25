<?php

namespace App\Http\Controllers;

use App\Exceptions\DatabaseBackupException;
use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DatabaseBackupController extends Controller
{
    public function __invoke(DatabaseBackupService $backups): BinaryFileResponse|RedirectResponse
    {
        abort_unless(auth()->user()?->is_admin, 403);

        try {
            $file = $backups->create();

            return response()->download($file, $backups->filename(), [
                'Content-Type' => 'application/sql',
                'Cache-Control' => 'no-store, private',
            ])->deleteFileAfterSend(true);
        } catch (DatabaseBackupException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable) {
            return back()->with('error', 'تعذر إنشاء النسخة الاحتياطية بسبب خطأ غير متوقع. حاول مجددًا.');
        }
    }
}
