@php
    $settings = app(\App\Services\SettingsService::class);
    $printFonts = [
        'cairo' => 'Cairo, Tahoma, Arial, sans-serif',
        'changa' => 'Changa, Tahoma, Arial, sans-serif',
        'reem-kufi' => '"Reem Kufi", Tahoma, Arial, sans-serif',
        'baloo' => '"Baloo Bhaijaan 2", Tahoma, Arial, sans-serif',
    ];
    $printFont = $printFonts[$settings->get('font_family', 'cairo')] ?? $printFonts['cairo'];
    $companyName = (string) $settings->get('company_name', $settings->get('site_name', 'Khaloo'));
    $companyDescription = trim((string) $settings->get('company_description', ''));
    $companyPhone = trim((string) $settings->get('company_phone', ''));
    $companyEmail = trim((string) $settings->get('company_email', ''));
    $companyAddress = trim((string) $settings->get('company_address', ''));
    $companyLogo = $settings->get('logo');
    $customerPhone = trim((string) ($quotation->customer_phone ?: $quotation->partner?->phone));
    $currencySymbol = trim((string) $settings->get('currency_symbol', ''));
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>عرض سعر {{ $quotation->number }}</title>
    @vite('resources/css/quotation-print.css')
</head>
<body style="--quotation-font: {!! $printFont !!};">
    <div class="quotation-screen-actions no-print">
        <button type="button" class="quotation-print-button" onclick="window.print()">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17h10v4H7zM5 17H3v-7h18v7h-2M17 12h1"/></svg>
            <span>طباعة عرض السعر</span>
        </button>
    </div>

    <main class="quotation-document">
        <header class="quotation-masthead">
            <section class="quotation-brand">
                <div class="quotation-logo" aria-hidden="true">
                    @if($companyLogo)<img src="{{ Storage::url($companyLogo) }}" alt="">@else<span>{{ mb_substr($companyName, 0, 1) }}</span>@endif
                </div>
                <div class="quotation-brand-copy">
                    <p class="quotation-kicker">{{ $companyDescription ?: 'حلول أعمال منظمة وواضحة' }}</p>
                    <h1>{{ $companyName }}</h1>
                    <div class="quotation-company-contact">
                        @if($companyPhone)<span>{{ $companyPhone }}</span>@endif
                        @if($companyEmail)<span>{{ $companyEmail }}</span>@endif
                        @if($companyAddress)<span>{{ $companyAddress }}</span>@endif
                    </div>
                </div>
            </section>

            <section class="quotation-identity">
                <span class="quotation-document-label">عرض سعر</span>
                <strong>{{ $quotation->number }}</strong>
                <dl>
                    <div><dt>تاريخ الإنشاء</dt><dd>{{ $quotation->quotation_date?->format('Y-m-d') }}</dd></div>
                    @if($quotation->expires_at)<div><dt>صالح حتى</dt><dd>{{ $quotation->expires_at->format('Y-m-d') }}</dd></div>@endif
                </dl>
            </section>
        </header>

        <section class="quotation-client-card">
            <div class="quotation-client-marker"><span>إلى</span></div>
            <div><p>مقدم إلى</p><h2>{{ $quotation->display_customer }}</h2>@if($customerPhone)<span class="quotation-client-phone">{{ $customerPhone }}</span>@endif</div>
            <div class="quotation-client-note">يسعدنا تقديم عرض السعر التالي لكم، وفق التفاصيل الموضحة أدناه.</div>
        </section>

        <section class="quotation-lines-section">
            <div class="quotation-section-heading"><div><span>تفاصيل العرض</span><h2>الأصناف والخدمات المقترحة</h2></div><small>{{ $quotation->lines->count() }} {{ $quotation->lines->count() === 1 ? 'صنف' : 'أصناف' }}</small></div>
            <div class="quotation-table-wrap">
                <table class="quotation-lines">
                    <thead><tr><th class="line-number">#</th><th>المنتج</th><th class="line-quantity">الكمية</th><th class="line-money">السعر</th><th class="line-money">الإجمالي</th></tr></thead>
                    <tbody>@foreach($quotation->lines as $line)<tr><td class="line-number">{{ $loop->iteration }}</td><td class="line-product">{{ $line->product_name }}</td><td class="line-quantity">{{ \App\Support\Decimal::displayQuantity($line->quantity) }}</td><td class="line-money">{{ number_format((float) $line->unit_price, 2) }}</td><td class="line-money line-total">{{ number_format((float) $line->line_total, 2) }}</td></tr>@endforeach</tbody>
                </table>
            </div>
        </section>

        <section class="quotation-closing-grid">
            <div class="quotation-notes-stack">
                @if($quotation->notes)<article class="quotation-notes-card"><span class="quotation-notes-icon" aria-hidden="true">✦</span><div><h2>ملاحظات العرض</h2><p>{!! nl2br(e($quotation->notes)) !!}</p></div></article>@endif
                <article class="quotation-validity-card"><h2>شروط وصلاحية العرض</h2><p>@if($quotation->expires_at)هذا العرض صالح حتى {{ $quotation->expires_at->format('Y-m-d') }}.@else هذا العرض قابل للمراجعة عند الحاجة.@endif الأسعار والأصناف حسب التفاصيل أعلاه.</p></article>
            </div>
            <aside class="quotation-totals-card" aria-label="إجماليات عرض السعر">
                <div class="quotation-total-row"><span>المجموع</span><b>{{ number_format((float) $quotation->subtotal, 2) }} {{ $currencySymbol }}</b></div>
                @if((float) $quotation->discount_amount > 0)<div class="quotation-total-row quotation-discount"><span>الحسم {{ $quotation->discount_type === 'percentage' ? '('.number_format((float)$quotation->discount_value,2).'%)' : '(ثابت)' }}</span><b>− {{ number_format((float) $quotation->discount_amount, 2) }} {{ $currencySymbol }}</b></div>@endif
                <div class="quotation-total-final"><span>الإجمالي النهائي</span><strong>{{ number_format((float) $quotation->total, 2) }} <small>{{ $currencySymbol }}</small></strong></div>
            </aside>
        </section>

        <footer class="quotation-footer"><p>نشكر لكم ثقتكم ونتطلع للتعامل معكم.</p><div><span>{{ $companyName }}</span>@if($companyPhone)<span>{{ $companyPhone }}</span>@endif<span class="quotation-page-number">صفحة </span></div></footer>
    </main>
</body>
</html>
