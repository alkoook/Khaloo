@extends('layouts.app')
@section('title', 'فاتورة رقم '.$invoice->id)
@section('content')
@php
    $typeLabel = $invoice->isSale() ? 'فاتورة بيع' : ($invoice->isPurchase() ? 'فاتورة شراء' : 'مرتجع مبيعات');
    $paymentLabel = $invoice->remaining <= 0 ? 'مدفوعة بالكامل' : ((float) $invoice->amount_paid > 0 ? 'مدفوعة جزئيًا' : 'غير مدفوعة');
    $companyName = (string) $settings->get('company_name', $settings->get('site_name', 'Khaloo'));
    $companyPhone = $settings->get('company_phone');
    $companyAddress = $settings->get('company_address');
    $invoiceLogo = $settings->get('invoice_logo', $settings->get('logo'));
    $currencySymbol = (string) $settings->get('currency_symbol', 'ل.س');
@endphp

<div class="invoice-view page-shell invoice-document">
    <header class="workspace-card invoice-document__masthead">
        <div class="invoice-document__brand">
            @if($invoiceLogo)
                <img class="invoice-document__logo" src="{{ Storage::url($invoiceLogo) }}" alt="شعار {{ $companyName }}">
            @else
                <span class="invoice-document__logo invoice-document__logo--fallback">{{ mb_substr($companyName, 0, 1) }}</span>
            @endif
            <div>
                <span class="eyebrow">مستند مالي</span>
                <h1>{{ $companyName }}</h1>
                <p>{{ $typeLabel }}</p>
            </div>
        </div>

        <div class="invoice-document__header-content">
            <div class="invoice-document__header-title">
                <div><span class="eyebrow">تفاصيل الفاتورة</span><h2>{{ $typeLabel }}</h2></div>
                <span class="payment-state">{{ $paymentLabel }}</span>
            </div>
            <dl class="invoice-document__facts">
                <div><dt>رقم الفاتورة</dt><dd dir="ltr">#{{ $invoice->id }}</dd></div>
                <div><dt>التاريخ</dt><dd dir="ltr">{{ $invoice->created_at->format('Y-m-d H:i') }}</dd></div>
                @if(filled($companyPhone))<div><dt>هاتف المنشأة</dt><dd dir="ltr">{{ $companyPhone }}</dd></div>@endif
                @if(filled($companyAddress))<div class="invoice-document__fact-address"><dt>عنوان المنشأة</dt><dd>{{ $companyAddress }}</dd></div>@endif
            </dl>
        </div>

        <div class="invoice-actions invoice-document__actions">
            @if(!$invoice->isReturn())
                <a href="{{ route('invoices.receipt', $invoice) }}" target="_blank" class="print-button">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17h10v4H7zM5 17H3v-7h18v7h-2M17 12h1"/></svg><span>طباعة الإيصال</span>
                </a>
            @endif
            @if($invoice->isSale())
                <a href="{{ route('invoices.returns.create', ['invoice' => $invoice->id]) }}" class="secondary-button">إرجاع أصناف</a>
                @if($invoice->returns->isEmpty())<a href="{{ route('invoices.edit', ['invoice' => $invoice->id]) }}" class="secondary-button">تعديل</a>@endif
            @endif
            <a href="{{ $invoice->isPurchase() ? route('invoices.purchases.index') : route('invoices.sales.index') }}" class="ghost-button">العودة</a>
        </div>
    </header>

    <section class="invoice-overview invoice-document__overview">
        <article class="workspace-card invoice-party invoice-document__party">
            <span class="eyebrow">بيانات الطرف</span>
            <div class="party-identity"><span>{{ mb_substr($invoice->partner?->name ?? 'ن', 0, 1) }}</span><div><small>{{ $invoice->isPurchase() ? 'المورد' : 'العميل' }}</small><h2>{{ $invoice->partner?->name ?? 'معاملة نقدية' }}</h2></div></div>
            @if(filled($invoice->partner?->phone) || filled($invoice->partner?->address))
                <div class="party-contact">
                    @if(filled($invoice->partner?->phone))<div><span aria-hidden="true">☎</span><p><small>رقم الجوال</small><strong dir="ltr">{{ $invoice->partner->phone }}</strong></p></div>@endif
                    @if(filled($invoice->partner?->address))<div class="party-address"><span aria-hidden="true">⌖</span><p><small>العنوان</small><strong>{{ $invoice->partner->address }}</strong></p></div>@endif
                </div>
            @endif
            @if($invoice->isReturn() && $invoice->original_invoice_id)<a href="{{ route('invoices.sales.show', $invoice->original_invoice_id) }}">مرتبطة بالفاتورة #{{ $invoice->original_invoice_id }}</a>@endif
        </article>

        <div class="invoice-money-grid invoice-document__summary">
            <article><small>الإجمالي</small><strong>{{ number_format((float) $invoice->total, 2) }} <em>{{ $currencySymbol }}</em></strong></article>
            <article class="paid"><small>المدفوع</small><strong>{{ number_format((float) $invoice->amount_paid, 2) }} <em>{{ $currencySymbol }}</em></strong></article>
            <article class="remaining"><small>المتبقي</small><strong>{{ number_format((float) $invoice->remaining, 2) }} <em>{{ $currencySymbol }}</em></strong></article>
            @unless($invoice->isReturn())<article><small>رصيد الطرف الحالي</small><strong>{{ number_format((float) $currentPartnerBalance, 2) }} <em>{{ $currencySymbol }}</em></strong></article>@endunless
        </div>
    </section>

    <section class="workspace-card invoice-lines-view invoice-document__lines">
        <div class="card-heading"><div><span class="eyebrow">تفاصيل المستند</span><h2>بنود الفاتورة</h2><p>{{ $invoice->lines->count() }} صنف · {{ number_format((float) $invoice->lines->sum('quantity')) }} وحدة</p></div><strong class="invoice-document__lines-total">{{ number_format((float) $invoice->total, 2) }} {{ $currencySymbol }}</strong></div>
        <div class="table-shell"><table><thead><tr><th>الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>إجمالي السطر</th></tr></thead><tbody>@foreach($invoice->lines as $line)<tr><td><strong>{{ $line->product_name }}</strong><small>رقم المنتج: {{ $line->product_id ?: 'غير متوفر' }}</small></td><td>{{ number_format((float) $line->quantity) }}</td><td>{{ number_format((float) $line->unit_price, 2) }}</td><td><strong>{{ number_format((float) $line->line_total, 2) }}</strong></td></tr>@endforeach</tbody></table></div>
    </section>

    @if($invoice->isSale())
        <section class="invoice-foot-grid">
            @if($invoice->returns->isNotEmpty())<article class="workspace-card"><div class="card-heading"><div><h2>المرتجعات المرتبطة</h2><p>حركات خفّضت قيمة الفاتورة الأصلية</p></div></div>@foreach($invoice->returns as $return)<a class="return-row" href="{{ route('invoices.sales.show', $return) }}"><span>مرتجع #{{ $return->id }} · {{ $return->created_at->format('Y-m-d') }}</span><strong>{{ number_format((float) $return->total, 2) }} {{ $currencySymbol }}</strong></a>@endforeach</article>@endif
            <article class="workspace-card profit-card"><small>صافي ربح الفاتورة</small><strong>{{ number_format($invoice->lines->sum(fn($line) => (float) $line->quantity * ((float) $line->unit_price - (float) $line->purchase_price)), 2) }} <em>{{ $currencySymbol }}</em></strong><p>محسوب من أسعار التكلفة المحفوظة مع البنود.</p></article>
        </section>
    @endif
</div>
@endsection
