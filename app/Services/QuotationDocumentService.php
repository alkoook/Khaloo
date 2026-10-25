<?php

namespace App\Services;

use App\Models\Quotation;
use App\Support\Decimal;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\Style\Language;

class QuotationDocumentService
{
    public function __construct(
        private readonly ThermalPrintSettings $thermalSettings,
        private readonly ExportFileService $exports,
    ) {}

    public function createWord(Quotation $quotation): string
    {
        $quotation->loadMissing('partner', 'lines');
        $thermal = $this->thermalSettings->resolve();
        $word = new PhpWord;
        $word->getSettings()->setThemeFontLang(new Language('ar-SA'));
        $word->setDefaultFontName($this->wordFont($thermal['font_key']));
        $word->setDefaultFontSize(10);

        $section = $word->addSection([
            'orientation' => 'portrait',
            'marginTop' => 720,
            'marginRight' => 820,
            'marginBottom' => 760,
            'marginLeft' => 820,
        ]);

        if ($thermal['show_logo'] && ($logo = $this->logoPath($thermal['logo']))) {
            $section->addImage($logo, ['width' => 54, 'alignment' => Jc::CENTER]);
        }
        if ($thermal['show_company']) {
            $section->addText($thermal['company_name'], ['bold' => true, 'size' => 17], $this->rtl(Jc::CENTER, 40));
        }
        if ($thermal['show_phone'] && $thermal['company_phones'] !== []) {
            $section->addText(implode('  |  ', $thermal['company_phones']), ['size' => 9, 'color' => '444444'], ['alignment' => Jc::CENTER, 'spaceAfter' => 40]);
        }
        if ($thermal['show_address'] && $thermal['company_address'] !== '') {
            $section->addText($thermal['company_address'], ['size' => 8, 'color' => '666666'], $this->rtl(Jc::CENTER, 70));
        }

        $rule = $section->addTable(['width' => 100 * 50, 'unit' => 'pct']);
        $rule->addRow(80)->addCell(9300, ['borderBottomSize' => 12, 'borderBottomColor' => '222222']);
        $section->addText('عرض سعر', ['bold' => true, 'size' => 20], $this->rtl(Jc::CENTER, 90, 70));

        $identity = $section->addTable([
            'width' => 100 * 50,
            'unit' => 'pct',
            'bidiVisual' => true,
            'cellMargin' => 55,
            'borderBottomSize' => 4,
            'borderBottomColor' => 'B7B7B7',
        ]);
        $identity->addRow();
        $this->addInfoCell($identity->addCell(4650), 'رقم العرض', $quotation->number, false);
        $this->addInfoCell($identity->addCell(4650), 'التاريخ', $quotation->quotation_date->format('d/m/Y'), false);
        $identity->addRow();
        $this->addInfoCell($identity->addCell(4650), 'مقدم إلى السيد', $quotation->display_customer, true);
        $phone = trim((string) ($quotation->customer_phone ?: $quotation->partner?->phone));
        $this->addInfoCell($identity->addCell(4650), 'الهاتف', $phone !== '' ? $phone : '—', false);

        $section->addTextBreak(1);
        $table = $section->addTable([
            'width' => 100 * 50,
            'unit' => 'pct',
            'bidiVisual' => true,
            'alignment' => JcTable::CENTER,
            'cellMargin' => 85,
            'borderSize' => 4,
            'borderColor' => 'B8B8B8',
        ]);
        $widths = [620, 4880, 900, 1450, 1450];
        $headings = ['الرقم', 'الصنف', 'الكمية', 'السعر الإفرادي', 'الإجمالي'];
        $table->addRow(430, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headings as $column => $heading) {
            $cell = $table->addCell($widths[$column], [
                'bgColor' => 'EEEEEE',
                'valign' => 'center',
                'borderBottomSize' => 8,
                'borderBottomColor' => '333333',
            ]);
            $cell->addText($heading, ['bold' => true, 'size' => 10], $this->rtl(Jc::CENTER, 0));
        }
        foreach ($quotation->lines as $index => $line) {
            $table->addRow(null, ['cantSplit' => true]);
            $values = [
                (string) ($index + 1),
                $line->product_name,
                Decimal::displayQuantity($line->quantity),
                number_format((float) $line->unit_price, 2),
                number_format((float) $line->line_total, 2),
            ];
            foreach ($values as $column => $value) {
                $cell = $table->addCell($widths[$column], ['valign' => 'center', 'bgColor' => 'FFFFFF']);
                $cell->addText((string) $value, $column === 1 ? ['bold' => true, 'size' => 10] : ['size' => 9], [
                    'alignment' => $column === 1 ? Jc::RIGHT : Jc::CENTER,
                    'bidi' => $column === 1,
                    'spaceAfter' => 0,
                ]);
            }
        }

        $section->addTextBreak(1);
        $totals = $section->addTable([
            'width' => 44 * 50,
            'unit' => 'pct',
            'alignment' => JcTable::END,
            'bidiVisual' => true,
            'cellMargin' => 65,
        ]);
        $this->addTotalRow($totals, 'المجموع', (float) $quotation->subtotal, $thermal['currency']);
        if ((float) $quotation->discount_amount > 0) {
            $this->addTotalRow($totals, 'الخصم', -(float) $quotation->discount_amount, $thermal['currency']);
        }
        $this->addTotalRow($totals, 'الإجمالي النهائي', (float) $quotation->total, $thermal['currency'], true);

        if (filled($quotation->notes)) {
            $section->addTextBreak(1);
            $section->addText('ملاحظات', ['bold' => true, 'size' => 11], $this->rtl(Jc::RIGHT, 40));
            $section->addText(trim((string) $quotation->notes), ['size' => 10], $this->rtl(Jc::RIGHT, 80));
        }

        $footerText = trim(implode('  |  ', array_filter([
            $thermal['company_name'],
            ...$thermal['company_phones'],
            $thermal['company_address'],
        ])));
        if ($footerText !== '') {
            $footer = $section->addFooter();
            $footer->addText($footerText, ['size' => 8, 'color' => '666666'], $this->rtl(Jc::CENTER, 0));
        }

        $path = $this->exports->path('عرض-سعر-'.$quotation->number.'-'.now()->format('Ymd-His').'.docx');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    private function addInfoCell($cell, string $label, string $value, bool $rtlValue): void
    {
        $cell->addText($label, ['size' => 8, 'color' => '666666'], $this->rtl(Jc::RIGHT, 10));
        $cell->addText($value, ['bold' => true, 'size' => 10], [
            'alignment' => Jc::RIGHT,
            'bidi' => $rtlValue,
            'spaceAfter' => 35,
        ]);
    }

    private function addTotalRow($table, string $label, float $amount, string $currency, bool $grand = false): void
    {
        $row = $table->addRow($grand ? 460 : 360, ['cantSplit' => true]);
        $cellStyle = $grand
            ? ['borderTopSize' => 12, 'borderTopColor' => '222222', 'borderBottomSize' => 12, 'borderBottomColor' => '222222']
            : ['borderBottomSize' => 3, 'borderBottomColor' => 'C8C8C8'];
        $row->addCell(2450, $cellStyle)->addText($label, ['bold' => $grand, 'size' => $grand ? 12 : 10], $this->rtl(Jc::RIGHT, 0));
        $formatted = ($amount < 0 ? '−' : '').number_format(abs($amount), 2).($currency !== '' ? ' '.$currency : '');
        $row->addCell(1750, $cellStyle)->addText($formatted, ['bold' => true, 'size' => $grand ? 12 : 10], ['alignment' => Jc::LEFT, 'spaceAfter' => 0]);
    }

    /** @return array<string, mixed> */
    private function rtl(string $alignment, int $spaceAfter = 0, int $spaceBefore = 0): array
    {
        return compact('alignment', 'spaceAfter', 'spaceBefore') + ['bidi' => true];
    }

    private function logoPath(mixed $logo): ?string
    {
        if (! is_string($logo) || trim($logo) === '') {
            return null;
        }
        $path = storage_path('app/public/'.$logo);

        return is_file($path) ? $path : null;
    }

    private function wordFont(string $fontKey): string
    {
        return [
            'cairo' => 'Cairo',
            'changa' => 'Changa',
            'reem-kufi' => 'Reem Kufi',
            'baloo' => 'Baloo Bhaijaan 2',
        ][$fontKey] ?? 'Arial';
    }
}
