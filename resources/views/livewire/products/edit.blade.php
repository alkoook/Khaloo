<div class="rounded-xl p-6 max-w-2xl" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <h2 class="text-xl font-semibold mb-4" style="color: var(--app-primary);">تعديل المنتج</h2>
    <form wire:submit.prevent="save" class="space-y-4">
        <div>
            <label for="category_id" class="block text-sm font-medium mb-1">التصنيف *</label>
            <select id="category_id" wire:model="category_id" required class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @foreach($categories as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
            @error('category_id') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="name" class="block text-sm font-medium mb-1">الاسم</label>
            <input type="text" id="name" wire:model="name" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
        </div>
        <div>
            <label for="description" class="block text-sm font-medium mb-1">الوصف</label>
            <textarea id="description" wire:model="description" rows="2" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);"></textarea>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="purchase_price" class="block text-sm font-medium mb-1">سعر الشراء</label>
                <input type="number" step="0.01" id="purchase_price" wire:model="purchase_price" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
            <div>
                <label for="sale_price" class="block text-sm font-medium mb-1">سعر البيع</label>
                <input type="number" step="0.01" id="sale_price" wire:model="sale_price" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="quantity" class="block text-sm font-medium mb-1">الكمية</label>
                <input type="number" min="0" id="quantity" wire:model="quantity" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('quantity') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="alert_stock_at" class="block text-sm font-medium mb-1">حد تنبيه هذا المنتج</label>
                <input type="number" min="0" id="alert_stock_at" wire:model="alert_stock_at" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('alert_stock_at') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="country_origin" class="block text-sm font-medium mb-1">بلد المنشأ</label>
                <input type="text" id="country_origin" wire:model="country_origin" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">الصورة (اختياري)</label>
            <input type="file" wire:model="image" accept="image/*" class="w-full text-sm" style="color: var(--app-text);">
            @error('image') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 rounded-lg text-white font-medium" style="background: var(--app-primary);">حفظ</button>
            <a href="{{ route('products.index') }}" class="px-4 py-2 rounded-lg" style="background: var(--app-bg); color: var(--app-text);">إلغاء</a>
        </div>
    </form>
</div>
