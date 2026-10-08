<div class="rounded-xl p-6 max-w-md" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <h2 class="text-xl font-semibold mb-4" style="color: var(--app-primary);">تعديل التصنيف</h2>
    <form wire:submit.prevent="save" class="space-y-4">
        <div>
            <label for="name" class="block text-sm font-medium mb-1">الاسم</label>
            <input type="text" id="name" wire:model="name"
                class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            @error('name')
                <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
            @enderror
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 rounded-lg text-white font-medium" style="background: var(--app-primary);">حفظ</button>
            <a href="{{ route('categories.index') }}" class="px-4 py-2 rounded-lg" style="background: var(--app-bg); color: var(--app-text);">إلغاء</a>
        </div>
    </form>
</div>
