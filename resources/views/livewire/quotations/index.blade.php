<div class="module-page" x-data="{ activeResult: 0 }" @focus-quotation-quantity.window="$nextTick(() => document.querySelector(`[data-quote-qty='${$event.detail.index}']`)?.focus())">
    <div class="page-heading"><div><span class="eyebrow">إدارة عروض الأسعار</span><h1>عروض الأسعار</h1><p>عروض مستقلة قابلة للإدارة والتحويل، ولا تؤثر على المخزون أو الذمم قبل التحويل.</p></div><button type="button" class="primary-button" wire:click="create">＋ عرض جديد</button></div>
    @if(session('success')) <div class="notice success">{{ session('success') }}</div> @endif
    @error('delete') <div class="notice danger">{{ $message }}</div> @enderror

    @if($showForm)
    <section class="workspace-card quotation-editor" wire:key="quotation-editor" x-on:keydown.enter.prevent>
        <div class="card-heading"><div><h2>{{ $editingId ? 'تعديل العرض' : 'إنشاء عرض جديد' }}</h2><p>أدخل بيانات الطرف والبنود ثم احفظ عرض السعر.</p></div><button type="button" class="ghost-button" wire:click="$set('showForm', false)">إغلاق</button></div>
        <div class="form-grid">
            <label>العميل المسجل <select wire:model="partner_id"><option value="">عميل غير مسجل</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select></label>
            <label>اسم العميل <input wire:model="customer_name" maxlength="255"></label>
            <label>الهاتف <input wire:model="customer_phone" maxlength="40"></label>
            <label>تاريخ العرض <input type="date" wire:model="quotation_date"></label>
            <label>صالح حتى <input type="date" wire:model="expires_at"></label>
        </div>
        <div class="product-picker">
            <label>ابحث بالاسم أو رقم المنتج أو التصنيف</label>
            <input wire:model.live.debounce.250ms="productSearch" autocomplete="off" placeholder="ابدأ الكتابة…" @keydown.arrow-down.prevent="activeResult++" @keydown.arrow-up.prevent="activeResult = Math.max(0, activeResult - 1)">
            @if($productSearch !== '')<div class="picker-results">@forelse($productResults as $product)<button type="button" wire:click="selectProduct({{ $product->id }})"><strong>{{ $product->name }}</strong><small>#{{ $product->id }} · {{ $product->category?->name }} · مخزون {{ $product->quantity }}</small><b>{{ number_format((float)$product->sale_price,2) }}</b></button>@empty<div class="empty-result">لا توجد نتائج</div>@endforelse</div>@endif
        </div>
        <div class="line-cards">@foreach($lines as $index=>$line)<article wire:key="quote-line-{{ $line['product_id'] }}"><div><strong>{{ $line['product_name'] }}</strong><small>#{{ $line['product_id'] }}</small></div><label>الكمية<input data-quote-qty="{{ $index }}" type="number" min="1" wire:model.live="lines.{{ $index }}.quantity"></label><label>السعر<input type="number" min="0" step="0.01" wire:model.live="lines.{{ $index }}.unit_price"></label><b>{{ number_format((float)$line['quantity']*(float)$line['unit_price'],2) }}</b><button type="button" class="icon-danger" wire:click="removeLine({{ $index }})">×</button></article>@endforeach</div>
        @error('lines')<small class="field-error">{{ $message }}</small>@enderror @error('stock')<small class="field-error">{{ $message }}</small>@enderror
        <div class="sticky-summary"><label>الحسم <input type="number" min="0" step="0.01" wire:model.live="discount"></label><label class="wide">ملاحظات <input wire:model="notes"></label><div><small>الإجمالي</small><strong>{{ number_format($this->total,2) }}</strong></div><button type="button" class="primary-button" wire:click="save" wire:loading.attr="disabled">حفظ العرض</button></div>
    </section>
    @endif

    <section class="workspace-card">
        <div class="filter-bar"><input wire:model.live.debounce.300ms="search" placeholder="بحث برقم العرض أو العميل"><input type="number" min="0" step="0.01" wire:model="amountPaid" placeholder="المدفوع عند التحويل"></div>
        <div class="table-shell"><table><thead><tr><th>الرقم</th><th>العميل</th><th>التاريخ</th><th>العناصر</th><th>الإجمالي</th><th>الإجراءات</th></tr></thead><tbody>@forelse($quotations as $quote)<tr><td><strong>{{ $quote->number }}</strong></td><td>{{ $quote->display_customer }}</td><td>{{ $quote->quotation_date->format('Y-m-d') }}</td><td>{{ $quote->lines_count }}</td><td>{{ number_format((float)$quote->total,2) }}</td><td><div class="row-actions quote-actions"><a class="table-action quote-view" href="{{ route('quotations.print',$quote) }}" target="_blank" title="معاينة عرض السعر وطباعته"><span aria-hidden="true">▣</span><b>عرض وطباعة</b></a>@if(!$quote->converted_invoice_id)<button type="button" class="table-action quote-edit" wire:click="edit({{ $quote->id }})" title="تعديل عرض السعر"><span aria-hidden="true">✎</span><b>تعديل</b></button><button type="button" class="table-action quote-convert" data-confirm="سيتم فحص المخزون ثم تحويل العرض إلى فاتورة مبيعات. هل تريد المتابعة؟" wire:click="convert({{ $quote->id }})" title="تحويل إلى فاتورة"><span aria-hidden="true">⇧</span><b>تحويل إلى فاتورة</b></button><button type="button" class="table-action is-danger" data-confirm="هل تريد حذف عرض السعر {{ $quote->number }}؟ لن يتأثر المخزون أو حساب العميل." wire:click="delete({{ $quote->id }})" title="حذف عرض السعر"><span aria-hidden="true">⌫</span><b>حذف</b></button>@else<a class="table-action quote-view" href="{{ route('invoices.sales.show',$quote->converted_invoice_id) }}"><span aria-hidden="true">▣</span><b>عرض الفاتورة</b></a>@endif</div></td></tr>@empty<tr><td colspan="6"><div class="empty-state">لا توجد عروض أسعار بعد.</div></td></tr>@endforelse</tbody></table></div>
        {{ $quotations->links() }}
    </section>
</div>
