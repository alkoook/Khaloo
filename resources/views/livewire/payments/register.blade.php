<div>
<div class="rounded-xl overflow-hidden" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <div class="p-4" style="border-bottom: 1px solid var(--app-border);">
        <h2 class="text-xl font-semibold mb-4" style="color: var(--app-primary);">سجل الدفعات</h2>
        <div class="flex flex-wrap items-center gap-4 mb-4">
            <select wire:model.live="typeFilter" class="rounded-lg px-3 py-2 text-sm w-40" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);">
                <option value="">كل الدفعات</option>
                <option value="incoming">وارد (اللي النا)</option>
                <option value="outgoing">صادر (اللي علينا)</option>
            </select>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg px-3 py-2 text-sm" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);" placeholder="من">
            <input type="date" wire:model.live="dateTo" class="rounded-lg px-3 py-2 text-sm" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);" placeholder="إلى">
            <form method="get" action="{{ route('payments.register') }}" class="inline">
                <input type="hidden" name="open_modal" value="1">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background: var(--app-primary);">تسجيل دفعة جديدة</button>
            </form>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
            <thead>
                <tr style="background: var(--app-bg);">
                    <th class="px-4 py-3 font-medium">التاريخ</th>
                    <th class="px-4 py-3 font-medium">الطرف (السيد / مورد)</th>
                    <th class="px-4 py-3 font-medium">المرجع</th>
                    <th class="px-4 py-3 font-medium">النوع</th>
                    <th class="px-4 py-3 font-medium">طريقة الدفع</th>
                    <th class="px-4 py-3 font-medium">المبلغ</th>
                    <th class="px-4 py-3 font-medium">الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($settlements as $s)
                    <tr wire:key="settlement-{{ $s->id }}" style="border-top: 1px solid var(--app-border);">
                        <td class="px-4 py-3">{{ $s->settlement_date?->format('Y-m-d') ?? $s->created_at?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $s->partner?->name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $s->invoice_id ? 'فاتورة #'.$s->invoice_id : 'ذمة الطرف' }}</td>
                        <td class="px-4 py-3">{{ $s->type === 'incoming' ? 'وارد (اللي النا)' : 'صادر (اللي علينا)' }}</td>
                        <td class="px-4 py-3">{{ match($s->payment_method ?: 'cash') { 'cash' => 'نقدي', 'bank_transfer' => 'تحويل', 'card' => 'بطاقة', default => 'أخرى' } }}</td>
                        <td class="px-4 py-3 font-medium">{{ number_format($s->amount, 2) }}</td>
                        <td class="px-4 py-3"><div class="row-actions"><button type="button" class="table-action" wire:click="openEditModal({{ $s->id }})">تعديل الدفعة</button><button type="button" class="table-action is-danger" wire:click="cancelPayment({{ $s->id }})" data-confirm="هل تريد إلغاء هذه الدفعة؟ سيعاد احتساب الرصيد وحركة النقد.">حذف / إلغاء</button></div></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center" style="color: var(--app-muted);">لا توجد دفعات مسجلة.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-2" style="border-top: 1px solid var(--app-border);">
        {{ $settlements->links() }}
    </div>
</div>

