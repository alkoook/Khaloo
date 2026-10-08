<div class="rounded-xl overflow-hidden card-puff" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <div class="p-4 flex flex-wrap items-center justify-between gap-4" style="border-bottom: 1px solid var(--app-border);">
        <h2 class="text-xl font-semibold" style="color: var(--app-primary);">التصنيفات</h2>
        <div class="flex items-center gap-2">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="بحث بالاسم..."
                class="rounded-lg px-3 py-2 text-sm w-48" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);">
            <a href="{{ route('categories.create') }}" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background: var(--app-primary);">إضافة تصنيف</a>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-right">
            <thead>
                <tr style="background: var(--app-bg);">
                    <th class="px-4 py-3 text-sm font-medium">الاسم</th>
                    <th class="px-4 py-3 text-sm font-medium w-24">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $category)
                    <tr wire:key="category-{{ $category->id }}" style="border-top: 1px solid var(--app-border);">
                        <td class="px-4 py-3">{{ $category->name }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1">
                                <a href="{{ route('categories.edit', $category) }}" class="btn-action btn-edit" title="تعديل">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                <button type="button" wire:click="delete({{ $category->id }})" data-confirm="هل تريد حذف هذا التصنيف؟"
                                    class="btn-action btn-delete" title="حذف">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-4 py-8 text-center" style="color: var(--app-muted);">لا توجد تصنيفات.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-2" style="border-top: 1px solid var(--app-border);">
        {{ $categories->links() }}
    </div>
</div>
