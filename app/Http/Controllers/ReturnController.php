<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use PhpOffice\PhpWord\TemplateProcessor;

class ReturnController extends Controller
{
    public function exportWord(Invoice $invoice)
    {
        abort_if(!$invoice->isReturn(), 404, 'الفاتورة ليست مرتجع.');
        $invoice->load(['partner', 'lines']);

        $templatePath = storage_path('app/public/templates/quotation.docx');
        if (!file_exists($templatePath)) {
            return back()->with('error', 'قالب الفاتورة غير موجود. يرجى إضافة quotation.docx في المجلد.');
        }

        $templateProcessor = new TemplateProcessor($templatePath);
        
        // Adjust the title logically for Sales Return
        $title = 'إشعار مردود مبيعات (مرتجع) - رقم #' . $invoice->id;
        $partnerName = $invoice->partner ? $invoice->partner->name : 'زبون نقدي';

        $templateProcessor->setValue('client_name', $title . ' | الزبون: ' . $partnerName);
        $templateProcessor->setValue('date', $invoice->created_at->format('Y-m-d H:i'));

        $rows = [];
        foreach ($invoice->lines as $item) {
            $rows[] = [
                'p_name' => $item->product_name,
                'p_qty' => $item->quantity,
                'p_price' => number_format((float)$item->unit_price, 2),
                'p_total' => number_format((float)$item->line_total, 2),
            ];
        }

        $templateProcessor->cloneRowAndSetValues('p_name', $rows);
        $templateProcessor->setValue('grand_total', number_format((float)$invoice->total, 2));

        $fileName = 'فاتورة_مرتجع_' . $invoice->id . '_' . date('Ymd_His') . '.docx';
        $outputPath = app(\App\Services\ExportFileService::class)->path($fileName);

        $templateProcessor->saveAs($outputPath);

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }
}
