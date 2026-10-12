<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class WindowsReceiptCutter
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{enabled: bool, printer: string|null} */
    public function cut(): array
    {
        if (! $this->settings->get('receipt_auto_cut', true)) {
            return ['enabled' => false, 'printer' => null];
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('القص التلقائي متاح فقط عند تشغيل Khaloo على Windows.');
        }

        $script = base_path('scripts/send-receipt-cut.ps1');
        if (! is_file($script)) {
            throw new RuntimeException('ملف إرسال أمر القص إلى Windows غير موجود.');
        }

        $printer = trim((string) $this->settings->get('receipt_printer_name', ''));
        $cutMode = in_array($this->settings->get('receipt_cut_mode', 'partial'), ['full', 'partial'], true)
            ? $this->settings->get('receipt_cut_mode', 'partial')
            : 'partial';
        $feedLines = max(1, min(10, (int) $this->settings->get('receipt_cut_feed_lines', 3)));

        $result = Process::timeout(15)->run([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $script, '-PrinterName', $printer, '-CutMode', $cutMode, '-FeedLines', (string) $feedLines,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('تعذر إرسال أمر القص إلى الطابعة. تحقق من اسم الطابعة ودعم Auto Cutter.');
        }

        return ['enabled' => true, 'printer' => trim($result->output()) ?: ($printer ?: null)];
    }
}
