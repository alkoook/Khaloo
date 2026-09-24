<?php

namespace App\Http\Controllers;

use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    public function __invoke(ReportExport $export): BinaryFileResponse
    {
        abort_unless((int) $export->user_id === (int) auth()->id(), 403);
        abort_unless($export->status === 'ready' && $export->path && Storage::disk('local')->exists($export->path), 404);

        return response()->download(Storage::disk('local')->path($export->path), basename($export->path));
    }
}
