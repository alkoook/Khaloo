<div class="space-y-6">
    <div class="rounded-xl overflow-hidden" style="background: var(--app-card); border: 1px solid var(--app-border);">
        <h2 class="text-xl font-semibold p-4" style="color: var(--app-primary); border-bottom: 1px solid var(--app-border);">تسجيل مصروف جديد</h2>
        <form wire:submit.prevent="save" class="p-4 flex flex-wrap items-end gap-4">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium mb-1">البيان (السبب)</label>
                <input type="text" wire:model="reason" placeholder="مثلاً: آجار المحل، فاتورة كهرباء..." class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('reason') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="w-40">
                <label class="block text-sm font-medium mb-1">المبلغ</label>
                <input type="number" step="0.01" wire:model="amount" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('amount') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="w-48">
                <label class="block text-sm font-medium mb-1">التاريخ</label>
                <input type="date" wire:model="date" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('date') <p class="text-sm text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="px-6 py-2 rounded-lg text-white font-medium whitespace-nowrap" style="background: var(--app-primary);">إضافة المصروف</button>
        </form>
    </div>

    <div class="rounded-xl overflow-hidden" style="background: var(--app-card); border: 1px solid var(--app-border);">
        <div class="flex justify-between items-center p-4 border-b" style="border-color: var(--app-border);">
            <h2 class="text-xl font-semibold" style="color: var(--app-primary);">قائمة المصروفات</h2>
            <div class="text-sm py-1 px-3 rounded-full" style="background: var(--app-bg); color: var(--app-muted);">
                إجمالي هذا الشهر: <span class="font-bold text-lg" style="color: var(--app-primary);">{{ number_format($totalThisMonth, 2) }}</span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-right text-sm">
                <thead>
                    <tr style="background: var(--app-bg);">
                        <th class="px-4 py-3 font-medium">التاريخ</th>
                        <th class="px-4 py-3 font-medium">البيان</th>
                        <th class="px-4 py-3 font-medium text-left">المبلغ</th>
                        <th class="px-4 py-3 font-medium w-16"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($expenses as $e)
                        <tr style="border-top: 1px solid var(--app-border);">
                            <td class="px-4 py-3">{{ $e->date->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">{{ $e->reason }}</td>
                            <td class="px-4 py-3 text-left font-bold" style="color: var(--app-primary);">{{ number_format($e->amount, 2) }}</td>
                            <td class="px-4 py-3 text-center">
                                <button wire:click="delete({{ $e->id }})" data-confirm="هل أنت متأكد من حذف هذا المصروف؟ لا يمكن التراجع عن هذا الإجراء." class="text-red-500 hover:opacity-75">حذف</button>
                            </td>
                        </tr>
                    @endforeach
                    @if($expenses->isEmpty())
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center" style="color: var(--app-muted);">لا يوجد مصروفات مسجلة حالياً.</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t" style="border-color: var(--app-border);">
            {{ $expenses->links() }}
        </div>
    </div>
</div>
