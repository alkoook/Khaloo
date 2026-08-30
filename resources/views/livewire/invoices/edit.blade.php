<div>
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
                    
                    <div class="space-y-4">
                        @foreach($lines as $index => $line)
                            <div class="flex flex-col sm:flex-row gap-4 p-4 rounded-xl border relative" style="background: var(--app-bg); border-color: var(--app-border);">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">المنتج</label>
                                    <select wire:model.live="lines.{{ $index }}.product_id" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
                                        <option value="">اختر المنتج...</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product['id'] }}">{{ $product['name'] }}</option>
                                        @endforeach
                                    </select>
                                    @error("lines.{$index}.product_id") <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div class="w-full sm:w-24">
                                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">الكمية</label>
                                    <input type="number" wire:model="lines.{{ $index }}.quantity" min="1" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
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
        </div>
        
        <div class="space-y-6">
            <div class="rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
                <div class="p-6 space-y-5">
                    <h2 class="text-lg font-semibold" style="color: var(--app-text)">تفاصيل مالية</h2>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">المبلغ المدفوع</label>
                        <input type="number" step="0.01" wire:model="amountPaid" min="0" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                        @error('amountPaid') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    
                    @php
                        $total = 0;
                        foreach($lines as $l) {
                            $total += floatval($l['quantity'] ?? 0) * floatval($l['unit_price'] ?? 0);
                        }
                    @endphp
                    
                    <div class="pt-4 border-t space-y-3" style="border-color: var(--app-border);">
                        <div class="flex justify-between text-sm">
                            <span style="color: var(--app-muted)">الإجمالي بعد التعديل:</span>
                            <span class="font-medium" style="color: var(--app-text)">{{ number_format($total, 2) }}</span>
                        </div>
                    </div>
                </div>
                
                <div class="p-4 border-t" style="background: var(--app-bg); border-color: var(--app-border);">
                    <button wire:click="saveEdit" wire:loading.attr="disabled" class="w-full flex items-center justify-center px-4 py-2.5 text-sm font-medium text-white rounded-xl disabled:opacity-70" style="background: var(--app-primary);">
                        <span wire:loading.remove wire:target="saveEdit">حفظ التعديلات (Delta)</span>
                        <span wire:loading wire:target="saveEdit">جاري الحفظ...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
