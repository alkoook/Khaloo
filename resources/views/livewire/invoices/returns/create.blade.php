<div>
    <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold" style="color: var(--app-text)">إضافة فاتورة مرتجع</h1>
            <p class="text-sm mt-1" style="color: var(--app-muted)">قم بتسجيل مرتجعات المبيعات واسترجاعها للمخزون.</p>
        </div>
        <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium transition-colors border rounded-xl" style="background: var(--app-bg); color: var(--app-text); border-color: var(--app-border);">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><path d="m15 18-6-6 6-6"/></svg>
            عودة
        </a>
    </div>

    @if(!$invoice)
        <div class="max-w-md p-6 rounded-2xl border" style="background: var(--app-card); border-color: var(--app-border);">
            <h2 class="text-lg font-semibold mb-4" style="color: var(--app-text)">البحث عن فاتورة مبيعات</h2>
            <form wire:submit="findInvoice($refs.searchInput.value)" class="flex gap-2">
                <input type="number" x-ref="searchInput" placeholder="أدخل رقم الفاتورة..." required class="flex-1 px-4 py-2 rounded-xl text-sm border focus:ring-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                <button type="submit" class="px-4 py-2 rounded-xl text-sm font-medium text-white" style="background: var(--app-primary);">بحث</button>
            </form>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
                    <div class="p-6 border-b" style="border-color: var(--app-border);">
                        <h2 class="text-lg font-semibold" style="color: var(--app-text)">المنتجات القابلة للإرجاع (من فاتورة #{{ $invoice->id }})</h2>
                    </div>
                    <div class="p-6 space-y-4">
                        @foreach($lines as $line)
                            <div class="flex flex-col sm:flex-row gap-4 p-4 rounded-xl border" style="background: var(--app-bg); border-color: var(--app-border);">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">المنتج</label>
                                    <div class="px-4 py-2.5 rounded-xl text-sm border" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text)">
                                        {{ $line['product_name'] }} (السعر: {{ number_format($line['unit_price'], 2) }})
                                    </div>
                                </div>
                                <div class="w-full sm:w-32">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">الكمية المباعة</label>
                                    <div class="px-4 py-2.5 rounded-xl text-sm border text-center" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-muted)">
                                        {{ $line['quantity'] }}
                                    </div>
                                </div>
                                <div class="w-full sm:w-32">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">كمية الإرجاع</label>
                                    <input type="number" wire:model.live="returnQuantities.{{ $line['id'] }}" min="0" max="{{ $line['quantity'] }}" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2 text-center" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            
            <div class="space-y-6">
                <div class="rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
                    <div class="p-6 space-y-5">
                        <h2 class="text-lg font-semibold" style="color: var(--app-text)">تفاصيل المرتجع</h2>
                        
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">الزبون</label>
                            <div class="px-4 py-2.5 rounded-xl text-sm border" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                                {{ $invoice->partner->name ?? 'زبون نقدي' }}
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">المبلغ المردود نقداً (إن وجد)</label>
                            <input type="number" step="0.01" wire:model="amountPaid" min="0" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                            <p class="text-xs mt-1" style="color: var(--app-muted)">تُخصم قيمة المرتجع من الدين أولًا. لا تسجّل هنا إلا المبلغ الذي أُعيد نقدًا فعليًا للعميل.</p>
                        </div>
                        
                        <div class="pt-4 border-t space-y-3" style="border-color: var(--app-border);">
                            <div class="flex justify-between text-sm">
                                <span style="color: var(--app-muted)">الإجمالي المرتجع:</span>
                                <span class="font-medium" style="color: var(--app-text)">{{ number_format($this->returnTotal, 2) }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-4 border-t" style="background: var(--app-bg); border-color: var(--app-border);">
                        <button wire:click="saveReturn" wire:loading.attr="disabled" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white rounded-xl disabled:opacity-70" style="background: var(--app-primary);">
                            <span wire:loading.remove wire:target="saveReturn">تأكيد المرتجع</span>
                            <span wire:loading wire:target="saveReturn">جاري الحفظ...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
