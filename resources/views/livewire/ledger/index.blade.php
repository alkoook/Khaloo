<div class="rounded-xl overflow-hidden" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <div class="p-4" style="border-bottom: 1px solid var(--app-border);">
        <h2 class="text-xl font-semibold mb-4" style="color: var(--app-primary);">الدفتر</h2>
        <div class="flex flex-wrap items-center gap-4 mb-4">
            <input type="text" wire:model.live.debounce.200ms="search" placeholder="بحث بالاسم..."
                class="rounded-lg px-3 py-2 text-sm w-48" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);">
            <select wire:model.live="sortBy" class="rounded-lg px-3 py-2 text-sm w-40" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);">
                <option value="balance">ترتيب بالمبلغ (الأكبر أولاً)</option>
                <option value="date">ترتيب بالتاريخ</option>
            </select>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg px-3 py-2 text-sm" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);" placeholder="من">
            <input type="date" wire:model.live="dateTo" class="rounded-lg px-3 py-2 text-sm" style="background: var(--app-bg); border: 1px solid var(--app-border); color: var(--app-text);" placeholder="إلى">
        </div>
        <div class="flex flex-wrap items-center gap-2 mt-2">
        <div class="flex rounded-lg overflow-hidden" style="border: 1px solid var(--app-border);">
            <button type="button" wire:click="setTabReceivable" wire:key="btn-receivable" class="px-4 py-2 text-sm font-medium"
                style="{{ $tab === 'receivable' ? 'background: var(--app-primary); color: #fff;' : 'background: var(--app-bg); color: var(--app-text);' }}">
                اللي النا
            </button>
            <button type="button" wire:click="setTabPayable" wire:key="btn-payable" class="px-4 py-2 text-sm font-medium"
                style="{{ $tab === 'payable' ? 'background: var(--app-primary); color: #fff;' : 'background: var(--app-bg); color: var(--app-text);' }}">
                اللي علينا
            </button>
        </div>
        <small style="color: var(--app-muted);">{{ $tab === 'receivable' ? 'مبالغ باقية لنا لدى السادة بعد المقبوضات.' : 'مبالغ مستحقة للموردين أو للسادة عند وجود رصيد لهم.' }}</small>
            <form method="get" action="{{ route('ledger.index') }}" class="inline">
                <input type="hidden" name="open_modal" value="1">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background: var(--app-primary);">إضافة دفعة</button>
            </form>
        </div>
    </div>

    {{-- Modal إضافة دفعة --}}
    @if($showAddPaymentModal)
    @teleport('body')
    <div class="fixed inset-0 z-50 flex justify-center p-4 payment-modal-backdrop" style="background: rgba(0,0,0,0.5);" wire:key="modal-add-payment" wire:click.self="closeAddPaymentModal" wire:keydown.escape.window="closeAddPaymentModal">
        <div class="rounded-xl p-6 max-w-md w-full payment-modal-panel" style="background: var(--app-card); border: 1px solid var(--app-border);">
            <h3 class="text-lg font-semibold mb-4 payment-modal-header" style="color: var(--app-primary);">إضافة دفعة {{ $tab === 'receivable' ? '(اللي النا)' : '(اللي علينا)' }}</h3>
            <form wire:submit.prevent="addPayment" class="payment-modal-form">
                <div class="space-y-4 payment-modal-body">
                <div>
                    <label for="paymentPartnerId" class="block text-sm font-medium mb-1">الطرف *</label>
                    <select id="paymentPartnerId" wire:model.live="paymentPartnerId" required class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                        <option value="">اختر الطرف</option>
                        @foreach($paymentPartners as $p)
                            <option value="{{ $p->id }}">{{ $p->name ?? '—' }}</option>
                        @endforeach
                    </select>
                    @error('paymentPartnerId')
                        <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                    @if($paymentPartners->isEmpty())
                        <p class="text-sm mt-1" style="color: var(--app-muted);">لا يوجد أطراف بـ رصيد في هذا التبويب.</p>
                    @endif
                </div>
                @if($paymentPartnerId)<div class="applied-filters"><div><span>الرصيد الحالي</span><b>{{ number_format($this->currentPaymentBalance,2) }}</b></div><div><span>الرصيد المتوقع بعد الدفعة</span><b>{{ number_format($this->expectedPaymentBalance,2) }}</b></div></div>@endif
                <div>
                    <label for="paymentAmount" class="block text-sm font-medium mb-1">المبلغ *</label>
                    <input type="number" id="paymentAmount" wire:model="paymentAmount" step="0.01" min="0.01" required placeholder="أدخل المبلغ" class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    @error('paymentAmount')
                        <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <div><label for="paymentNotes" class="block text-sm font-medium mb-1">ملاحظة اختيارية</label><textarea id="paymentNotes" wire:model="paymentNotes" maxlength="1000" class="w-full rounded-lg border px-4 py-2" style="background:var(--app-bg);border-color:var(--app-border);color:var(--app-text)"></textarea></div>
                <div>
                    <label for="paymentDate" class="block text-sm font-medium mb-1">تاريخ الدفعة *</label>
                    <input type="date" id="paymentDate" wire:model="paymentDate" required class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                    @error('paymentDate')
                        <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                </div>
                <div class="flex gap-2 justify-end payment-modal-actions">
                    <button type="button" wire:click="closeAddPaymentModal" class="px-4 py-2 rounded-lg" style="background: var(--app-bg); color: var(--app-text); border: 1px solid var(--app-border);">إلغاء</button>
                    <button type="submit" class="px-4 py-2 rounded-lg text-white font-medium" style="background: var(--app-primary);" @if($paymentPartners->isEmpty()) disabled @endif>إضافة</button>
                </div>
            </form>
        </div>
    </div>
    @endteleport
    @endif
    <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
            <thead>
                <tr style="background: var(--app-bg);">
                    <th class="px-4 py-3 font-medium">الاسم</th>
                    <th class="px-4 py-3 font-medium">المبلغ</th>
                    <th class="px-4 py-3 font-medium">تاريخ التحديث</th>
                    <th class="px-4 py-3 font-medium w-24">إجراء</th>
                </tr>
            </thead>
            @if($tab === 'receivable')
            <tbody wire:key="tbody-receivable">
                @forelse($receivable as $row)
                    <tr wire:key="rec-{{ $loop->index }}-{{ $row['partner']?->id ?? 0 }}" style="border-top: 1px solid var(--app-border);">
                        <td class="px-4 py-3">{{ $row['partner']?->name ?? '—' }}</td>
                        <td class="px-4 py-3 font-medium">{{ number_format($row['balance'], 2) }}</td>
                        <td class="px-4 py-3">{{ $row['updated_at'] ? \Carbon\Carbon::parse($row['updated_at'])->format('Y-m-d') : '—' }}</td>
                        <td class="px-4 py-3">
                            @if($row['partner'])
                            <div class="flex items-center gap-2">
                                <a href="{{ route('statements.show', ['partner' => $row['partner'], 'from' => $dateFrom, 'to' => $dateTo]) }}" class="ghost-button" title="عرض كشف الحساب">كشف الحساب</a>
                            <form method="POST" action="{{ route('telegram.send-ledger') }}" class="inline">
                                @csrf
                                <input type="hidden" name="partner_id" value="{{ $row['partner']->id }}">
                                <input type="hidden" name="balance" value="{{ $row['balance'] }}">
                                <input type="hidden" name="type" value="receivable">
                                <button type="submit" class="btn-telegram" title="إرسال بتيلغرام">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
                                </button>
                            </form>
                                <button type="button" wire:click="settle({{ $row['partner']->id }}, 'receivable')" data-confirm="تسوية المبلغ؟ سيتم اعتبار المبلغ مدفوعاً بالكامل."
                                    class="btn-action btn-delete" title="تسوية">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center" style="color: var(--app-muted);">لا يوجد مستحقات (اللي النا).</td>
                    </tr>
                @endforelse
            </tbody>
            @else
            <tbody wire:key="tbody-payable">
                @forelse($payable as $row)
                    <tr wire:key="pay-{{ $loop->index }}-{{ $row['partner']?->id ?? 0 }}" style="border-top: 1px solid var(--app-border);">
                        <td class="px-4 py-3">{{ $row['partner']?->name ?? '—' }}</td>
                        <td class="px-4 py-3 font-medium">{{ number_format($row['balance'], 2) }}</td>
                        <td class="px-4 py-3">{{ $row['updated_at'] ? \Carbon\Carbon::parse($row['updated_at'])->format('Y-m-d') : '—' }}</td>
                        <td class="px-4 py-3">
                            @if($row['partner'])
                            <div class="flex items-center gap-2">
                                <a href="{{ route('statements.show', ['partner' => $row['partner'], 'from' => $dateFrom, 'to' => $dateTo]) }}" class="ghost-button" title="عرض كشف الحساب">كشف الحساب</a>
                            <form method="POST" action="{{ route('telegram.send-ledger') }}" class="inline">
                                @csrf
                                <input type="hidden" name="partner_id" value="{{ $row['partner']->id }}">
                                <input type="hidden" name="balance" value="{{ $row['balance'] }}">
                                <input type="hidden" name="type" value="payable">
                                <button type="submit" class="btn-telegram" title="إرسال بتيلغرام">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
                                </button>
                            </form>
                                <button type="button" wire:click="settle({{ $row['partner']->id }}, 'payable')" data-confirm="تسوية المبلغ؟ سيتم اعتبار المبلغ مدفوعاً بالكامل."
                                    class="btn-action btn-delete" title="تسوية">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center" style="color: var(--app-muted);">لا يوجد التزامات (اللي علينا).</td>
                    </tr>
                @endforelse
            </tbody>
            @endif
        </table>
    </div>
    <div class="p-4">{{ ($tab === 'receivable' ? $receivable : $payable)->links() }}</div>
</div>
