@php
    $documentTitle = $partner->isCustomer() ? 'كشف حساب السيد' : 'كشف حساب المورد';
    $period = match (true) {
        filled($from) && filled($to) => \Carbon\Carbon::parse($from)->format('d/m/Y').' — '.\Carbon\Carbon::parse($to)->format('d/m/Y'),
        filled($from) => 'من '.\Carbon\Carbon::parse($from)->format('d/m/Y').' حتى اليوم',
        filled($to) => 'كامل السجل حتى '.\Carbon\Carbon::parse($to)->format('d/m/Y'),
        default => 'كامل السجل حتى اليوم',
    };
    $closing = (float) $statement['closing'];
    $opening = (float) $statement['opening'];
    $balanceMeaning = match (true) {
        abs($closing) < 0.005 => 'الحساب مسدد',
        $partner->isCustomer() && $closing < 0 => 'رصيد للسيد لدى المحل',
        $partner->isCustomer() => 'مطلوب من السيد',
        $closing < 0 => 'رصيد للمحل لدى المورد',
        default => 'مطلوب للمورد',
    };
    $signed = fn ($amount) => ((float) $amount > 0 ? '+' : ((float) $amount < 0 ? '−' : '')).number_format(abs((float) $amount), 2);
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }} — {{ $partner->name }}</title>
    @include('thermal._styles')
    <style>
        .statement-balances{margin:1.2mm 0 1.6mm;border-top:.25mm solid var(--thermal-ink);border-bottom:.25mm solid var(--thermal-ink)}
        .statement-balance{display:flex;justify-content:space-between;align-items:baseline;gap:2mm;padding:.7mm .4mm}.statement-balance+.statement-balance{border-top:.15mm dotted var(--thermal-line)}
        .statement-balance span{color:var(--thermal-muted);font-size:calc(var(--thermal-base) - 2px);font-weight:700}.statement-balance strong{direction:ltr;font-variant-numeric:tabular-nums}
        .statement-balance--current{padding-block:1mm;background:var(--thermal-soft)}.statement-balance--current span{color:var(--thermal-ink)}.statement-balance--current strong{font-size:calc(var(--thermal-base) + 2px);font-weight:900}
        .statement-balance__meaning{display:block;padding:0 .4mm .8mm;font-size:calc(var(--thermal-base) - 2px);font-weight:800;text-align:left}
        .statement-movements{margin-top:1.2mm;border-top:.35mm solid var(--thermal-ink)}.statement-movement{padding:1.1mm .35mm;border-bottom:.18mm solid #aaa;break-inside:avoid;page-break-inside:avoid}
        .statement-movement__head,.statement-movement__money{display:flex;justify-content:space-between;gap:2mm}.statement-movement__head{align-items:flex-start}.statement-movement__date{flex:0 0 auto;direction:ltr;color:var(--thermal-muted);font-size:calc(var(--thermal-base) - 2px);font-weight:700}
        .statement-movement__description{min-width:0;font-size:calc(var(--thermal-base) - 1px);font-weight:900;overflow-wrap:anywhere}.statement-movement__money{margin-top:.6mm;padding-top:.5mm;border-top:.12mm dotted #bbb;font-size:calc(var(--thermal-base) - 2px)}
        .statement-movement__money b{direction:ltr;font-size:calc(var(--thermal-base) - 1px);font-variant-numeric:tabular-nums}.statement-empty{padding:3mm 1mm;color:var(--thermal-muted);text-align:center}.statement-summary{margin-top:1.8mm}
        @media print{.statement-balances,.statement-summary{break-inside:avoid;page-break-inside:avoid}}
    </style>
</head>
<body>
    <nav class="thermal-preview-actions" aria-label="إجراءات كشف الحساب"><button type="button" onclick="window.print()">طباعة</button><button type="button" onclick="window.close()">إغلاق</button></nav>
    <main class="thermal-paper thermal-paper--{{ $thermal['paper_width_mm'] }}" aria-label="{{ $documentTitle }}">
        @include('thermal._header')
        <section class="thermal-meta" aria-label="بيانات الحساب">
            @if($thermal['show_partner'])
                <div class="thermal-meta__row"><span class="thermal-meta__label">الاسم</span><strong class="thermal-meta__value">{{ $partner->name }}</strong></div>
                @if(filled($partner->phone))<div class="thermal-meta__row"><span class="thermal-meta__label">الهاتف</span><strong class="thermal-meta__value thermal-ltr">{{ $partner->phone }}</strong></div>@endif
            @endif
            <div class="thermal-meta__row"><span class="thermal-meta__label">الفترة</span><strong class="thermal-meta__value">{{ $period }}</strong></div>
        </section>
        <section class="statement-balances" aria-label="ملخص الرصيد">
            <div class="statement-balance"><span>الرصيد السابق</span><strong>{{ number_format(abs($opening), 2) }}</strong></div>
            <div class="statement-balance statement-balance--current"><span>الرصيد الحالي</span><strong>{{ number_format(abs($closing), 2) }}</strong></div>
            <span class="statement-balance__meaning">{{ $balanceMeaning }}</span>
        </section>
        <h2 class="thermal-section-title">الحركات</h2>
        <section class="statement-movements" aria-label="حركات الحساب">
            @forelse($statement['rows'] as $row)
                <article class="statement-movement">
                    <div class="statement-movement__head"><strong class="statement-movement__description">{{ $row['description'] }}</strong><time class="statement-movement__date">{{ $row['date']->format('d/m/Y') }}</time></div>
                    <div class="statement-movement__money"><span>المبلغ <b>{{ $signed($row['amount']) }}</b></span><span>الرصيد <b>{{ number_format((float) $row['balance'], 2) }}</b></span></div>
                </article>
            @empty
                <div class="statement-empty">لا توجد حركات خلال هذه الفترة.</div>
            @endforelse
        </section>
        <section class="thermal-totals statement-summary" aria-label="ملخص كشف الحساب">
            @if((float) $statement['invoices_total'] != 0)<div class="thermal-total-row"><span>إجمالي الفواتير</span><strong>{{ number_format((float) $statement['invoices_total'], 2) }}</strong></div>@endif
            @if((float) $statement['payments_total'] != 0)<div class="thermal-total-row"><span>إجمالي الدفعات</span><strong>{{ number_format((float) $statement['payments_total'], 2) }}</strong></div>@endif
            @if((float) $statement['returns_total'] > 0)<div class="thermal-total-row"><span>إجمالي المرتجعات</span><strong>{{ number_format((float) $statement['returns_total'], 2) }}</strong></div>@endif
            <div class="thermal-total-row thermal-total-row--grand"><span>الرصيد الحالي</span><strong>{{ number_format(abs($closing), 2) }}</strong></div>
        </section>
        @include('thermal._footer')
    </main>
</body>
</html>
