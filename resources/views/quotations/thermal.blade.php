@php
    $documentTitle = 'عرض سعر';
    $partyPhone = trim((string) ($quotation->customer_phone ?: $quotation->partner?->phone));
    $delivery = (float) data_get($quotation, 'delivery_amount', 0);
    $additional = (float) data_get($quotation, 'additional_total', 0);
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>عرض سعر {{ $quotation->number }}</title>
    @include('thermal._styles')
</head>
<body>
    <nav class="thermal-preview-actions" aria-label="إجراءات عرض السعر">
        <button type="button" onclick="window.print()">طباعة</button>
        <button type="button" onclick="window.close()">إغلاق</button>
    </nav>

    <main class="thermal-paper thermal-paper--{{ $thermal['paper_width_mm'] }}" aria-label="عرض سعر {{ $quotation->number }}">
        @include('thermal._header')

        <section class="thermal-meta" aria-label="بيانات عرض السعر">
            @if($thermal['show_number'])
                <div class="thermal-meta__row"><span class="thermal-meta__label">رقم العرض</span><strong class="thermal-meta__value thermal-ltr">{{ $quotation->number }}</strong></div>
            @endif
            <div class="thermal-meta__row"><span class="thermal-meta__label">التاريخ</span><strong class="thermal-meta__value thermal-ltr">{{ $quotation->quotation_date->format('d/m/Y') }}</strong></div>
            @if($thermal['show_partner'])
                <div class="thermal-meta__row"><span class="thermal-meta__label">السيد</span><strong class="thermal-meta__value">{{ $quotation->display_customer }}</strong></div>
                @if($partyPhone !== '')
                    <div class="thermal-meta__row"><span class="thermal-meta__label">الهاتف</span><strong class="thermal-meta__value thermal-ltr">{{ $partyPhone }}</strong></div>
                @endif
            @endif
        </section>

        <h2 class="thermal-section-title">البنود</h2>
        <table class="thermal-items">
            <thead><tr>
                <th class="thermal-col-seq">#</th><th class="thermal-col-name">الصنف</th><th class="thermal-col-qty">الكمية</th><th class="thermal-col-price">السعر</th><th class="thermal-col-total">الإجمالي</th>
            </tr></thead>
            <tbody>
                @foreach($quotation->lines as $line)
                    <tr>
                        <td class="thermal-col-seq">{{ $loop->iteration }}</td>
                        <td class="thermal-col-name">{{ $line->product_name }}</td>
                        <td class="thermal-col-qty">{{ \App\Support\Decimal::displayQuantity($line->quantity) }}</td>
                        <td class="thermal-col-price">{{ number_format((float) $line->unit_price, 2) }}</td>
                        <td class="thermal-col-total">{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <section class="thermal-totals" aria-label="إجماليات عرض السعر">
            <div class="thermal-total-row"><span>المجموع</span><strong>{{ number_format((float) $quotation->subtotal, 2) }} {{ $thermal['currency'] }}</strong></div>
            @if((float) $quotation->discount_amount > 0)
                <div class="thermal-total-row"><span>الخصم</span><strong>−{{ number_format((float) $quotation->discount_amount, 2) }} {{ $thermal['currency'] }}</strong></div>
            @endif
            @if($delivery > 0)
                <div class="thermal-total-row"><span>التوصيل</span><strong>{{ number_format($delivery, 2) }} {{ $thermal['currency'] }}</strong></div>
            @endif
            @if($additional > 0)
                <div class="thermal-total-row"><span>مبالغ إضافية</span><strong>{{ number_format($additional, 2) }} {{ $thermal['currency'] }}</strong></div>
            @endif
            <div class="thermal-total-row thermal-total-row--grand"><span>الإجمالي النهائي</span><strong>{{ number_format((float) $quotation->total, 2) }} {{ $thermal['currency'] }}</strong></div>
        </section>

        @if(filled($quotation->notes))
            <section class="thermal-notes"><strong>ملاحظات</strong>{{ trim($quotation->notes) }}</section>
        @endif
        @include('thermal._footer')
    </main>
</body>
</html>
