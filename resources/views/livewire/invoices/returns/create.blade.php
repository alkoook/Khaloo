<div class="return-editor-page">
    <div class="page-heading">
        <div>
            <span class="eyebrow">مرتجعات المبيعات</span>
            <h1>{{ $this->editing ? 'تعديل المرتجع #'.$returnInvoice->id : 'إضافة مرتجع مبيعات' }}</h1>
            <p>{{ $this->editing ? 'يُطبّق التصحيح كفرق على المخزون والحساب، وتبقى فاتورة البيع الأصلية كما صدرت.' : 'سجّل الأصناف المرتجعة من دون تغيير فاتورة البيع الأصلية.' }}</p>
        </div>
        <a href="{{ route('invoices.returns.index') }}" class="secondary-button">العودة إلى المرتجعات</a>
    </div>

    @if(!$invoice)
        <section class="workspace-card return-search-card">
            <h2>البحث عن فاتورة مبيعات</h2>
            <form wire:submit="findInvoice($refs.searchInput.value)" class="return-search-form">
                <input type="number" x-ref="searchInput" placeholder="أدخل رقم الفاتورة" required>
                <button type="submit" class="primary-button">بحث</button>
            </form>
        </section>
    @else
        <form wire:submit="saveReturn" class="return-editor-form">
            <section class="workspace-card return-products-card">
                <header class="return-section-heading">
                    <div>
                        <h2>المنتجات القابلة للإرجاع</h2>
                        <p>فاتورة البيع الأصلية #{{ $invoice->id }} — {{ $invoice->partner->name ?? 'السيد النقدي' }}</p>
                    </div>
                    @if($this->editing)<span class="status-pill">مرتجع #{{ $returnInvoice->id }}</span>@endif
                </header>

                <div class="return-table-shell">
                    <table class="return-items-table">
                        <colgroup>
                            <col class="return-product-column">
                            <col span="5" class="return-number-column">
                            <col class="return-total-column">
                        </colgroup>
                        <thead><tr><th>المنتج</th><th>السعر الإفرادي</th><th>الكمية المباعة</th><th>الكمية المرتجعة سابقًا</th><th>الكمية المتاحة للإرجاع</th><th>كمية الإرجاع</th><th>إجمالي المرتجع</th></tr></thead>
                        <tbody>
                        @foreach($lines as $line)
                            <tr wire:key="return-line-{{ $line['id'] }}">
                                <td data-label="المنتج" class="return-product-name"><strong>{{ $line['product_name'] }}</strong></td>
                                <td data-label="السعر الإفرادي" class="return-numeric">{{ number_format((float) $line['effective_unit_price'], 2) }}</td>
                                <td data-label="الكمية المباعة" class="return-numeric">{{ number_format((float) $line['sold_quantity'], 3) }}</td>
                                <td data-label="الكمية المرتجعة سابقًا" class="return-numeric">{{ number_format((float) $line['returned_quantity'], 3) }}</td>
                                <td data-label="الكمية المتاحة للإرجاع" class="return-numeric">{{ number_format((float) $line['available_quantity'], 3) }}</td>
                                <td data-label="كمية الإرجاع" class="return-quantity-input"><input aria-label="كمية إرجاع {{ $line['product_name'] }}" type="number" wire:model.live="returnQuantities.{{ $line['id'] }}" min="0" step="0.001" max="{{ $line['available_quantity'] }}" @disabled((float) $line['available_quantity'] <= 0)></td>
                                <td data-label="إجمالي المرتجع" class="return-numeric return-line-total"><strong>{{ number_format((float) \App\Support\Decimal::mul($line['effective_unit_price'], $returnQuantities[$line['id']] ?? 0), 2) }}</strong></td>
                            </tr>
                            @error("returnQuantities.{$line['id']}")<tr class="return-error-row"><td colspan="7"><p class="field-error" role="alert">{{ $message }}</p></td></tr>@enderror
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="workspace-card return-details-card">
                <div class="return-section-heading">
                    <div><h2>تفاصيل المرتجع</h2><p>يوضح الملخص الفرق بين النقد المعاد وأثر المرتجع على رصيد السيد.</p></div>
                </div>
                <div class="return-details-grid">
                    <label><span>السيد</span><input value="{{ $invoice->partner->name ?? 'السيد النقدي' }}" disabled></label>
                    <label><span>تاريخ المرتجع</span><input type="date" wire:model="returnDate" min="{{ $invoice->created_at->toDateString() }}" max="{{ now()->toDateString() }}">@error('returnDate')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label>
                    <label><span>المبلغ الذي سيتم رده نقدًا</span><input type="number" step="0.01" wire:model.live="amountPaid" min="0" placeholder="0.00"><small>أدخل فقط النقد الذي سيخرج فعليًا من الصندوق.</small>@error('amountPaid')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label>
                    <label class="return-notes"><span>ملاحظات</span><textarea wire:model="notes" rows="3" placeholder="ملاحظة اختيارية عن سبب المرتجع أو التصحيح"></textarea>@error('notes')<small class="field-error" role="alert">{{ $message }}</small>@enderror</label>
                </div>

                <dl class="return-financial-summary">
                    <div><dt>قيمة المرتجع</dt><dd>{{ number_format((float) $this->returnTotal, 2) }}</dd></div>
                    <div><dt>المردود نقدًا</dt><dd>{{ number_format((float) ($amountPaid ?: 0), 2) }}</dd></div>
                    <div class="return-credit-effect"><dt>المبلغ المخصوم من رصيد السيد</dt><dd>{{ number_format((float) $this->accountCredit, 2) }}</dd></div>
                </dl>
            </section>

            <div class="return-actions">
                <div>
                    @error('return')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @error('returnQuantities')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit" wire:loading.attr="disabled" wire:target="saveReturn" class="primary-button return-save-button">
                    <span wire:loading.remove wire:target="saveReturn">{{ $this->editing ? 'حفظ التعديلات' : 'حفظ المرتجع' }}</span>
                    <span wire:loading wire:target="saveReturn">{{ $this->editing ? 'جارٍ حفظ التعديلات...' : 'جارٍ حفظ المرتجع...' }}</span>
                </button>
            </div>
        </form>
    @endif
</div>
