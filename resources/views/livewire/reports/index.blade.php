<div class="module-page">
    <div class="page-heading"><div><span class="eyebrow">مركز القرار</span><h1>منشئ التقارير</h1><p>تقارير عربية مفصلة، بسياق واضح وأوراق مستقلة للملخص والبنود والحركات.</p></div></div>
    @if(session('success'))<div class="notice success">{{ session('success') }}</div>@endif
    <div class="report-layout">
        <section class="workspace-card report-builder">
            <div class="card-heading"><div><h2>تكوين التقرير</h2><p>اختر نوع التقرير والفترة والفلاتر المناسبة ثم نزّل ملف إكسل منسقًا.</p></div><span class="xlsx-badge">إكسل</span></div>
            <div class="report-types">@foreach($types as $value=>$label)<button type="button" wire:click="$set('type','{{ $value }}')" class="{{ $type===$value?'active':'' }}"><span>{{ str_pad($loop->iteration,2,'0',STR_PAD_LEFT) }}</span>{{ $label }}</button>@endforeach</div>
            <div class="form-grid">
                <label>من تاريخ<input type="date" wire:model.live="from" @disabled(in_array($type, ['inventory', 'partners']))></label><label>إلى تاريخ<input type="date" wire:model.live="to" @disabled(in_array($type, ['inventory', 'partners']))></label>
                <label>الطرف<select wire:model.live="partner_id" @disabled(in_array($type, ['inventory', 'expenses', 'notifications']))><option value="">كل الأطراف</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }} — {{ $partner->type==='customer'?'عميل':'مورد' }}</option>@endforeach</select></label>
                <label>التصنيف<select wire:model.live="category_id" @disabled(in_array($type, ['partners', 'payments', 'expenses', 'notifications']))><option value="">كل التصنيفات</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
            </div>
            @error('to')<small class="field-error">{{ $message }}</small>@enderror
            <button type="button" class="primary-button export-button" wire:click="download" wire:loading.attr="disabled"><span>↓</span> إنشاء تقرير {{ $types[$type] }} في الخلفية</button>
        </section>
        <aside class="workspace-card report-preview">
            <span class="eyebrow">معاينة التقرير</span><h2>{{ $preview['title'] }}</h2>
            <div class="preview-metric"><small>عدد السجلات</small><strong>{{ $preview['count'] === null ? 'يُحسب في الخلفية' : number_format($preview['count']) }}</strong></div>
            <div class="preview-metric"><small>الإجمالي</small><strong>{{ $preview['total'] === null ? 'غير مطبق' : number_format($preview['total'],2) }}</strong></div>
            <div class="applied-filters"><strong>الفلاتر المطبقة</strong>@foreach($preview['filters'] as $label=>$value)<div><span>{{ $label }}</span><b>{{ $value }}</b></div>@endforeach</div>
            <p>يتضمن الملف اسم التقرير والمنشأة ووقت الإنشاء والفلاتر المطبقة، مع اتجاه عربي وتجميد للرؤوس وصفوف قابلة للفرز.</p>
        </aside>
    </div>
    <section class="workspace-card statement-builder"><div><span class="eyebrow">كشف الحساب</span><h2>كشف حساب عميل أو مورد</h2><p>اطلب ملف إكسل في الخلفية، أو أرسل ملخصًا والحركات الأخيرة إلى تيليغرام كرسالة نصية.</p></div><select wire:model="partner_id"><option value="">اختر الطرف</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}</option>@endforeach</select><button type="button" class="secondary-button" wire:click="downloadStatement" wire:loading.attr="disabled">إنشاء كشف إكسل</button><button type="button" class="primary-button" wire:click="sendStatement">إرسال رسالة إلى تيليغرام</button>@error('partner_id')<small class="field-error">{{ $message }}</small>@enderror @error('telegram')<small class="field-error">{{ $message }}</small>@enderror</section>
    <section class="workspace-card report-queue" wire:poll.5s aria-live="polite">
        <div class="card-heading"><div><h2>ملفات التقارير</h2><p>تابع حالة التقارير المطلوبة وحمّل الملفات الجاهزة من هنا.</p></div></div>
        @forelse($exports as $export)
            <div class="report-queue-row" wire:key="report-export-{{ $export->id }}">
                <div><strong>{{ $export->title }}</strong><small>{{ $export->created_at->format('Y-m-d H:i') }}</small></div>
                @if($export->status === 'ready')<a class="secondary-button" href="{{ route('reports.exports.download', $export) }}">تنزيل إكسل</a>
                @elseif($export->status === 'failed')<span class="report-queue-status failed">تعذر إنشاء الملف؛ يمكن طلبه مجددًا</span>
                @else<span class="report-queue-status">{{ $export->status === 'processing' ? 'قيد الإنشاء' : 'بانتظار المعالجة' }}</span>@endif
            </div>
        @empty<div class="empty-state">لم تطلب أي تقرير بعد.</div>@endforelse
    </section>
</div>
