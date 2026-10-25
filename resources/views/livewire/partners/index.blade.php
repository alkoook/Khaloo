<div class="rounded-xl overflow-hidden card-puff" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <div class="p-4 flex flex-wrap items-center justify-between gap-4" style="border-bottom: 1px solid var(--app-border);">
        <h2 class="text-xl font-semibold" style="color: var(--app-primary);">السادة والموردين</h2>
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="بحث بالاسم..."
                class="rounded-lg px-3 py-2 text-sm w-40" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);">
            <select wire:model.live="typeFilter" class="rounded-lg px-3 py-2 text-sm w-40" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);">
                <option value="">الكل</option>
                <option value="customer">السيد</option>
                <option value="supplier">مورد</option>
            </select>
            <a href="{{ route('partners.create') }}" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background: var(--app-primary);">إضافة</a>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
            <thead>
                <tr style="background: var(--app-bg);">
                    <th class="px-4 py-3 font-medium">الاسم</th>
                    <th class="px-4 py-3 font-medium">النوع</th>
                    <th class="px-4 py-3 font-medium">الرقم الوطني</th>
                    <th class="px-4 py-3 font-medium w-28">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($partners as $partner)
                    <tr wire:key="partner-{{ $partner->id }}" style="border-top: 1px solid var(--app-border);">
                        <td class="px-4 py-3">{{ $partner->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $partner->type === 'customer' ? 'السيد' : 'مورد' }}</td>
                        <td class="px-4 py-3">{{ $partner->national_id ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <a href="{{ route('partners.edit', $partner) }}" class="btn-action btn-edit" title="تعديل">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                <button type="button" wire:click="openStatementModal({{ $partner->id }})" class="btn-action" style="color: var(--app-primary);" title="كشف حساب">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </button>
                                <button type="button" wire:click="delete({{ $partner->id }})" data-confirm="هل تريد الحذف؟" class="btn-action btn-delete" title="حذف">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center" style="color: var(--app-muted);">لا يوجد سادة أو موردون.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-2" style="border-top: 1px solid var(--app-border);">
        {{ $partners->links() }}
    </div>

    @if($showStatementModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div class="w-full max-w-md bg-white rounded-xl shadow-lg border p-6" style="background: var(--app-card); border-color: var(--app-border);">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold" style="color: var(--app-primary);">إصدار كشف حساب</h3>
                    <button wire:click="closeStatementModal" class="text-gray-500 hover:text-gray-700">&times;</button>
                </div>
                
                @if(session()->has('error'))
                    <div class="mb-4 text-sm text-red-600 bg-red-50 p-2 rounded">
                        {{ session('error') }}
                    </div>
                @endif
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">من تاريخ</label>
                        <input type="date" wire:model.defer="statementFromDate" class="w-full rounded-lg px-3 py-2 text-sm" style="background: var(--app-bg); border: 1px solid var(--app-border);">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">إلى تاريخ</label>
                        <input type="date" wire:model.defer="statementToDate" class="w-full rounded-lg px-3 py-2 text-sm" style="background: var(--app-bg); border: 1px solid var(--app-border);">
                    </div>
                </div>
                
                <div class="mt-6 flex gap-2 justify-end">
                    <button wire:click="closeStatementModal" class="px-4 py-2 rounded-lg text-sm bg-gray-200 text-gray-800 hover:bg-gray-300">إلغاء</button>
                    <button wire:click="generateStatement" class="px-4 py-2 rounded-lg text-sm text-white" style="background: var(--app-primary);">إصدار الوورد والارسال</button>
                </div>
            </div>
        </div>
    @endif
</div>