{{-- Modal تسجيل دفعة جديدة --}}
@if($showAddModal)
@teleport('body')
<div class="fixed inset-0 z-50 flex justify-center p-4 payment-modal-backdrop" style="background: rgba(0,0,0,0.5);" wire:key="modal-payment-register" wire:click.self="closeAddModal" wire:keydown.escape.window="closeAddModal">
    <div class="rounded-xl p-6 max-w-md w-full payment-modal-panel" style="background: var(--app-card); border: 1px solid var(--app-border);">
        <h3 class="text-lg font-semibold mb-4 payment-modal-header" style="color: var(--app-primary);">تسجيل دفعة جديدة</h3>
        <form wire:submit="addPayment" class="payment-modal-form">
            <div class="space-y-4 payment-modal-body">
            <div>
                <label for="settlementType" class="block text-sm font-medium mb-1">نوع الدفعة *</label>
                <select id="settlementType" wire:model.live="settlementType" required class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    <option value="incoming">وارد (اللي النا) — من السيد</option>
                    <option value="outgoing">صادر (اللي علينا) — لمورد</option>
                </select>
                <p class="mt-1 text-xs" style="color: var(--app-muted);">الدفع الصادر يُستخدم لتسديد مورد أو لرد مبلغ مستحق للسيد؛ ولا يمكن أن يتجاوز الرصيد المستحق.</p>
            </div>
            <div>
                <label for="partnerId" class="block text-sm font-medium mb-1">{{ $settlementType === 'incoming' ? 'السيد' : 'المورد' }} *</label>
                <select id="partnerId" wire:model.live="partnerId" required class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    <option value="">اختر {{ $settlementType === 'incoming' ? 'السيد (اللي النا)' : 'المورد (اللي علينا)' }}</option>
                    @foreach($partnersWithBalance as $p)
                        <option value="{{ $p->id }}">{{ $p->name ?? '—' }}</option>
                    @endforeach
                </select>
                @if($partnersWithBalance->isEmpty())
                    <p class="text-sm mt-1" style="color: var(--app-muted);">لا يوجد {{ $settlementType === 'incoming' ? 'سادة لديهم مستحقات (اللي النا)' : 'أطراف لها مستحقات (اللي علينا)' }}.</p>
                @endif
                @error('partnerId')
                    <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>
            @if($partnerId)<div class="applied-filters"><div><span>الرصيد الحالي</span><b>{{ number_format($this->currentBalance,2) }}</b></div><div><span>الرصيد المتوقع بعد الحفظ</span><b>{{ number_format($this->expectedBalance,2) }}</b></div></div>@endif
            @if($partnerId)<div><label for="invoiceId" class="block text-sm font-medium mb-1">مرجع الفاتورة (اختياري)</label><select id="invoiceId" wire:model="invoiceId" class="w-full rounded-lg border px-4 py-2" style="background:var(--app-bg);border-color:var(--app-border);color:var(--app-text)"><option value="">على ذمة الطرف عمومًا</option>@foreach($paymentInvoices as $paymentInvoice)<option value="{{ $paymentInvoice->id }}">فاتورة #{{ $paymentInvoice->id }} — المتبقي {{ number_format((float)$paymentInvoice->remaining,2) }}</option>@endforeach</select>@error('invoiceId')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror</div>@endif
            <div>
                <label for="amount" class="block text-sm font-medium mb-1">قيمة الدفعة *</label>
                <input type="number" id="amount" wire:model="amount" step="0.01" min="0.01" required placeholder="أدخل المبلغ" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('amount')
                    <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div><label for="paymentMethod" class="block text-sm font-medium mb-1">طريقة الدفع *</label><select id="paymentMethod" wire:model="paymentMethod" class="w-full rounded-lg border px-4 py-2" style="background:var(--app-bg);border-color:var(--app-border);color:var(--app-text)"><option value="cash">نقدي</option><option value="bank_transfer">تحويل</option><option value="card">بطاقة</option><option value="other">أخرى</option></select>@error('paymentMethod')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror</div>
            <div>
                <label for="settlementDate" class="block text-sm font-medium mb-1">تاريخ الدفعة *</label>
                <input type="date" id="settlementDate" wire:model="settlementDate" required class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                @error('settlementDate')
                    <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div><label for="paymentNotes" class="block text-sm font-medium mb-1">ملاحظة اختيارية</label><textarea id="paymentNotes" wire:model="notes" maxlength="1000" class="w-full rounded-lg border px-4 py-2" style="background:var(--app-bg);border-color:var(--app-border);color:var(--app-text)"></textarea></div>
            </div>
            <div class="flex gap-2 justify-end payment-modal-actions">
                <button type="button" wire:click="closeAddModal" class="px-4 py-2 rounded-lg" style="background: var(--app-bg); color: var(--app-text); border: 1px solid var(--app-border);">إلغاء</button>
                <button type="submit" class="px-4 py-2 rounded-lg text-white font-medium" style="background: var(--app-primary);">تسجيل</button>
            </div>
        </form>
    </div>
