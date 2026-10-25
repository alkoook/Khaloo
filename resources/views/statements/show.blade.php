@extends('layouts.app')

@section('title', $partner->isCustomer() ? 'كشف حساب السيد' : 'كشف حساب المورد')

@section('content')
<div class="module-page statement-page" dir="rtl">
    <div class="page-heading">
        <div><span class="eyebrow">السجل المالي</span><h1>{{ $partner->isCustomer() ? 'كشف حساب السيد' : 'كشف حساب المورد' }}</h1><p>{{ $partner->name }} @if($partner->phone) — {{ $partner->phone }} @endif</p></div>
        <div class="row-actions">
            <a class="secondary-button" target="_blank" href="{{ route('statements.thermal', ['partner' => $partner, 'from' => $from, 'to' => $to, 'movement_type' => $movementType]) }}">طباعة حرارية</a>
            <form method="post" action="{{ route('statements.telegram', $partner) }}">@csrf<input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}"><input type="hidden" name="movement_type" value="{{ $movementType }}"><button class="primary-button">إرسال إلى تيليغرام</button></form>
        </div>
    </div>
    @if(session('success'))<div class="notice success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="notice error">{{ session('error') }}</div>@endif
    <section class="workspace-card">
        <form method="get" class="filter-bar statement-filters">
            <label>من تاريخ<input type="date" name="from" value="{{ $from }}"></label>
            <label>إلى تاريخ<input type="date" name="to" value="{{ $to }}"></label>
            <label>نوع الحركة<select name="movement_type"><option value="">كل الحركات</option><option value="invoice" @selected($movementType === 'invoice')>الفواتير</option><option value="settlement" @selected($movementType === 'settlement')>الدفعات والتسويات</option>@if($partner->isCustomer())<option value="return" @selected($movementType === 'return')>المرتجعات</option>@endif</select></label>
            <button class="secondary-button">تطبيق</button>
        </form>
        @error('to')<p class="field-error">{{ $message }}</p>@enderror
    </section>
    <section class="statement-summary">
        <article class="workspace-card"><small>الرصيد الافتتاحي</small><strong>{{ \App\Support\Decimal::format($statement['opening']) }}</strong></article>
        <article class="workspace-card"><small>إجمالي المدين</small><strong>{{ \App\Support\Decimal::format($statement['debit_total']) }}</strong></article>
        <article class="workspace-card"><small>إجمالي الدائن</small><strong>{{ \App\Support\Decimal::format($statement['credit_total']) }}</strong></article>
        <article class="workspace-card statement-closing"><small>الرصيد الختامي</small><strong>{{ \App\Support\Decimal::format($statement['closing']) }}</strong></article>
    </section>
    <section class="workspace-card">
        <div class="statement-meta"><span>الفترة: {{ $from ?: 'بداية السجل' }} — {{ $to ?: 'اليوم' }}</span><span>{{ $statement['rows']->count() }} حركة</span></div>
        <div class="table-shell"><table><thead><tr><th>التاريخ</th><th>نوع الحركة</th><th>المستند/المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead><tbody>
            @forelse($statement['rows'] as $row)<tr><td>{{ $row['date']->format('Y-m-d') }}</td><td>{{ $row['type'] }}</td><td>{{ $row['reference'] }} @if(isset($row['settlement_id']))<a class="table-action" href="{{ route('payments.register', ['edit_settlement' => $row['settlement_id']]) }}">تعديل</a>@endif</td><td>{{ $row['description'] }}</td><td>{{ \App\Support\Decimal::format($row['charge']) }}</td><td>{{ \App\Support\Decimal::format($row['payment']) }}</td><td><strong>{{ \App\Support\Decimal::format($row['balance']) }}</strong></td></tr>
            @empty<tr><td colspan="7"><div class="empty-state">لا توجد حركات مطابقة ضمن الفترة المحددة.</div></td></tr>@endforelse
        </tbody><tfoot><tr><th colspan="4">إجمالي الفترة</th><th>{{ \App\Support\Decimal::format($statement['debit_total']) }}</th><th>{{ \App\Support\Decimal::format($statement['credit_total']) }}</th><th>{{ \App\Support\Decimal::format($statement['closing']) }}</th></tr></tfoot></table></div>
    </section>
</div>
@endsection
