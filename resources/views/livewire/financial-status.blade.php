<div class="module-page financial-status-page" dir="rtl">
    <div class="page-heading">
        <div>
            <span class="eyebrow">خلو — متابعة المتجر</span>
            <h1>الوضع المالي</h1>
            <p>افصل ربح النشاط عن حركة النقد، وتابع الذمم والمخزون الحاليين.</p>
        </div>
    </div>

    <section class="workspace-card financial-filters" aria-label="تصفية نشاط الفترة">
        <div class="filter-bar">
            <label>الفترة
                <select wire:model.live="period">
                    <option value="today">اليوم</option><option value="week">هذا الأسبوع</option>
                    <option value="month">هذا الشهر</option><option value="last_month">الشهر الماضي</option>
                    <option value="year">هذه السنة</option><option value="custom">فترة مخصصة</option>
                </select>
            </label>
            <label>من تاريخ<input type="date" wire:model.live="from" @disabled($period !== 'custom')></label>
            <label>إلى تاريخ<input type="date" wire:model.live="to" @disabled($period !== 'custom')></label>
        </div>
        <small>المبيعات والمشتريات بتاريخ الفاتورة، والمرتجعات بتاريخ حدوثها، والتسويات بتاريخ الدفع، والمصاريف بتاريخ المصروف.</small>
        @if($dateError)<p class="financial-date-error" role="alert">{{ $dateError }}</p>@endif
    </section>

    @if($summary)
        @php
            $money = fn ($value) => $value === null ? 'غير متاح' : \App\Support\Decimal::format($value);
            $nonzero = fn ($value) => \App\Support\Decimal::cmp($value, 0) !== 0;
        @endphp
        @if($summary['unknown_cost_lines'] || $summary['unknown_inventory_count'])
            <section class="workspace-card financial-warning" role="status">
                <strong>تكلفة غير موثقة</strong>
                <p>لا تُعامل التكلفة المفقودة كصفر؛ الربح أو قيمة المخزون المتأثرة غير متاحة حتى توثيق التكلفة.</p>
            </section>
        @endif

        <section class="financial-group" aria-labelledby="financial-period-heading">
            <header class="financial-group-heading">
                <div><span class="eyebrow">نشاط الفترة المحددة</span><h2 id="financial-period-heading">من {{ $from }} إلى {{ $to }}</h2></div>
                <p>هذه الأرقام تتغير عند تغيير الفترة.</p>
            </header>
            <div class="financial-section-grid">
                <article class="workspace-card financial-card">
                    <h3>الربحية</h3>
                    <dl>
                        @foreach([
                            'net_sales' => 'صافي مبيعات البضاعة',
                            'delivery_income' => 'إيرادات التوصيل',
                            'cogs' => 'تكلفة البضاعة المباعة',
                            'gross_profit' => 'مجمل الربح',
                            'expenses' => 'المصاريف التشغيلية',
                            'net_profit' => 'صافي الربح التشغيلي',
                        ] as $key => $label)
                            <div @class(['emphasis' => $key === 'net_profit'])><dt>{{ $label }}</dt><dd>{{ $money($summary[$key]) }}</dd></div>
                        @endforeach
                    </dl>
                    <small>المبيعات بعد الخصومات والمرتجعات + التوصيل − تكلفة البضاعة المباعة − المصاريف. المشتريات والمبالغ العابرة ليست مصروفاً في معادلة الربح.</small>
                </article>

                <article class="workspace-card financial-card">
                    <h3>السيولة والحركة النقدية</h3>
                    <dl>
                        <div><dt>إجمالي المقبوضات</dt><dd>{{ $money($summary['cash_inflows']) }}</dd></div>
                        <div><dt>إجمالي المدفوعات</dt><dd>{{ $money($summary['cash_outflows']) }}</dd></div>
                        <div class="emphasis"><dt>صافي الحركة النقدية</dt><dd>{{ $money($summary['cash_remaining']) }}</dd></div>
                    </dl>
                    @if($nonzero($summary['sales_paid_at_issue']) || $nonzero($summary['additional_collections']) || $nonzero($summary['refunds']) || $nonzero($summary['supplier_refunds']))
                        <details class="financial-detail">
                            <summary>تفصيل تحصيل العملاء والمبالغ المعادة</summary>
                            <dl>
                                @foreach([
                                    'sales_paid_at_issue' => 'مدفوع العملاء عند إنشاء البيع',
                                    'additional_collections' => 'تحصيل ذمم العملاء لاحقاً',
                                    'refunds' => 'مبالغ معادة للعملاء',
                                    'supplier_refunds' => 'مبالغ مستلمة من الموردين',
                                ] as $key => $label)
                                    @if($nonzero($summary[$key]))<div><dt>{{ $label }}</dt><dd>{{ $money($summary[$key]) }}</dd></div>@endif
                                @endforeach
                            </dl>
                        </details>
                    @endif
                    <small>التحصيل ليس مبيعات جديدة، وتسديد المورد ليس شراءً جديداً. تُحسب الدفعة مرة واحدة بتاريخها، وتشمل المدفوعات المصاريف والسلف العابرة.</small>
                </article>

                @if($summary['purchase_count'] || $nonzero($summary['purchase_payments']))
                    <article class="workspace-card financial-card">
                        <h3>المشتريات</h3>
                        <dl>
                            <div><dt>إجمالي المشتريات خلال الفترة</dt><dd>{{ $money($summary['purchases']) }}</dd></div>
                            <div><dt>المدفوع للموردين خلال الفترة</dt><dd>{{ $money($summary['purchase_payments']) }}</dd></div>
                            @if($nonzero($summary['purchase_credit_created']))
                                <div><dt>المتبقي عند إنشاء فواتير الفترة</dt><dd>{{ $money($summary['purchase_credit_created']) }}</dd></div>
                            @endif
                        </dl>
                        <small>المدفوع يشمل دفعات الشراء والتسويات المؤرخة خلال الفترة، وقد يسدد مشتريات أقدم. المتبقي عند الإنشاء قبل التسويات، وليس الرصيد الحالي. <a href="#financial-suppliers">المتبقي للموردين بعد التسويات ضمن الذمم الحالية.</a></small>
                        <small>الشراء يزيد المخزون، ويصبح تكلفة بضاعة مباعة فقط عند بيع الأصناف.</small>
                    </article>
                @endif

                @if($nonzero($summary['pass_through_advances']))
                    <article class="workspace-card financial-card financial-pass-through">
                        <h3>المبالغ العابرة</h3>
                        <dl><div><dt>سلف مدفوعة نيابة عن العملاء</dt><dd>{{ $money($summary['pass_through_advances']) }}</dd></div></dl>
                        <small>تُخصم نقداً عند إصدار الفاتورة، ويُدرج استردادها ضمن مقبوضات العميل مرة واحدة. ليست إيراداً أو مصروفاً أو مخزوناً؛ أثرها النهائي صفر عند استردادها بالكامل. لا تُخصص الدفعات الجزئية تلقائياً بين البضاعة والسلف.</small>
                    </article>
                @endif
            </div>
            @if($summary['sale_count'] || $summary['purchase_count'] || $summary['return_count'])
                <article class="workspace-card financial-activity">
                    <h3>النشاط خلال الفترة</h3>
                    <ul>
                        @if($summary['sale_count'])<li>فواتير بيع <strong>{{ $summary['sale_count'] }}</strong></li>@endif
                        @if($summary['purchase_count'])<li>فواتير شراء <strong>{{ $summary['purchase_count'] }}</strong></li>@endif
                        @if($summary['return_count'])
                            <li>مرتجعات <strong>{{ $summary['return_count'] }}</strong></li>
                            <li>قيمة البضاعة المرتجعة <strong>{{ $money($summary['returns_total']) }}</strong></li>
                        @endif
                    </ul>
                    <small>الفواتير الملغاة مستبعدة؛ قيمة المرتجعات خُصمت بالفعل من المبيعات ولا تُخصم ثانية.</small>
                </article>
            @endif
        </section>

        <section class="financial-group financial-current" aria-labelledby="financial-current-heading">
            <header class="financial-group-heading">
                <div><span class="eyebrow">الوضع الحالي</span><h2 id="financial-current-heading">الأرصدة الحالية حتى {{ $summary['position_date'] }}</h2></div>
                <p>مستقلة عن الفترة المختارة؛ المخزون هو الرصيد المسجل الآن.</p>
            </header>
            <div class="workspace-card financial-cash-position">
                <div><h3>الرصيد النقدي المحسوب</h3><p>تراكمي من الحركات المسجلة حتى اليوم، دون رصيد افتتاحي. ليس تأكيداً لرصيد الصندوق الفعلي.</p></div>
                <strong>{{ $money($summary['current_cash_position']) }}</strong>
            </div>
            <div class="financial-section-grid">
                <article class="workspace-card financial-card">
                    <h3>ذمم العملاء</h3>
                    @if($nonzero($summary['customer_invoiced']) || $nonzero($summary['customer_collected']) || $nonzero($summary['receivables']) || $nonzero($summary['customer_credits']))
                        <dl>
                            <div><dt>إجمالي المبالغ المستحقة على العملاء</dt><dd>{{ $money($summary['customer_invoiced']) }}</dd></div>
                            <div><dt>إجمالي المبالغ المحصلة</dt><dd>{{ $money($summary['customer_collected']) }}</dd></div>
                            <div class="emphasis"><dt>الرصيد المتبقي</dt><dd>{{ $money($summary['receivables']) }}</dd></div>
                            @if($nonzero($summary['customer_credits']))<div><dt>أرصدة مستحقة للعملاء</dt><dd>{{ $money($summary['customer_credits']) }}</dd></div>@endif
                        </dl>
                    @else
                        <p class="financial-empty">لا توجد ذمم أو تحصيلات مسجلة للعملاء.</p>
                    @endif
                    <small>فواتير العملاء المسجلين بعد المرتجعات، بما فيها التوصيل والمبالغ العابرة. المحصل يشمل دفعة الفاتورة والتسويات بعد طرح الاستردادات. أرصدة العملاء الدائنة لا تُقاص مع ديون عملاء آخرين.</small>
                </article>

                <article class="workspace-card financial-card" id="financial-suppliers">
                    <h3>ذمم الموردين</h3>
                    @if($nonzero($summary['supplier_invoiced']) || $nonzero($summary['supplier_paid']) || $nonzero($summary['supplier_adjustments']) || $nonzero($summary['payables']) || $nonzero($summary['supplier_credits']))
                        <dl>
                            <div><dt>إجمالي المستحق للموردين</dt><dd>{{ $money($summary['supplier_invoiced']) }}</dd></div>
                            <div><dt>إجمالي المدفوع للموردين</dt><dd>{{ $money($summary['supplier_paid']) }}</dd></div>
                            @if($nonzero($summary['supplier_adjustments']))<div><dt>تسويات دائنة أخرى للموردين</dt><dd>{{ $money($summary['supplier_adjustments']) }}</dd></div>@endif
                            <div class="emphasis"><dt>الرصيد المتبقي</dt><dd>{{ $money($summary['payables']) }}</dd></div>
                            @if($nonzero($summary['supplier_credits']))<div><dt>أرصدة المتجر لدى الموردين</dt><dd>{{ $money($summary['supplier_credits']) }}</dd></div>@endif
                        </dl>
                    @else
                        <p class="financial-empty">لا توجد ذمم أو دفعات مسجلة للموردين.</p>
                    @endif
                    <small>الفواتير الفعالة ودفعاتها والتسويات حتى اليوم. تبقى معالجة التسويات الدائنة كما هي في كشف المورد؛ لا تُسجل شراءً أو ربحاً جديداً.</small>
                </article>

                <article class="workspace-card financial-card">
                    <h3>المخزون</h3>
                    @if($summary['product_count'])
                        <dl>
                            <div><dt>قيمة المخزون الحالية بالتكلفة</dt><dd>{{ $money($summary['inventory_value']) }}</dd></div>
                            <div><dt>عدد الأصناف</dt><dd>{{ $summary['product_count'] }}</dd></div>
                            <div><dt>الأصناف التي نفد مخزونها</dt><dd>{{ $summary['out_of_stock_count'] }}</dd></div>
                            @if($summary['unknown_inventory_count'])<div><dt>أصناف بتكلفة غير معروفة</dt><dd>{{ $summary['unknown_inventory_count'] }}</dd></div>@endif
                        </dl>
                    @else
                        <p class="financial-empty">لا توجد أصناف مسجلة.</p>
                    @endif
                    <small>الكمية الحالية × متوسط التكلفة المرجح الحالي. تكلفة البضاعة المباعة تُؤخذ من تكلفة بنود الفاتورة التاريخية، لا من تكلفة المخزون الحالية.</small>
                </article>
            </div>
        </section>
    @endif
</div>