</div>
@endteleport
@endif

@if($showEditModal && $editingSettlement)
@teleport('body')
<div class="fixed inset-0 z-50 flex justify-center p-4 payment-modal-backdrop" style="background:rgba(0,0,0,.5)" wire:key="modal-payment-edit" wire:click.self="closeEditModal" wire:keydown.escape.window="closeEditModal">
    <div class="rounded-xl p-6 max-w-md w-full payment-modal-panel" style="background:var(--app-card);border:1px solid var(--app-border)">
        <h3 class="text-lg font-semibold mb-4 payment-modal-header" style="color:var(--app-primary)">تعديل الدفعة #{{ $editingSettlement->id }}</h3>
        <form wire:submit="savePaymentEdit" class="payment-modal-form">
            <div class="space-y-4 payment-modal-body">
                <div class="applied-filters"><div><span>الطرف</span><b>{{ $editingSettlement->partner?->name }}</b></div><div><span>المبلغ المسجل حاليًا</span><b>{{ number_format((float)$editingSettlement->amount,2) }}</b></div><div><span>المرجع</span><b>{{ $editingSettlement->invoice_id ? 'فاتورة #'.$editingSettlement->invoice_id : 'ذمة الطرف' }}</b></div></div>
                <div><label for="editAmount" class="block text-sm font-medium mb-1">المبلغ *</label><input id="editAmount" type="number" min="0.01" step="0.01" wire:model="editAmount" class="w-full rounded-lg border px-4 py-2">@error('editAmount')<p class="field-error mt-1">{{ $message }}</p>@enderror</div>
                <div><label for="editSettlementDate" class="block text-sm font-medium mb-1">تاريخ الدفعة *</label><input id="editSettlementDate" type="date" wire:model="editSettlementDate" class="w-full rounded-lg border px-4 py-2">@error('editSettlementDate')<p class="field-error mt-1">{{ $message }}</p>@enderror</div>
                <div><label for="editPaymentMethod" class="block text-sm font-medium mb-1">طريقة الدفع *</label><select id="editPaymentMethod" wire:model="editPaymentMethod" class="w-full rounded-lg border px-4 py-2"><option value="cash">نقدي</option><option value="bank_transfer">تحويل</option><option value="card">بطاقة</option><option value="other">أخرى</option></select>@error('editPaymentMethod')<p class="field-error mt-1">{{ $message }}</p>@enderror</div>
                <div><label for="editInvoiceId" class="block text-sm font-medium mb-1">مرجع الفاتورة</label><select id="editInvoiceId" wire:model="editInvoiceId" class="w-full rounded-lg border px-4 py-2"><option value="">على ذمة الطرف عمومًا</option>@foreach($editInvoices as $paymentInvoice)<option value="{{ $paymentInvoice->id }}">فاتورة #{{ $paymentInvoice->id }} — المتبقي {{ number_format((float)$paymentInvoice->remaining,2) }}</option>@endforeach</select>@error('editInvoiceId')<p class="field-error mt-1">{{ $message }}</p>@enderror</div>
                <div><label for="editNotes" class="block text-sm font-medium mb-1">ملاحظات</label><textarea id="editNotes" wire:model="editNotes" maxlength="1000" class="w-full rounded-lg border px-4 py-2"></textarea>@error('editNotes')<p class="field-error mt-1">{{ $message }}</p>@enderror</div>
            </div>
            <div class="flex gap-2 justify-end payment-modal-actions"><button type="button" wire:click="closeEditModal" class="btn-secondary">إلغاء</button><button type="submit" wire:loading.attr="disabled" wire:target="savePaymentEdit" class="btn-primary"><span wire:loading.remove wire:target="savePaymentEdit">حفظ التعديل</span><span wire:loading wire:target="savePaymentEdit">جارٍ الحفظ...</span></button></div>
        </form>
    </div>
</div>
@endteleport
@endif
</div>
