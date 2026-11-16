@extends('layouts.app')
@section('title', 'فاتورة رقم '.$invoice->id)
@section('content')
@php
    $typeLabel = $invoice->isSale() ? 'فاتورة مبيعات' : ($invoice->isPurchase() ? 'فاتورة مشتريات' : 'فاتورة مرتجع مبيعات');
    $partyLabel = $invoice->isPurchase() ? 'المورد' : 'السيد';
    $paymentLabel = $invoice->remaining <= 0 ? 'مدفوعة بالكامل' : ((float) $invoice->effective_paid > 0 ? 'مدفوعة جزئياً' : 'غير مدفوعة');
    $companyName = (string) $settings->get('company_name', $settings->get('site_name', 'Khaloo'));
    $companyPhones = $settings->invoicePhones();
    $companyAddress = $settings->get('company_address');
    $invoiceLogo = $settings->get('invoice_logo', $settings->get('logo'));
    $currency = (string) $settings->get('currency_symbol', 'ل.س');
@endphp
<div class="invoice-document">
    <nav class="invoice-document__toolbar" aria-label="إجراءات الفاتورة">
        @if(!$invoice->isCancelled())<a href="{{ route('invoices.receipt', $invoice) }}" target="_blank" class="print-button">طباعة / لقطة شاشة</a>@endif
        @if($invoice->isReturn() && !$invoice->isCancelled()) @can('update', $invoice)<a href="{{ route('invoices.returns.edit', $invoice) }}" class="secondary-button">تعديل المرتجع</a>@endcan @endif
        @if($invoice->isReturn() && !$invoice->isCancelled()) @can('delete', $invoice)<details class="invoice-cancel-action"><summary class="danger-button">إلغاء المرتجع</summary><form method="post" action="{{ route('invoices.returns.cancel', $invoice) }}">@csrf<label>سبب الإلغاء<textarea name="cancellation_reason" minlength="5" maxlength="1000" required></textarea></label>@error('cancellation_reason')<small class="field-error">{{ $message }}</small>@enderror<button class="danger-button">تأكيد إلغاء المرتجع</button></form></details>@endcan @endif
        @if($invoice->isSale() && !$invoice->isCancelled())
            <a href="{{ route('invoices.returns.create', ['invoice'=>$invoice->id]) }}" class="secondary-button">إرجاع أصناف</a>
            @can('update', $invoice)
                @if($editBlockReason)<button type="button" class="secondary-button" disabled title="{{ $editBlockReason }}">تعديل</button>@else<a href="{{ route('invoices.edit', $invoice) }}" class="secondary-button">تعديل</a>@endif
            @endcan
        @endif
        @if($invoice->isPurchase())
            @can('update', $invoice)
                @if($editBlockReason)<button type="button" class="secondary-button" disabled title="{{ $editBlockReason }}">تعديل</button>@else<a href="{{ route('invoices.edit', $invoice) }}" class="secondary-button">تعديل</a>@endif
            @endcan
            @can('delete', $invoice)
                @if($cancellationBlockReason)
                    <button type="button" class="danger-button" disabled title="{{ $cancellationBlockReason }}">إلغاء الفاتورة</button>
                @else
                    <details class="invoice-cancel-action"><summary class="danger-button">إلغاء الفاتورة</summary><form method="post" action="{{ route('invoices.purchases.cancel', $invoice) }}">@csrf<label>سبب الإلغاء<textarea name="cancellation_reason" minlength="5" maxlength="1000" required></textarea></label>@error('cancellation_reason')<small class="field-error">{{ $message }}</small>@enderror<button class="danger-button">تأكيد الإلغاء</button></form></details>
                @endif
            @endcan
        @endif
        <a href="{{ $invoice->isPurchase() ? route('invoices.purchases.index') : route('invoices.sales.index') }}" class="ghost-button">العودة</a>
    </nav>
    @if(($invoice->isPurchase() && ($editBlockReason || $cancellationBlockReason)) || ($invoice->isSale() && $editBlockReason))<p class="field-error invoice-action-reason">{{ $invoice->isSale() ? $editBlockReason : ($editBlockReason ?: $cancellationBlockReason) }}</p>@endif

    <article class="invoice-sheet">
        @if($invoice->isCancelled())<div class="invoice-cancelled"><strong>هذه الفاتورة ملغاة</strong><span>{{ $invoice->cancellation_reason }}</span></div>@endif
        <header class="invoice-sheet__header invoice-document__masthead">
            <div class="invoice-brand">
                @if($invoiceLogo)<img src="{{ Storage::url($invoiceLogo) }}" alt="شعار {{ $companyName }}">@else<span>{{ mb_substr($companyName,0,1) }}</span>@endif
                <div><p>مستند مالي</p><h1>{{ $companyName }}</h1>@if(filled($companyAddress))<small>{{ $companyAddress }}</small>@endif @if($companyPhones)<small dir="ltr">{{ implode(' / ', $companyPhones) }}</small>@endif</div>
            </div>
            <div class="invoice-title"><span>{{ $typeLabel }}</span><strong dir="ltr">#{{ $invoice->id }}</strong><small dir="ltr">{{ $invoice->accountingDate()->format($invoice->isReturn() && $invoice->return_date ? 'Y-m-d' : 'Y-m-d H:i') }}</small><em>{{ $paymentLabel }}</em></div>
        </header>

        <section class="invoice-party-strip">
            <div><small>{{ $partyLabel }}</small><strong>{{ $invoice->partner?->name ?? 'معاملة نقدية' }}</strong></div>
            @if(filled($invoice->partner?->phone))<div class="party-contact"><small>الهاتف</small><strong dir="ltr">{{ $invoice->partner->phone }}</strong></div>@endif
            @if(filled($invoice->partner?->address))<div><small>العنوان</small><strong>{{ $invoice->partner->address }}</strong></div>@endif
            @if($invoice->isReturn() && $invoice->original_invoice_id)<div><small>الفاتورة الأصلية</small><a href="{{ route('invoices.sales.show',$invoice->original_invoice_id) }}">#{{ $invoice->original_invoice_id }}</a></div>@endif
        </section>

        <section class="invoice-items">
            <table><thead><tr><th class="seq">#</th><th>المادة</th><th class="num">الكمية</th><th class="num">السعر</th><th class="num">الخصم</th><th class="num">الإجمالي</th></tr></thead>
            <tbody>@foreach($invoice->lines as $line)<tr><td class="seq">{{ $loop->iteration }}</td><td><strong>{{ $line->product_name }}</strong></td><td class="num">{{ \App\Support\Decimal::displayQuantity($line->quantity) }}</td><td class="num">{{ number_format((float)$line->unit_price,2) }}</td><td class="num">{{ (float)$line->discount_amount > 0 ? number_format((float)$line->discount_amount,2) : '—' }}</td><td class="num total">{{ number_format((float)($line->net_line_total ?? $line->line_total),2) }}</td></tr>@endforeach</tbody></table>
        </section>

        @if($invoice->isSale() && $invoice->additionalLines->isNotEmpty())
            <section class="invoice-items invoice-additional-items"><h2>خانات إضافية <small>مبالغ مستردة لا ترتبط بالمخزون</small></h2><table><thead><tr><th class="seq">#</th><th>اسم الغرض</th><th class="num">الكمية</th><th class="num">المبلغ</th><th>المصدر / ملاحظة</th><th class="num">الإجمالي</th></tr></thead><tbody>@foreach($invoice->additionalLines as $line)<tr><td class="seq">{{ $loop->iteration }}</td><td><strong>{{ $line->description }}</strong></td><td class="num">{{ number_format((float)$line->quantity,3) }}</td><td class="num">{{ number_format((float)$line->unit_amount,2) }}</td><td>{{ $line->source_note ?: '—' }}</td><td class="num total">{{ number_format((float)$line->row_total,2) }}</td></tr>@endforeach</tbody></table></section>
        @endif

        <footer class="invoice-sheet__footer">
            <div class="invoice-note"><strong>{{ $invoice->lines->count() }} صنف</strong><span>{{ \App\Support\Decimal::displayQuantity(\App\Support\Decimal::sum($invoice->lines->pluck('quantity'), 3)) }} وحدة</span>@if($invoice->isSale() && $invoice->returns->isNotEmpty())<span>{{ $invoice->returns->count() }} مرتجع مرتبط</span>@endif</div>
            <dl class="invoice-totals">
                <div><dt>المجموع قبل الخصم</dt><dd>{{ number_format((float)($invoice->subtotal ?: $invoice->lines->sum('line_total')),2) }} {{ $currency }}</dd></div>
                @if((float)$invoice->discount_amount > 0)<div><dt>الخصم</dt><dd>- {{ number_format((float)$invoice->discount_amount,2) }} {{ $currency }}</dd></div>@endif
                <div><dt>صافي البضاعة</dt><dd>{{ number_format((float)$invoice->total,2) }} {{ $currency }}</dd></div>
                @if((float)$invoice->delivery_amount > 0)<div><dt>التوصيل</dt><dd>{{ number_format((float)$invoice->delivery_amount,2) }} {{ $currency }}</dd></div>@endif
                @if((float)$invoice->additional_total > 0)<div><dt>الخانات الإضافية</dt><dd>{{ number_format((float)$invoice->additional_total,2) }} {{ $currency }}</dd></div>@endif
                <div class="grand"><dt>الإجمالي المطلوب</dt><dd>{{ number_format($invoice->collectibleTotal(),2) }} {{ $currency }}</dd></div>
                @if((float)$invoice->effective_paid > 0)<div><dt>{{ $invoice->isReturn() ? 'المسترد نقداً' : 'إجمالي المدفوع' }}</dt><dd>{{ number_format((float)$invoice->effective_paid,2) }} {{ $currency }}</dd></div>@endif
                @if((float)$invoice->remaining > 0)<div><dt>{{ $invoice->isReturn() ? 'المخصوم من الذمة' : 'المتبقي' }}</dt><dd>{{ number_format((float)$invoice->remaining,2) }} {{ $currency }}</dd></div>@endif
            </dl>
        </footer>
    </article>
    @if($profitability !== null)
        <section class="workspace-card financial-card invoice-profitability print:hidden" aria-label="ربحية الفاتورة">
            <h2>ربحية الفاتورة</h2>
            @if(!$profitability['active'])<p>الفاتورة ملغاة؛ لا تدخل في الربحية.</p>@endif
            <dl>
                <div><dt>صافي مبيعات البضاعة</dt><dd>{{ \App\Support\Decimal::format($profitability['net_merchandise']) }} {{ $currency }}</dd></div>
                @if($profitability['delivery'] > 0)<div><dt>إيراد التوصيل</dt><dd>{{ \App\Support\Decimal::format($profitability['delivery']) }} {{ $currency }}</dd></div>@endif
                <div><dt>تكلفة البضاعة المباعة</dt><dd>{{ $profitability['cogs'] === null ? 'غير موثوقة' : \App\Support\Decimal::format($profitability['cogs']) }}</dd></div>
                <div class="emphasis"><dt>مجمل ربح الفاتورة</dt><dd>{{ $profitability['profit'] === null ? 'غير موثوق' : \App\Support\Decimal::format($profitability['profit']) }}</dd></div>
                <div><dt>هامش الربح</dt><dd>{{ $profitability['margin'] === null ? '—' : $profitability['margin'].'٪' }}</dd></div>
            </dl>
            <small>التكلفة التاريخية المسجلة وقت البيع؛ بعد عكس المرتجعات. لا تشمل الخانات الإضافية أو المصاريف العامة.</small>
        </section>
    @endif
</div>
@endsection
