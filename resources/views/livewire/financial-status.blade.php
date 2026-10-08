<div class="module-page financial-status-page">
    <div class="page-heading">
        <div><span class="eyebrow">متابعة مالية تشغيلية</span><h1>الوضع المالي</h1><p>قراءة عملية لحركة الفترة، الذمم، المخزون، وجهات المال دون خلط الربح بالنقد المتبقي.</p></div>
        <button type="button" class="secondary-button" wire:click="openMovementForm">＋ تسجيل حركة جهة مال</button>
    </div>

    <section class="workspace-card financial-filters">
        <div class="filter-bar">
            <select wire:model.live="period"><option value="today">اليوم</option><option value="week">هذا الأسبوع</option><option value="month">هذا الشهر</option><option value="last_month">الشهر الماضي</option><option value="year">هذه السنة</option><option value="custom">فترة مخصصة</option></select>
            <input type="date" wire:model.live="from" @disabled($period !== 'custom')>
            <input type="date" wire:model.live="to" @disabled($period !== 'custom')>
        </div>
        <small>الفترة: {{ $from ?: 'بداية السجل' }} — {{ $to ?: 'اليوم' }}. المتبقي النقدي مؤشر لحركة Khaloo المسجلة، وليس رصيد صندوق محاسبيًا لعدم وجود رصيد افتتاحي وكل الحركات الخارجية.</small>
    </section>

    @if($summary['unclassified_count'])
        <section class="workspace-card financial-warning"><strong>تنبيه تصنيف مالي</strong><span>هناك {{ $summary['unclassified_count'] }} حركة غير مصنفة بقيمة {{ number_format($summary['unclassified_amount'],2) }} مستبعدة من الأرقام الموثوقة حتى تصنيفها.</span></section>
    @endif

    <section class="financial-hero-grid">
        <article class="financial-kpi"><small>المتبقي من أموال المبيعات</small><strong>{{ number_format($summary['cash_remaining'],2) }}</strong><span>حركة الفترة المسجلة</span></article>
        <article class="financial-kpi"><small>صافي الربح التشغيلي</small><strong>{{ $summary['net_profit'] === null ? 'غير موثوق' : number_format($summary['net_profit'],2) }}</strong><span>لا يشمل المشتريات مباشرة</span></article>
        <article class="financial-kpi"><small>صافي الوضع المالي المدعوم</small><strong>{{ $summary['net_financial_position'] === null ? 'غير موثوق' : number_format($summary['net_financial_position'],2) }}</strong><span>نقد تشغيلي + مخزون + جهات المال</span></article>
    </section>

    <section class="financial-section-grid">
        <article class="workspace-card financial-card"><h2>الأداء المالي</h2><dl>
            <div><dt>صافي المبيعات</dt><dd>{{ number_format($summary['net_sales'],2) }}</dd></div>
            <div><dt>إيراد التوصيل</dt><dd>{{ number_format($summary['delivery_income'],2) }}</dd></div>
            <div><dt>سلف الخانات الإضافية</dt><dd>{{ number_format($summary['pass_through_advances'],2) }}</dd></div>
            <div><dt>تكلفة البضاعة المباعة</dt><dd>{{ $summary['cogs'] === null ? 'غير موثوقة' : number_format($summary['cogs'],2) }}</dd></div>
            <div><dt>مجمل الربح</dt><dd>{{ $summary['gross_profit'] === null ? 'غير موثوق' : number_format($summary['gross_profit'],2) }}</dd></div>
            <div><dt>المصاريف</dt><dd>{{ number_format($summary['expenses'],2) }}</dd></div>
            <div class="emphasis"><dt>صافي الربح</dt><dd>{{ $summary['net_profit'] === null ? 'غير موثوق' : number_format($summary['net_profit'],2) }}</dd></div>
            <div><dt>إجمالي المشتريات</dt><dd>{{ number_format($summary['purchases'],2) }}</dd></div>
        </dl><small>COGS من لقطة سعر الشراء في بند البيع. المرتجعات العائدة لفاتورة أقدم من الفترة تُعكس ضمن فترة المرتجع.</small></article>
        <article class="workspace-card financial-card"><h2>أين ذهبت الأموال؟</h2><dl>
            <div><dt>المبالغ المحصلة من العملاء</dt><dd>{{ number_format($summary['sales_collected'],2) }}</dd></div>
            <div><dt>المرتجعات المدفوعة</dt><dd>− {{ number_format($summary['refunds'],2) }}</dd></div>
            <div><dt>المشتريات النقدية المباشرة</dt><dd>− {{ number_format($summary['direct_cash_purchases'],2) }}</dd></div>
            <div><dt>تسديدات الموردين</dt><dd>− {{ number_format($summary['supplier_payments'],2) }}</dd></div>
            <div><dt>مبالغ معادة من الموردين</dt><dd>+ {{ number_format($summary['supplier_refunds'],2) }}</dd></div>
            <div><dt>المصاريف</dt><dd>− {{ number_format($summary['expenses'],2) }}</dd></div>
            <div><dt>جهات المال (خارج)</dt><dd>− {{ number_format($summary['money_to_parties'],2) }}</dd></div>
            <div><dt>جهات المال (عائد)</dt><dd>+ {{ number_format($summary['money_from_parties'],2) }}</dd></div>
            <div><dt>سحوبات المالك</dt><dd>− {{ number_format($summary['owner_withdrawals'],2) }}</dd></div>
            <div class="emphasis"><dt>المتبقي</dt><dd>{{ number_format($summary['cash_remaining'],2) }}</dd></div>
        </dl></article>
        <article class="workspace-card financial-card"><h2>المشتريات والمخزون</h2><dl>
            <div><dt>إجمالي المشتريات للفترة</dt><dd>{{ number_format($summary['purchases'],2) }}</dd></div>
            <div class="emphasis"><dt>قيمة المخزون الحالية بالتكلفة</dt><dd>{{ $summary['inventory_value'] === null ? 'غير موثوقة' : number_format($summary['inventory_value'],2) }}</dd></div>
        </dl><small>القيمة الحالية تستخدم سعر الشراء الحالي للمنتج، وليست FIFO أو متوسطًا مرجحًا.</small></article>
        <article class="workspace-card financial-card"><h2>جهات المال</h2><dl>
            <div><dt>أموالنا لدى الجهات</dt><dd>{{ number_format($summary['money_at_parties'],2) }}</dd></div>
            <div><dt>التزاماتنا تجاه الجهات</dt><dd>{{ number_format($summary['money_party_liabilities'],2) }}</dd></div>
            <div class="emphasis"><dt>صافي أرصدة الجهات</dt><dd>{{ number_format($summary['money_party_net'],2) }}</dd></div>
        </dl></article>
        <article class="workspace-card financial-card"><h2>الذمم الحالية</h2><dl>
            <div><dt>المتبقي على العملاء</dt><dd>{{ number_format($summary['receivables'],2) }}</dd></div>
            <div><dt>مبالغ مستحقة للعملاء</dt><dd>{{ number_format($summary['customer_credits'],2) }}</dd></div>
            <div class="emphasis"><dt>المستحق للموردين</dt><dd>{{ number_format($summary['payables'],2) }}</dd></div>
        </dl></article>
        <article class="workspace-card financial-card financial-position"><h2>المركز المالي الحالي</h2><strong>{{ $summary['net_financial_position'] === null ? 'غير موثوق' : number_format($summary['net_financial_position'],2) }}</strong><p>المتبقي من أموال المبيعات + قيمة المخزون الحالية + أموال المشروع لدى الجهات − الالتزامات تجاهها. لا يضاف صافي الربح مرة ثانية.</p></article>
        @if($summary['unknown_cost_lines'] || $summary['unknown_inventory_count'])<article class="workspace-card"><strong>تنبيه تكلفة</strong><p>توجد تكلفة شراء غير موثقة؛ لذلك لم يفترض النظام تكلفة صفرية وأوقف الأرقام المتأثرة.</p></article>@endif
    </section>

    <section class="workspace-card financial-card"><h2>أرصدة جهات المال الحالية</h2><div class="table-shell"><table><thead><tr><th>الجهة</th><th>الرصيد</th><th>المعنى</th></tr></thead><tbody>@forelse($partyBalances as $row)<tr><td>{{ $row['party']->name }}</td><td>{{ number_format($row['balance'],2) }}</td><td>{{ $row['balance'] >= 0 ? 'أموال المشروع لدى الجهة' : 'التزام تجاه الجهة' }}</td></tr>@empty<tr><td colspan="3"><div class="empty-state">لا توجد جهات مال مسجلة.</div></td></tr>@endforelse</tbody></table></div></section>

    <section class="workspace-card financial-card"><h2>أحدث حركات جهات المال</h2><div class="table-shell"><table><thead><tr><th>التاريخ</th><th>الجهة</th><th>الاتجاه</th><th>التصنيف</th><th>المبلغ</th><th>الحالة</th></tr></thead><tbody>@forelse($movements as $movement)<tr><td>{{ $movement->date->format('Y-m-d') }}</td><td>{{ $movement->moneyParty->name }}</td><td>{{ $movement->direction === 'to_party' ? 'إلى الجهة' : 'من الجهة' }}</td><td>{{ ['purchase_advance'=>'دفعة مقدمة للشراء','purchase_compensation'=>'تعويض شراء','owner_withdrawal'=>'سحب صاحب المشروع','returned_from_party'=>'مبلغ معاد من الجهة','other'=>'أخرى','unclassified'=>'غير مصنف'][$movement->category] }}</td><td>{{ number_format($movement->amount_base_currency,2) }}</td><td>{{ $movement->status === 'active' ? 'نشطة' : 'ملغاة' }}</td></tr>@empty<tr><td colspan="6"><div class="empty-state">لا توجد حركات ضمن الفترة.</div></td></tr>@endforelse</tbody></table></div></section>

    @if($showMovementForm)<div class="modal-backdrop" wire:click.self="$set('showMovementForm',false)"><section class="workspace-card financial-movement-modal"><header><h2>تسجيل حركة جهة مال</h2><button type="button" class="icon-button" wire:click="$set('showMovementForm',false)">×</button></header><form wire:submit="saveMovement" class="form-grid"><label>جهة موجودة<select wire:model="money_party_id"><option value="">اختر جهة</option>@foreach($parties as $party)<option value="{{ $party->id }}">{{ $party->name }}</option>@endforeach</select></label><label>أو جهة جديدة<input wire:model="new_party_name" maxlength="120"></label><label>الاتجاه<select wire:model="direction"><option value="to_party">إلى الجهة</option><option value="from_party">من الجهة</option></select></label><label>التصنيف<select wire:model="category"><option value="purchase_advance">دفعة مقدمة للشراء</option><option value="purchase_compensation">تعويض شراء</option><option value="owner_withdrawal">سحب صاحب المشروع</option><option value="returned_from_party">مبلغ معاد من الجهة</option><option value="other">أخرى</option><option value="unclassified">غير مصنف</option></select></label><label>المبلغ<input type="number" min="0.01" step="0.01" wire:model="amount"></label><label>التاريخ<input type="date" wire:model="date"></label><label class="form-wide">ملاحظات<textarea wire:model="notes" maxlength="1000"></textarea></label><div class="form-wide modal-actions"><button type="button" class="secondary-button" wire:click="$set('showMovementForm',false)">إلغاء</button><button class="primary-button">حفظ الحركة</button></div></form></section></div>@endif
</div>
