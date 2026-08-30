<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ExportFileService
{
    public function path(string $filename): string
    {
        Storage::disk('public')->makeDirectory('reports');
        $safe = preg_replace('/[^\pL\pN._-]+/u', '_', $filename) ?: 'report_'.now()->format('Ymd_His').'.docx';
        return Storage::disk('public')->path('reports/'.trim($safe, '_'));
    }
}
