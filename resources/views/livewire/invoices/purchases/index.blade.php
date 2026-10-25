<div class="module-page">
    <div class="page-heading">
        <div><span class="eyebrow">سجل المشتريات</span><h1>فواتير الشراء</h1><p>حركة التوريد والمبالغ وطباعة الإيصال من الصف نفسه.</p></div>
        <a href="{{ route('invoices.purchases.create') }}" wire:navigate class="primary-button">＋ فاتورة شراء</a>
    </div>

    <section class="workspace-card">
        <div class="filter-bar"><input wire:model.live.debounce.300ms="search" placeholder="بحث برقم الفاتورة أو اسم المورد"></div>
        <div class="table-shell"><table>
            <thead><tr><th>#</th><th>المورد</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي</th><th>الحالة</th><th>التاريخ</th><th>الإجراءات</th></tr></thead>
            <tbody>
            @forelse($invoices as $invoice)
                @php
                    $editReason = $editBlockReasons[$invoice->id] ?? null;
                    $cancelReason = $cancellationBlockReasons[$invoice->id] ?? null;
                @endphp
                <tr wire:key="purchase-{{ $invoice->id }}">
                    <td>{{ $invoice->id }}</td><td>{{ $invoice->partner?->name ?? 'مورد نقدي' }}</td>
                    <td>{{ number_format((float)$invoice->total,2) }}</td><td>{{ number_format((float)$invoice->effective_paid,2) }}</td><td>{{ number_format($invoice->remaining,2) }}</td>
                    <td><span class="badge {{ $invoice->isCancelled() ? 'badge-danger' : 'badge-success' }}">{{ $invoice->isCancelled() ? 'ملغاة' : 'نشطة' }}</span></td><td>{{ $invoice->created_at->format('Y-m-d') }}</td>
                    <td><div class="row-actions">
                        <a class="ghost-button" href="{{ route('invoices.purchases.show',$invoice) }}" wire:navigate>التفاصيل</a>
                        @can('update', $invoice)
                            @if($editReason)<button type="button" class="secondary-button" disabled title="{{ $editReason }}">تعديل</button>@else<a class="secondary-button" href="{{ route('invoices.edit',$invoice) }}" wire:navigate>تعديل</a>@endif
                        @endcan
                        @unless($invoice->isCancelled())
                            <a class="print-button print-button-compact" href="{{ route('invoices.receipt',$invoice) }}" target="_blank" title="طباعة الإيصال" aria-label="طباعة الإيصال"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17h10v4H7zM5 17H3v-7h18v7h-2M17 12h1"/></svg></a>
                            <form method="post" action="{{ route('telegram.send-invoice') }}">@csrf<input type="hidden" name="invoice_id" value="{{ $invoice->id }}"><button class="icon-button" title="إرسال إلى تيليغرام">➤</button></form>
                        @endunless
                        @can('delete', $invoice)
                            @if($cancelReason)<button type="button" class="danger-button" disabled title="{{ $cancelReason }}">إلغاء الفاتورة</button>@else<button type="button" class="danger-button" wire:click="openCancellation({{ $invoice->id }})">إلغاء الفاتورة</button>@endif
                        @endcan
                    </div>@if($editReason || $cancelReason)<small class="field-error">{{ $editReason ?: $cancelReason }}</small>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="empty-state">لا توجد فواتير شراء.</div></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $invoices->links() }}
    </section>

    @if($cancellingId)
        <div class="modal-backdrop" wire:click.self="$set('cancellingId',null)"><section class="workspace-card financial-movement-modal">
            <header><h2>إلغاء فاتورة الشراء #{{ $cancellingId }}</h2><button type="button" class="icon-button" wire:click="$set('cancellingId',null)">×</button></header>
            <form wire:submit="cancel"><label>سبب الإلغاء<textarea wire:model="cancellationReason" maxlength="1000" required autofocus></textarea>@error('cancellationReason')<small class="field-error">{{ $message }}</small>@enderror</label><div class="modal-actions"><button type="button" class="secondary-button" wire:click="$set('cancellingId',null)">تراجع</button><button class="danger-button">تأكيد الإلغاء</button></div></form>
        </section></div>
    @endif
</div>
