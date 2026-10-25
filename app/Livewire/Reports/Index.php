<?php

namespace App\Livewire\Reports;

use App\Models\Category;
use App\Models\Partner;
use App\Models\ReportExport;
use App\Jobs\GenerateReportExport;
use App\Services\ReportService;
use App\Services\TelegramService;
use Livewire\Component;

class Index extends Component
{
    public string $type = 'sales';
    public string $from = '';
    public string $to = '';
    public ?int $partner_id = null;
    public ?int $category_id = null;

    public function download(ReportService $reports): void
    {
        $this->validateFilters();
        $export = ReportExport::query()->create([
            'user_id' => auth()->id(), 'kind' => 'report', 'report_type' => $this->type,
            'filters' => $this->filters(), 'title' => $reports->reportTitle($this->type, $this->filters()),
        ]);
        GenerateReportExport::dispatch($export->id)->onConnection('database')->afterCommit();
        session()->flash('success', 'أُضيف التقرير إلى قائمة المعالجة. سيظهر رابط التنزيل هنا عند اكتماله.');
    }

    public function downloadStatement(): void
    {
        $this->validate(['partner_id' => ['required', 'exists:partners,id'], 'from' => ['nullable','date'], 'to' => ['nullable','date','after_or_equal:from']]);
        $partner = Partner::query()->findOrFail($this->partner_id);
        $from = $this->from ?: null;
        $to = $this->to ?: null;
        $export = ReportExport::query()->create([
            'user_id' => auth()->id(), 'kind' => 'statement', 'partner_id' => $partner->id,
            'filters' => ['from' => $from, 'to' => $to],
            'title' => 'كشف حساب '.($partner->isCustomer() ? 'السيد ' : 'المورد ').$partner->name.' من '.($from ?: 'بداية السجل').' إلى '.($to ?: 'اليوم'),
        ]);
        GenerateReportExport::dispatch($export->id)->onConnection('database')->afterCommit();
        session()->flash('success', 'أُضيف كشف الحساب إلى قائمة المعالجة.');
    }

    public function sendStatement(ReportService $reports, TelegramService $telegram): void
    {
        $this->validate(['partner_id' => ['required', 'exists:partners,id']]);
        $partner = Partner::query()->findOrFail($this->partner_id);
        $sent = $telegram->sendMessage($reports->statementMessage($partner, $this->from ?: null, $this->to ?: null));
        $sent ? session()->flash('success', 'تم إرسال كشف الحساب إلى تيليغرام كرسالة نصية مرتبة.') : $this->addError('telegram', 'تعذر الإرسال. راجع تفعيل تيليغرام والبيانات.');
    }

    public function render(ReportService $reports)
    {
        $preview = $reports->preview($this->type, $this->filters());
        return view('livewire.reports.index', [
            'types' => ReportService::TYPES, 'preview' => $preview,
            'partners' => Partner::query()->orderBy('name')->get(['id','name','type']),
            'categories' => Category::query()->orderBy('name')->get(['id','name']),
            'exports' => ReportExport::query()->where('user_id', auth()->id())->latest()->limit(10)->get(),
        ])->layout('layouts.app', ['title' => 'منشئ التقارير']);
    }

    private function validateFilters(): void
    {
        $this->validate(['type' => ['required','in:'.implode(',',array_keys(ReportService::TYPES))], 'from'=>['nullable','date'], 'to'=>['nullable','date','after_or_equal:from'], 'partner_id'=>['nullable','exists:partners,id'], 'category_id'=>['nullable','exists:categories,id']]);
    }

    private function filters(): array { return ['from'=>$this->from ?: null,'to'=>$this->to ?: null,'partner_id'=>$this->partner_id,'category_id'=>$this->category_id]; }
}
