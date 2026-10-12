<div x-data="{activeResult:0,resultsOpen:true}" @focus-product-search.window="$nextTick(()=>$refs.productSearch?.focus())">
    <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold" style="color: var(--app-text)">تعديل فاتورة #{{ $invoice->id }}</h1>
            <p class="text-sm mt-1" style="color: var(--app-muted)">تعديل الكميات والأسعار وسيتم تحديث المخزون تلقائياً بالفرق.</p>
        </div>
        <a href="{{ $invoice->isSale() ? route('invoices.sales.index') : route('invoices.purchases.index') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium transition-colors border rounded-xl" style="background: var(--app-bg); color: var(--app-text); border-color: var(--app-border);">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="m15 18-6-6 6-6"/></svg>
            إلغاء والتراجع
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
                <div class="p-6">
                    <h2 class="text-lg font-semibold mb-4" style="color: var(--app-text)">تفاصيل المنتجات</h2>
                    
                    <div class="product-picker"><label>إضافة منتج بالاسم أو الرقم أو التصنيف</label><input x-ref="productSearch" wire:model.live.debounce.220ms="productSearch" placeholder="اكتب للبحث…" autocomplete="off" @input="activeResult=0;resultsOpen=true" @keydown.arrow-down.prevent.stop="activeResult=Math.min(activeResult+1,Math.max(0,$refs.productResults?.querySelectorAll('button:not(:disabled)').length-1))" @keydown.arrow-up.prevent.stop="activeResult=Math.max(activeResult-1,0)" @keydown.enter.prevent.stop="if(resultsOpen){$refs.productResults?.querySelectorAll('button:not(:disabled)')[activeResult]?.click()}" @keydown.escape.prevent.stop="resultsOpen=false;activeResult=0;$nextTick(()=>$refs.productSearch.focus())">@if($productSearch!=='')<div x-ref="productResults" x-show="resultsOpen" class="picker-results">@forelse($productResults as $product)@php $available = $product->available_for_invoice; @endphp<button type="button" wire:click="selectProduct({{ $product->id }})" @disabled($invoice->isSale() && $available <= 0) @mouseenter="if(!$el.disabled) activeResult=Array.from($refs.productResults.querySelectorAll('button:not(:disabled)')).indexOf($el)" :class="activeResult===Array.from($refs.productResults?.querySelectorAll('button:not(:disabled)')??[]).indexOf($el)&&!$el.disabled&&'is-keyboard-active'"><strong>{{ $product->name }}</strong><small>#{{ $product->id }} · {{ $product->category?->name }}@if($invoice->isSale()) · {{ $available > 0 ? 'المتاح للتعديل: '.$available : 'نفد المخزون · المخزون: 0' }}@endif</small><b>{{ number_format((float) ($invoice->isSale() ? $product->sale_price : $product->purchase_price), 2) }}</b></button>@empty<div class="empty-result">لا توجد نتائج مطابقة</div>@endforelse</div>@endif</div>
                    <div class="space-y-4">
                        @foreach($lines as $index => $line)
                            <div class="flex flex-col sm:flex-row gap-4 p-4 rounded-xl border relative" style="background: var(--app-bg); border-color: var(--app-border);">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">المنتج</label>
                                    <select wire:model.live="lines.{{ $index }}.product_id" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
                                        <option value="">اختر المنتج...</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product['id'] }}" @disabled($invoice->isSale() && $product['available_for_invoice'] <= 0)>{{ $product['name'] }}{{ $invoice->isSale() ? ($product['available_for_invoice'] > 0 ? ' — المتاح: '.$product['available_for_invoice'] : ' — نفد المخزون') : '' }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$index}.product_id") <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div class="w-full sm:w-24">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">الكمية</label>
                                    <input type="number" wire:model="lines.{{ $index }}.quantity" min="0.001" step="0.001" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
                                </div>
                                <div class="w-full sm:w-32">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">السعر الإفرادي</label>
                                    <input type="number" step="0.01" wire:model="lines.{{ $index }}.unit_price" min="0" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
                                </div>
                                
                                @if(!$invoice->isSale())
                                    <div class="w-full sm:w-32">
                                        <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">سعر المبيع</label>
                                        <input type="number" step="0.01" wire:model="lines.{{ $index }}.sale_price" min="0" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
                                    </div>
                                @endif
                                
                                @if(count($lines) > 1)
                                    <button type="button" wire:click="removeLine({{ $index }})" class="absolute top-2 left-2 p-1 text-red-500 hover:text-red-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    
                    <button type="button" wire:click="addLine" class="mt-4 px-4 py-2 text-sm font-medium rounded-xl text-white" style="background: var(--app-primary);">
                        + إضافة منتج
                    </button>
                </div>
            </div>
            @if($invoice->isSale())
                <div class="rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
                    <div class="p-6"><h2 class="text-lg font-semibold">خانات إضافية</h2><p class="text-sm mt-1 mb-4" style="color:var(--app-muted)">مبالغ مستردة من العميل ولا تؤثر في المخزون أو مبيعات المنتجات أو الربح.</p>
                        <div class="additional-lines">@foreach($additionalLines as $index=>$line)<article wire:key="edit-additional-{{ $index }}"><label>اسم الغرض<input wire:model.live="additionalLines.{{ $index }}.description"></label><label>الكمية<input type="number" min="0.001" step="0.001" wire:model.live="additionalLines.{{ $index }}.quantity"></label><label>المبلغ الإفرادي<input type="number" min="0" step="0.01" wire:model.live="additionalLines.{{ $index }}.unit_amount"></label><label>المصدر / ملاحظة<input wire:model="additionalLines.{{ $index }}.source_note"></label><b>{{ \App\Support\Decimal::format(\App\Support\Decimal::mul($line['quantity'] ?: 0, $line['unit_amount'] ?: 0)) }}</b><button type="button" class="icon-danger" wire:click="removeAdditionalLine({{ $index }})">×</button></article>@endforeach</div>
                        <button type="button" wire:click="addAdditionalLine" class="secondary-button">＋ إضافة خانة</button>
                    </div>
                </div>
            @endif
        </div>
        
        <div class="space-y-6">
            <div class="rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
                <div class="p-6 space-y-5">
                    <h2 class="text-lg font-semibold" style="color: var(--app-text)">تفاصيل مالية</h2>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">المبلغ المدفوع</label>
                        <input type="number" step="0.01" wire:model="amountPaid" min="0" @readonly(!$invoice->created_at->isToday()) placeholder="غير مدفوع" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                        @if(!$invoice->created_at->isToday())<small>دفعة إنشاء الفاتورة محفوظة؛ سجّل الدفعات اللاحقة من سجل الدفعات بتاريخها الفعلي.</small>@endif
                        @error('amountPaid') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div><label class="block text-sm font-medium mb-1">نوع الحسم</label><select wire:model.live="discountType" class="w-full"><option value="fixed">مبلغ ثابت</option><option value="percentage">نسبة مئوية</option></select></div>
                    <div><label class="block text-sm font-medium mb-1">قيمة الحسم</label><input type="number" min="0" step="0.01" wire:model.live="discountValue" placeholder="بدون حسم">@error('discountValue')<small class="field-error">{{ $message }}</small>@enderror</div>
                    @if($invoice->isSale())
                        <div><label class="block text-sm font-medium mb-1">التوصيل</label><select wire:model.live="hasDelivery" class="w-full"><option value="0">بدون توصيل</option><option value="1">مع توصيل</option></select></div>
                        @if($hasDelivery)<div><label class="block text-sm font-medium mb-1">مبلغ التوصيل</label><input type="number" min="0" step="0.01" wire:model.live="deliveryAmount">@error('deliveryAmount')<small class="field-error">{{ $message }}</small>@enderror</div>@endif
                    @endif
                    
                    
                    <div class="pt-4 border-t space-y-3" style="border-color: var(--app-border);">
                        <div class="flex justify-between text-sm">
                            <span style="color: var(--app-muted)">المجموع قبل الحسم:</span><span class="font-medium">{{ number_format($this->pricing['subtotal'], 2) }}</span>
                        </div>
                        <div class="flex justify-between text-sm"><span>قيمة الحسم:</span><span>{{ number_format($this->pricing['discount_amount'], 2) }}</span></div>
                        <div class="flex justify-between text-sm"><strong>الإجمالي بعد الحسم:</strong><strong>{{ number_format($this->pricing['total'], 2) }}</strong></div>
                        @if($invoice->isSale() && $hasDelivery && (float)$deliveryAmount>0)<div class="flex justify-between text-sm"><span>التوصيل:</span><span>{{ number_format((float)$deliveryAmount,2) }}</span></div>@endif
                        @if($invoice->isSale() && $this->additionalTotal>0)<div class="flex justify-between text-sm"><span>الخانات الإضافية:</span><span>{{ number_format($this->additionalTotal,2) }}</span></div>@endif
                        @if($invoice->isSale())<div class="flex justify-between text-sm"><strong>الإجمالي المطلوب:</strong><strong>{{ number_format($this->customerTotal,2) }}</strong></div>@endif
                    </div>
                </div>
                
                <div class="p-4 border-t" style="background: var(--app-bg); border-color: var(--app-border);">
                    <button wire:click="saveEdit" wire:loading.attr="disabled" class="w-full flex items-center justify-center px-4 py-2.5 text-sm font-medium text-white rounded-xl disabled:opacity-70" style="background: var(--app-primary);">
                        <span wire:loading.remove wire:target="saveEdit">حفظ التعديلات</span>
                        <span wire:loading wire:target="saveEdit">جاري الحفظ...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
