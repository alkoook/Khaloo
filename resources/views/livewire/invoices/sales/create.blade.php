<div class="module-page" x-data="{activeResult:0,resultsOpen:true}" @focus-product-search.window="$nextTick(()=>$refs.productSearch?.focus())">
    <div class="page-heading"><div><span class="eyebrow">مكتب المبيعات السريع</span><h1>فاتورة بيع جديدة</h1><p>أضف منتجات المتجر ثم أي مبالغ توصيل أو خانات إضافية بصورة مستقلة.</p></div><a href="{{ route('invoices.sales.index') }}" wire:navigate class="secondary-button">العودة للفواتير</a></div>
    <section class="workspace-card invoice-editor" x-on:keydown.enter.prevent.stop>
        <div class="invoice-section-title"><div><span>01</span><strong>بيانات الفاتورة والطرف</strong></div><small>رقم الفاتورة والتاريخ يُنشآن عند الحفظ</small></div>
        <div class="form-grid"><label>العميل — اختياري<select wire:model="partner_id"><option value="">عميل نقدي غير مسجل</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select>@error('partner_id')<small>{{ $message }}</small>@enderror</label><div class="invoice-hint"><strong>حالة الدفع والذمة</strong><small>يلزم اختيار عميل إذا بقي مبلغ مستحق.</small></div></div>

        <div class="invoice-section-title"><div><span>02</span><strong>بنود البضاعة</strong></div><small>هذه البنود فقط تؤثر في المخزون والمبيعات والربح</small></div>
        <div class="product-picker"><label>إضافة منتج بالاسم أو الرقم أو التصنيف</label><input x-ref="productSearch" wire:model.live.debounce.220ms="productSearch" placeholder="اكتب للبحث…" autocomplete="off" @input="activeResult=0;resultsOpen=true" @keydown.arrow-down.prevent.stop="activeResult=Math.min(activeResult+1,Math.max(0,$refs.productResults?.querySelectorAll('button').length-1))" @keydown.arrow-up.prevent.stop="activeResult=Math.max(activeResult-1,0)" @keydown.enter.prevent.stop="if(resultsOpen){$refs.productResults?.querySelectorAll('button')[activeResult]?.click()}" @keydown.escape.prevent.stop="resultsOpen=false;activeResult=0;$nextTick(()=>$refs.productSearch.focus())">
            @if($productSearch!=='')<div x-ref="productResults" x-show="resultsOpen" class="picker-results">@forelse($productResults as $product)<button type="button" wire:click="selectProduct({{ $product->id }})" @mouseenter="activeResult={{ $loop->index }}" :class="activeResult==={{ $loop->index }}&&'is-keyboard-active'"><strong>{{ $product->name }}</strong><small>#{{ $product->id }} · {{ $product->category?->name }} · متوفر {{ $product->quantity }}</small><b>{{ number_format((float)$product->sale_price,2) }}</b></button>@empty<div class="empty-result">لا توجد نتائج مطابقة</div>@endforelse</div>@endif
        </div>
        <div class="line-cards">@forelse($lines as $index=>$line)<article wire:key="sale-line-{{ $line['product_id'] }}"><div><strong>{{ $line['product_name'] }}</strong><small>المتوفر {{ $line['available'] }}</small></div><label>الكمية<input data-invoice-qty="{{ $index }}" type="number" min="1" wire:model.live="lines.{{ $index }}.quantity"></label><label>سعر البيع<input type="number" min="0" step="0.01" wire:model.live="lines.{{ $index }}.unit_price"></label><b>{{ number_format((int)$line['quantity']*(float)$line['unit_price'],2) }}</b><button type="button" class="icon-danger" wire:click="removeLine({{ $index }})">×</button></article>@empty<div class="empty-state"><span>＋</span><strong>ابدأ بإضافة منتج</strong><small>تظهر أفضل 15 نتيجة بحث فقط.</small></div>@endforelse</div>
        @error('lines')<small class="field-error">{{ $message }}</small>@enderror

        <div class="invoice-section-title"><div><span>03</span><strong>خانات إضافية</strong></div><small>مبالغ مستردة من العميل فقط؛ لا تدخل في المخزون أو المبيعات أو الربح</small></div>
        <div class="additional-lines">
            @foreach($additionalLines as $index=>$line)
                <article wire:key="additional-line-{{ $index }}">
                    <label>اسم الغرض<input wire:model.live="additionalLines.{{ $index }}.description" placeholder="مثال: غرض خاص للزبون">@error("additionalLines.$index.description")<small>{{ $message }}</small>@enderror</label>
                    <label>الكمية<input type="number" min="0.001" step="0.001" wire:model.live="additionalLines.{{ $index }}.quantity"></label>
                    <label>المبلغ الإفرادي<input type="number" min="0" step="0.01" wire:model.live="additionalLines.{{ $index }}.unit_amount"></label>
                    <label>المصدر / ملاحظة<input wire:model="additionalLines.{{ $index }}.source_note" placeholder="من محل أبو أحمد"></label>
                    <b>{{ number_format((float)($line['quantity']??0)*(float)($line['unit_amount']??0),2) }}</b>
                    <button type="button" class="icon-danger" wire:click="removeAdditionalLine({{ $index }})">×</button>
                </article>
            @endforeach
        </div>
        <button type="button" class="secondary-button" wire:click="addAdditionalLine">＋ إضافة خانة</button>

        <div class="invoice-section-title"><div><span>04</span><strong>الحسم والتوصيل والتحصيل</strong></div></div>
        <div class="form-grid invoice-discount">
            <label>نوع الحسم<select wire:model.live="discount_type"><option value="fixed">مبلغ ثابت</option><option value="percentage">نسبة مئوية</option></select></label>
            <label>قيمة الحسم<input type="number" min="0" step="0.01" wire:model.live="discount_value" placeholder="بدون حسم">@error('discount_value')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>التوصيل<select wire:model.live="hasDelivery"><option value="0">بدون توصيل</option><option value="1">مع توصيل</option></select></label>
            @if($hasDelivery)<label>مبلغ التوصيل<input type="number" min="0" step="0.01" wire:model.live="deliveryAmount">@error('deliveryAmount')<small>{{ $message }}</small>@enderror</label>@endif
            <div class="invoice-hint"><small>صافي البضاعة</small><strong>{{ number_format($this->total,2) }}</strong></div>
            @if($this->additionalTotal>0)<div class="invoice-hint"><small>الخانات الإضافية</small><strong>{{ number_format($this->additionalTotal,2) }}</strong></div>@endif
        </div>
        <div class="sticky-summary"><label>المدفوع<input type="number" min="0" step="0.01" wire:model.live="amount_paid" placeholder="أدخل المبلغ"></label><div><small>المتبقي</small><strong>{{ number_format(max(0,$this->customerTotal-(float)($amount_paid?:0)),2) }}</strong></div><div><small>الإجمالي المطلوب</small><strong>{{ number_format($this->customerTotal,2) }}</strong></div><button type="button" class="primary-button" wire:click="save" wire:loading.attr="disabled">حفظ فاتورة البيع</button></div>
        @error('amount_paid')<small class="field-error">{{ $message }}</small>@enderror
    </section>
</div>
