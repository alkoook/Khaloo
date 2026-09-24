<?php

namespace App\Jobs;

use App\Models\Partner;
use App\Models\ReportExport;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 2;

    public function __construct(public string $exportId) {}

    public function handle(ReportService $reports): void
    {
        $export = ReportExport::query()->findOrFail($this->exportId);
        if ($export->status === 'ready') return;

        $export->update(['status' => 'processing', 'path' => null]);

        try {
            $filters = $export->filters;
            $file = $export->kind === 'statement'
                ? $reports->statement(Partner::query()->findOrFail($export->partner_id), $filters['from'] ?? null, $filters['to'] ?? null)
                : $reports->export($export->report_type, $filters);
            $relativePath = 'exports/'.basename($file);
            if (! Storage::disk('local')->exists($relativePath)) {
                throw new RuntimeException('لم يُنشأ ملف التقرير في التخزين الخاص.');
            }
            $export->update(['status' => 'ready', 'path' => $relativePath]);
        } catch (Throwable $exception) {
            $export->update(['status' => 'pending', 'path' => null]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->exportId)->update(['status' => 'failed', 'path' => null]);
    }
}
