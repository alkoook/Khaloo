<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Services\FinancialService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Services\ThermalPrintSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountStatementController extends Controller
{
    public function show(Request $request, Partner $partner, FinancialService $financials, SettingsService $settings): View
    {
        $filters = $this->filters($request);

        $data = $this->viewData($partner, $financials, $filters);
        $safeName = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $partner->name), '-_'), 0, 80);

        return view('statements.show', $data + [
            'settings' => $settings,
            'screenshotFilename' => ($partner->isCustomer() ? 'كشف-حساب-السيد-' : 'كشف-حساب-المورد-')
                .($safeName !== '' ? $safeName : $partner->id).'-'.now()->toDateString().'.png',
        ]);
    }

    public function thermal(Request $request, Partner $partner, FinancialService $financials, SettingsService $settings, ThermalPrintSettings $thermalSettings): View
    {
        $filters = $this->filters($request);

        return view('statements.thermal', [
            'partner' => $partner,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'statement' => $financials->thermalStatementData($partner, $filters['from'] ?? null, $filters['to'] ?? null),
            'settings' => $settings,
            'thermal' => $thermalSettings->resolve(),
        ]);
    }

    public function telegram(Request $request, Partner $partner, ReportService $reports, TelegramService $telegram): RedirectResponse
    {
        $filters = $this->filters($request);
        $path = $reports->statement($partner, $filters['from'] ?? null, $filters['to'] ?? null, $filters['movement_type'] ?? null);
        $caption = ($partner->isCustomer() ? 'كشف حساب السيد: ' : 'كشف حساب المورد: ').$partner->name
            .' | '.(($filters['from'] ?? null) ?: 'بداية السجل').' — '.(($filters['to'] ?? null) ?: 'اليوم');

        try {
            $sent = $telegram->sendDocument($path, $caption);
        } finally {
            @unlink($path);
        }

        return back()->with($sent ? 'success' : 'error', $sent
            ? 'تم إرسال كشف الحساب إلى تيليغرام بنجاح.'
            : 'تعذر إرسال كشف الحساب. راجع إعدادات تيليغرام واتصال الشبكة.');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'movement_type' => ['nullable', Rule::in(['invoice', 'settlement', 'return'])],
        ], [
            'from.date_format' => 'تاريخ البداية غير صالح.',
            'to.date_format' => 'تاريخ النهاية غير صالح.',
            'to.after_or_equal' => 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.',
            'movement_type.in' => 'نوع الحركة المحدد غير صالح.',
        ]);
    }

    private function viewData(Partner $partner, FinancialService $financials, array $filters): array
    {
        return [
            'partner' => $partner,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'movementType' => $filters['movement_type'] ?? null,
            'statement' => $financials->statementData($partner, $filters['from'] ?? null, $filters['to'] ?? null, $filters['movement_type'] ?? null),
        ];
    }
}
