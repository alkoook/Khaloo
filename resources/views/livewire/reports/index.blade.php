<div class="module-page">
    <div class="page-heading"><div><span class="eyebrow">مركز القرار</span><h1>منشئ التقارير</h1><p>تقارير عربية مفصلة، بسياق واضح وأوراق مستقلة للملخص والبنود والحركات.</p></div></div>
    @if(session('success'))<div class="notice success">{{ session('success') }}</div>@endif
    <div class="report-layout">
        <section class="workspace-card report-builder">
            <div class="card-heading"><div><h2>تكوين التقرير</h2><p>اختر نوع التقرير والفترة والفلاتر المناسبة ثم نزّل ملف إكسل منسقًا.</p></div><span class="xlsx-badge">إكسل</span></div>
            <div class="report-types">@foreach($types as $value=>$label)<button type="button" wire:click="$set('type','{{ $value }}')" class="{{ $type===$value?'active':'' }}"><span>{{ str_pad($loop->iteration,2,'0',STR_PAD_LEFT) }}</span>{{ $label }}</button>@endforeach</div>
            <div class="form-grid">
                <label>من تاريخ<input type="date" wire:model.live="from"></label><label>إلى تاريخ<input type="date" wire:model.live="to"></label>
                <label>الطرف<select wire:model.live="partner_id"><option value="">كل الأطراف</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }} — {{ $partner->type==='customer'?'عميل':'مورد' }}</option>@endforeach</select></label>
                <label>التصنيف<select wire:model.live="category_id"><option value="">كل التصنيفات</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
            </div>
            @error('to')<small class="field-error">{{ $message }}</small>@enderror
            <button type="button" class="primary-button export-button" wire:click="download" wire:loading.attr="disabled"><span>↓</span> تنزيل تقرير {{ $types[$type] }}</button>
        </section>
        <aside class="workspace-card report-preview">
            <span class="eyebrow">معاينة التقرير</span><h2>{{ $preview['title'] }}</h2>
            <div class="preview-metric"><small>عدد السجلات</small><strong>{{ number_format($preview['count']) }}</strong></div>
            <div class="preview-metric"><small>الإجمالي</small><strong>{{ $preview['total'] === null ? 'غير مطبق' : number_format($preview['total'],2) }}</strong></div>
            <div class="applied-filters"><strong>الفلاتر المطبقة</strong>@foreach($preview['filters'] as $label=>$value)<div><span>{{ $label }}</span><b>{{ $value }}</b></div>@endforeach</div>
            <p>يتضمن الملف اسم التقرير والمنشأة ووقت الإنشاء والفلاتر المطبقة، مع اتجاه عربي وتجميد للرؤوس وصفوف قابلة للفرز.</p>
        </aside>
    </div>
    <section class="workspace-card statement-builder"><div><span class="eyebrow">كشف الحساب</span><h2>كشف حساب عميل أو مورد</h2><p>نزّل ملف إكسل داخل النظام، أو أرسل ملخصًا والحركات الأخيرة إلى تيليغرام كرسالة نصية.</p></div><select wire:model="partner_id"><option value="">اختر الطرف</option>@foreach($partners as $partner)<option value="{{ $partner->id }}">{{ $partner->name }}</option>@endforeach</select><button type="button" class="secondary-button" wire:click="downloadStatement">تنزيل كشف إكسل</button><button type="button" class="primary-button" wire:click="sendStatement">إرسال رسالة إلى تيليغرام</button>@error('partner_id')<small class="field-error">{{ $message }}</small>@enderror @error('telegram')<small class="field-error">{{ $message }}</small>@enderror</section>
</div>
