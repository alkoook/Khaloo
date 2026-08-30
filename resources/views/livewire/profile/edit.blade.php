<div>
    <x-slot:title>الملف الشخصي</x-slot>

    <div class="mb-6">
        <h1 class="text-2xl font-bold" style="color: var(--app-text)">تحديث الملف الشخصي</h1>
        <p class="text-sm mt-1" style="color: var(--app-muted)">تحديث البريد الإلكتروني وكلمة المرور لحسابك بأمان.</p>
    </div>

    <div class="max-w-2xl rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
        <div class="p-6">
            @if (session('status'))
                <div class="mb-4 p-4 rounded-xl border flex items-center gap-2" style="background: rgba(34, 197, 94, 0.1); color: #22c55e; border-color: rgba(34, 197, 94, 0.2);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                    {{ session('status') }}
                </div>
            @endif

            <form wire:submit="updateProfile" class="space-y-6">
                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">البريد الإلكتروني <span class="text-red-500">*</span></label>
                    <input type="email" wire:model="email" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" dir="ltr" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    @error('email') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>

                <hr class="border" style="border-color: var(--app-border);">

                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">كلمة المرور الحالية <span class="text-red-500">*</span></label>
                    <input type="password" wire:model="current_password" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" dir="ltr" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    @error('current_password') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">كلمة المرور الجديدة <span class="text-red-500">*</span></label>
                    <input type="password" wire:model="password" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" dir="ltr" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    @error('password') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" style="color: var(--app-text)">تأكيد كلمة المرور الجديدة <span class="text-red-500">*</span></label>
                    <input type="password" wire:model="password_confirmation" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2" dir="ltr" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                </div>

                <div class="pt-2">
                    <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 px-6 py-2.5 text-sm font-medium text-white rounded-xl disabled:opacity-70" style="background: var(--app-primary);">
                        <span wire:loading.remove wire:target="updateProfile">حفظ التغييرات</span>
                        <span wire:loading wire:target="updateProfile">جاري الحفظ...</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
