<div>
    <div class="mb-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold" style="color: var(--app-text)">فواتير المرتجعات</h1>
            <p class="text-sm mt-1" style="color: var(--app-muted)">قائمة بكافة مرتجعات المبيعات المسجلة.</p>
        </div>
    </div>

    <div class="rounded-2xl border overflow-hidden" style="background: var(--app-card); border-color: var(--app-border);">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-sm">
                <thead>
                    <tr style="background: var(--app-bg); border-bottom: 1px solid var(--app-border);">
                        <th class="px-6 py-4 font-semibold">رقم المرتجع</th>
                        <th class="px-6 py-4 font-semibold">الزبون</th>
                        <th class="px-6 py-4 font-semibold">التاريخ</th>
                        <th class="px-6 py-4 font-semibold">قيمة المرتجع</th>
                        <th class="px-6 py-4 font-semibold">المبلغ المردود نقداً</th>
                        <th class="px-6 py-4 font-semibold">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y" style="border-color: var(--app-border);">
                    @forelse($returns as $return)
                        <tr>
                            <td class="px-6 py-4">#{{ $return->id }}</td>
                            <td class="px-6 py-4">{{ $return->partner->name ?? '—' }}</td>
                            <td class="px-6 py-4">{{ $return->created_at->format('Y-m-d') }}</td>
                            <td class="px-6 py-4 font-bold">{{ number_format($return->total, 2) }}</td>
                            <td class="px-6 py-4 text-red-500 font-medium">{{ number_format($return->amount_paid, 2) }}</td>
                            <td class="px-6 py-4 flex gap-2">
                                <a href="{{ route('invoices.sales.show', $return->id) }}" class="p-2 rounded-lg transition-colors hover:bg-slate-100 dark:hover:bg-slate-800" title="عرض">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                                <a href="{{ route('invoices.returns.export', $return->id) }}" class="p-2 rounded-lg transition-colors hover:bg-slate-100 dark:hover:bg-slate-800" title="تحميل Word">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center" style="color: var(--app-muted)">لا يوجد مرتجعات مسجلة حالياً.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t" style="border-color: var(--app-border);">
            {{ $returns->links() }}
        </div>
    </div>
</div>
