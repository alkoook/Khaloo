<?php

use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Quotation;
use App\Services\FinancialService;
use App\Services\QuotationDocumentService;
use App\Services\SettingsService;
use App\Services\ThermalPrintSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$output = storage_path('app/qa-print-redesign');
if (! is_dir($output)) {
    mkdir($output, 0775, true);
}

$settings = app(SettingsService::class);
$thermal = app(ThermalPrintSettings::class)->resolve();
$financials = app(FinancialService::class);
$quotation = Quotation::query()->with(['lines', 'partner'])->withCount('lines')->orderByDesc('lines_count')->firstOrFail();
$invoice = Invoice::query()->where('type', Invoice::TYPE_SALE)->withCount('lines')->orderByDesc('lines_count')->firstOrFail()
    ->load(['partner', 'lines', 'additionalLines', 'originalInvoice', 'settlements']);

$customers = Partner::query()->where('type', Partner::TYPE_CUSTOMER)->get();
$suppliers = Partner::query()->where('type', Partner::TYPE_SUPPLIER)->get();
$customer = $customers->sortByDesc(fn (Partner $partner) => $financials->thermalStatementData($partner)['rows']->count())->first();
$supplier = $suppliers->sortByDesc(fn (Partner $partner) => $financials->thermalStatementData($partner)['rows']->count())->first();

$embedLogo = function (string $html) use ($thermal): string {
    $logo = $thermal['logo'];
    $path = is_string($logo) ? storage_path('app/public/'.$logo) : null;
    if (! $path || ! is_file($path)) {
        return $html;
    }
    $mime = mime_content_type($path) ?: 'image/png';
    return str_replace(Storage::url($logo), 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path)), $html);
};

$render = function (string $view, array $data, string $filename) use ($output, $embedLogo): void {
    file_put_contents($output.DIRECTORY_SEPARATOR.$filename, $embedLogo(view($view, $data)->render()));
};

$render('quotations.thermal', compact('quotation', 'settings', 'thermal'), 'quotation-72mm.html');
$render('statements.thermal', [
    'partner' => $customer, 'from' => null, 'to' => null,
    'statement' => $financials->thermalStatementData($customer), 'settings' => $settings, 'thermal' => $thermal,
], 'customer-statement-72mm.html');
$render('statements.thermal', [
    'partner' => $supplier, 'from' => null, 'to' => null,
    'statement' => $financials->thermalStatementData($supplier), 'settings' => $settings, 'thermal' => $thermal,
], 'supplier-statement-72mm.html');

$thermal80 = $thermal;
$thermal80['paper_width_mm'] = 80;
$thermal80['content_width_mm'] = 76;
$thermal80['is_narrow'] = false;
$render('quotations.thermal', ['quotation' => $quotation, 'settings' => $settings, 'thermal' => $thermal80], 'quotation-80mm.html');
$render('statements.thermal', [
    'partner' => $customer, 'from' => null, 'to' => null,
    'statement' => $financials->thermalStatementData($customer), 'settings' => $settings, 'thermal' => $thermal80,
], 'customer-statement-80mm.html');

$receipt = $embedLogo(view('invoices.receipt', compact('invoice', 'settings', 'thermal'))->render());
$receiptCss = file_get_contents(resource_path('css/receipt.css'))."\n".file_get_contents(resource_path('css/receipt-preview.css'));
$receipt = str_replace('</head>', '<style>'.$receiptCss.'</style></head>', $receipt);
file_put_contents($output.DIRECTORY_SEPARATOR.'sales-invoice-'.$thermal['paper_width_mm'].'mm.html', $receipt);

$docx = app(QuotationDocumentService::class)->createWord($quotation);
copy($docx, $output.DIRECTORY_SEPARATOR.'quotation.docx');
@unlink($docx);

echo json_encode([
    'output' => $output,
    'configured_width' => $thermal['paper_width_mm'],
    'font' => $thermal['font_key'],
    'font_size' => $thermal['font_size_px'],
    'quotation' => ['id' => $quotation->id, 'lines' => $quotation->lines->count()],
    'invoice' => ['id' => $invoice->id, 'lines' => $invoice->lines->count()],
    'customer' => ['id' => $customer?->id, 'movements' => $customer ? $financials->thermalStatementData($customer)['rows']->count() : 0],
    'supplier' => ['id' => $supplier?->id, 'movements' => $supplier ? $financials->thermalStatementData($supplier)['rows']->count() : 0],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
