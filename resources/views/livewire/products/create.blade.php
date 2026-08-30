<div class="rounded-xl p-6 max-w-2xl" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <h2 class="text-xl font-semibold mb-4" style="color: var(--app-primary);">إضافة منتج</h2>
    <form wire:submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="category_id" class="block text-sm font-medium mb-1">التصنيف *</label>
                <select id="category_id" wire:model="category_id" required
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    <option value="">اختر التصنيف</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
                @error('category_id') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="partner_id" class="block text-sm font-medium mb-1">المورد *</label>
                <select id="partner_id" wire:model="partner_id" required
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    <option value="">اختر المورد</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
                @error('partner_id') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <div>
            <label for="name" class="block text-sm font-medium mb-1">الاسم</label>
            <input type="text" id="name" wire:model="name" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            @error('name') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="description" class="block text-sm font-medium mb-1">الوصف</label>
            <textarea id="description" wire:model="description" rows="2" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);"></textarea>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="purchase_price" class="block text-sm font-medium mb-1">سعر الشراء</label>
                <input type="number" step="0.01" id="purchase_price" wire:model.blur="purchase_price" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
            <div>
                <label for="sale_price" class="block text-sm font-medium mb-1">سعر البيع</label>
                <input type="number" step="0.01" id="sale_price" wire:model="sale_price" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="quantity" class="block text-sm font-medium mb-1">الكمية</label>
                <input type="number" min="0" id="quantity" wire:model.blur="quantity" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('quantity') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="alert_stock_at" class="block text-sm font-medium mb-1">نبّهني عندما تصل الكمية إلى</label>
                <input type="number" min="0" id="alert_stock_at" wire:model="alert_stock_at" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('alert_stock_at') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="country_origin" class="block text-sm font-medium mb-1">بلد المنشأ</label>
                <input type="text" id="country_origin" wire:model="country_origin" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">الصورة</label>
            <input type="file" wire:model="image" accept="image/*" class="w-full text-sm" style="color: var(--app-text);">
            @error('image') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="pt-4 border-t" style="border-color: var(--app-border);">
            <h3 class="text-md font-semibold mb-3" style="color: var(--app-primary);">تفاصيل الشراء (الفاتورة)</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="total_purchase_amount" class="block text-sm font-medium mb-1">المبلغ الإجمالي</label>
                    <input type="number" step="0.01" id="total_purchase_amount" wire:model="total_purchase_amount" readonly tabindex="-1"
                        class="w-full rounded-lg border px-4 py-2 opacity-75 cursor-not-allowed" style="background: var(--app-card); border-color: var(--app-border); color: var(--app-text);">
                    <p class="text-xs text-gray-500 mt-1">يُحسب تلقائياً: سعر الشراء × الكمية</p>
                </div>
                <div>
                    <label for="paid_purchase_amount" class="block text-sm font-medium mb-1">المبلغ المدفوع</label>
                    <input type="number" step="0.01" id="paid_purchase_amount" wire:model="paid_purchase_amount" 
                        class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                </div>
            </div>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 rounded-lg text-white font-medium" style="background: var(--app-primary);">حفظ</button>
            <a href="{{ route('products.index') }}" class="px-4 py-2 rounded-lg" style="background: var(--app-bg); color: var(--app-text);">إلغاء</a>
        </div>
    </form>
</div>
