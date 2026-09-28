@php
    $width = in_array((string) $settings->get('receipt_width', '80'), ['72', '80']) ? $settings->get('receipt_width', '80') : '80';
    $currency = (string) $settings->get('currency_symbol', 'ل.س');
    $companyName = (string) $settings->get('company_name', $settings->get('site_name', 'Khaloo'));
    $companyPhone = $settings->get('company_phone');
    $logo = $settings->get('invoice_logo', $settings->get('logo'));
    $typeLabel = $invoice->isSale() ? 'فاتورة مبيعات' : 'فاتورة مشتريات';
    $partyLabel = $invoice->isSale() ? 'العميل' : 'المورد';
    $invoiceNumber = '#'.$invoice->getKey();
    $itemsSubtotal = (float) $invoice->lines->sum('line_total');
    $discount = max(0, round($itemsSubtotal - (float) $invoice->total, 2));
    $printFonts = ['cairo' => 'Cairo, Tahoma, Arial, sans-serif', 'changa' => 'Changa, Tahoma, Arial, sans-serif', 'reem-kufi' => '"Reem Kufi", Tahoma, Arial, sans-serif', 'baloo' => '"Baloo Bhaijaan 2", Tahoma, Arial, sans-serif'];
    $printFont = $printFonts[$settings->get('font_family', 'cairo')] ?? $printFonts['cairo'];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $typeLabel }} {{ $invoiceNumber }}</title>
    @vite(['resources/css/receipt.css'])
    <style>:root{--receipt-width:{{ $width }}mm;--receipt-font:{!! $printFont !!};}</style>
</head>
<body class="receipt-page">
    <div class="receipt-preview">
        <main class="receipt receipt--{{ $width }}" aria-label="{{ $typeLabel }} {{ $invoiceNumber }}">
            <header class="receipt-header">
                <div class="receipt-brand">
                     @if($settings->get('receipt_show_logo', true) && $logo)
                        <img class="receipt-brand__logo" src="{{ Storage::url($logo) }}" alt="شعار {{ $companyName }}">
                    {{-- @else
                        <span class="receipt-brand__mark" aria-hidden="true">{{ mb_substr($companyName, 0, 1) }}</span>
                  --}}   @endif
                    <div class="receipt-brand__copy">@if($settings->get('receipt_show_company', true))<h1>{{ $companyName }}</h1>@endif</div>
                </div>
                <div class="receipt-kind"><span>مستند مالي</span><strong>{{ $typeLabel }}</strong></div>
            </header>

            <dl class="receipt-facts">
                @if($settings->get('receipt_show_number', true))<div><dt>رقم الفاتورة</dt><dd dir="ltr">{{ $invoiceNumber }}</dd></div>@endif
                <div><dt>التاريخ</dt><dd dir="ltr">{{ $invoice->created_at->format('Y-m-d H:i') }}</dd></div>
                @if($settings->get('receipt_show_phone', true) && filled($companyPhone))<div><dt>رقم الهاتف</dt><dd dir="ltr">{{ $companyPhone }}</dd></div>@endif
            </dl>

            @if($settings->get('receipt_show_partner', true) && $invoice->partner)
                <p class="receipt-customer">{{ $partyLabel }}: <strong>{{ $invoice->partner->name }}</strong></p>
            @endif

            <section class="receipt-items" aria-label="بنود الفاتورة">
                <div class="receipt-section-label">بنود الفاتورة</div>
                <table>
                    <thead><tr><th>الصنف</th><th class="receipt-number">الكمية</th><th class="receipt-number">السعر</th><th class="receipt-number">الإجمالي</th></tr></thead>
                    <tbody>
                        @foreach($invoice->lines as $line)
                            <tr><td>{{ $line->product_name }}</td><td class="receipt-number">{{ number_format((float) $line->quantity) }}</td><td class="receipt-number">{{ number_format((float) $line->unit_price, 2) }}</td><td class="receipt-number">{{ number_format((float) $line->line_total, 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="receipt-totals" aria-label="ملخص المبالغ">
                <div><span>المجموع</span><b>{{ number_format($itemsSubtotal, 2) }} {{ $currency }}</b></div>
                @if($discount > 0)<div><span>الحسم</span><b>{{ number_format($discount, 2) }} {{ $currency }}</b></div>@endif
                <div class="receipt-totals__grand"><span>الإجمالي</span><strong>{{ number_format((float) $invoice->total, 2) }} {{ $currency }}</strong></div>
                <div><span>المدفوع</span><b>{{ number_format((float) $invoice->amount_paid, 2) }} {{ $currency }}</b></div>
                <div class="receipt-totals__remaining"><span>المتبقي</span><b>{{ number_format((float) $invoice->remaining, 2) }} {{ $currency }}</b></div>
            </section>

            @if($settings->get('receipt_show_footer', true))
                <footer class="receipt-footer"><p>{{ $settings->get('receipt_footer', 'شكرًا لتعاملكم معنا') }}</p><small>طُبع في {{ now()->format('Y-m-d H:i') }}</small></footer>
            @endif
        </main>

        <div class="receipt-screen-actions" aria-label="إجراءات الإيصال">
            <button type="button" class="receipt-screen-actions__print" onclick="window.print()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17h10v4H7zM5 17H3v-7h18v7h-2M17 12h1"/></svg><span>طباعة الإيصال</span></button>
            <button type="button" onclick="window.close()">إغلاق</button>
        </div>
    </div>
    @if($settings->get('receipt_auto_print', true))<script>addEventListener('load', () => setTimeout(() => window.print(), 250))</script>@endif
</body>
</html>
