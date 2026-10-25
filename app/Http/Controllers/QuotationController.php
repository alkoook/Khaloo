<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Services\QuotationDocumentService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Services\ThermalPrintSettings;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuotationController extends Controller
{
    public function print(Quotation $quotation)
    {
        return view('quotations.print', ['quotation' => $quotation->load('lines', 'partner')]);
    }

    public function thermal(Quotation $quotation, SettingsService $settings, ThermalPrintSettings $thermalSettings)
    {
        return view('quotations.thermal', [
            'quotation' => $quotation->load('lines', 'partner'),
            'settings' => $settings,
            'thermal' => $thermalSettings->resolve(),
        ]);
    }

    public function word(Quotation $quotation, QuotationDocumentService $documents): BinaryFileResponse
    {
        $path = $documents->createWord($quotation);

        return response()->download($path, basename($path), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->deleteFileAfterSend(true);
    }

    public function telegram(Quotation $quotation, QuotationDocumentService $documents, TelegramService $telegram): RedirectResponse
    {
        $path = $documents->createWord($quotation->load('partner'));
        try {
            $sent = $telegram->sendDocument($path, "عرض السعر {$quotation->number} — السيد {$quotation->display_customer}");
        } finally {
            @unlink($path);
        }

        return back()->with($sent ? 'success' : 'error', $sent ? 'تم إرسال عرض السعر إلى تيليغرام.' : 'تعذر إرسال عرض السعر. راجع إعدادات تيليغرام واتصال الشبكة.');
    }
}
