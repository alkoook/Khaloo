# تدقيق خلو: الكميات العشرية والوضع المالي

تم العمل حصراً داخل `C:\Users\S I N O\Desktop\projects\Khaloo-main` بعد التحقق من الجذر. لم يُستخدم مشروع آخر، ولم تتغير إعدادات الاتصال أو بيانات الفواتير الحقيقية أثناء الاختبار.

## المشاكل السابقة

- أعمدة الكميات وتحويلاتها وقواعد التحقق كانت صحيحة العدد؛ الكسور كانت تُرفض أو تضيع في الإنشاء والتعديل والمرتجع والعرض.
- الحسابات الحساسة استخدمت أعداد PHP العائمة وجمع المجموعات غير الدقيق.
- الملخص جمع نقد الفترة مع مخزون وأرصدة حالية في مؤشر واحد، وأغفل ذمم العملاء والموردين في المركز المالي.
- المرتجع يخفض الفاتورة الأصلية فعلياً؛ التقارير القديمة لم تستعد قيمة الإصدار عند عرض فترة تسبق المرتجع، ولم تعالج كل حالات كشف الحساب بين الفترات.
- ربح الفاتورة لم يكن معروضاً مستقلاً. تقارير الربح لم تتضمن التوصيل.
- استعادة مخزون البيع عند المرتجع أو الإلغاء لم تعِد احتساب المتوسط بتكلفة البيع التاريخية عندما يتغير المتوسط الحالي.
- أسماء التصدير استخدمت لاحقة تقنية وحقل «الطرف» العام، وأظهرت بعض الفلاتر حتى في التقارير التي لا تستخدمها.

## المعادلات والسياسات النهائية

- صافي البضاعة = مجموع بنود منتجات خلو بعد توزيع الحسم الحالي بدقة القروش.
- تكلفة البضاعة المباعة = مجموع الكمية × لقطة تكلفة الشراء الموجودة في بند البيع؛ لا تُستخدم تكلفة المنتج الحالية لحساب الربح التاريخي.
- مجمل الربح = صافي البضاعة + التوصيل − تكلفة البضاعة المباعة.
- صافي الربح التشغيلي = مجمل الربح − مصاريف الفترة.
- ربح الفاتورة = صافي بضاعتها المتبقية + التوصيل − التكلفة التاريخية للكمية المتبقية؛ دون المصاريف العامة أو الخانات الإضافية.
- هامش الفاتورة = ربح الفاتورة ÷ (صافي البضاعة + التوصيل) × 100؛ لا يُعرض عند انعدام المقام أو غياب تكلفة موثوقة.
- المطلوب من السيد = صافي البضاعة + التوصيل + الخانات الإضافية.
- ذمة السيد = المطلوب المتبقي بعد المرتجعات − دفعة الإنشاء − التحصيلات اللاحقة + المبالغ المعادة فعلياً للسيد؛ الرصيد السالب رصيد مستحق للسيد.
- حركات الموردين بقيت وفق تصنيف الدفتر الحالي؛ دفعات المورد لا تنشئ شراءً جديداً.
- المقبوضات = دفعات البيع عند الإنشاء + تحصيل ذمم العملاء + المبالغ المعادة من الموردين + المبالغ العائدة من جهات المال.
- المدفوعات = الاسترداد النقدي للعملاء + دفع الشراء المباشر من المتجر + تسديد الموردين + المصاريف + المبالغ إلى جهات المال + سحوبات المالك + سلف الخانات الإضافية.
- صافي الحركة النقدية = المقبوضات − المدفوعات. الشراء الممول من جهة مال لا يُخصم مرة ثانية من نقد المتجر.
- النقد الحالي = الحركة التراكمية المسجلة حتى اليوم، وليس حركة الفترة المختارة. لا يتوفر رصيد افتتاحي للصندوق؛ توضح الصفحة أن المؤشر وفق السجلات وليس جرداً مؤكداً للنقد.
- المركز الحالي = النقد التراكمي + المخزون + ذمم العملاء + أرصدة المتجر لدى الموردين والجهات − مستحقات الموردين − أرصدة العملاء − التزامات الجهات. لا يضاف الربح مرة ثانية.
- قيمة المخزون = الكمية الحالية × متوسط تكلفة الشراء المرجح. التكلفة المجهولة لا تُعامل كصفر موثوق؛ المؤشرات المتأثرة تظهر غير موثوقة.
- متوسط الشراء = (قيمة المخزون السابق + صافي الشراء بعد الحسم) ÷ الكمية الجديدة، بدقة أربع منازل.
- عند استعادة بضاعة مباعة: المتوسط = (الكمية الحالية × المتوسط الحالي + الكمية المعادة × لقطة تكلفة البيع التاريخية) ÷ الكمية بعد الاستعادة. أي جزء مجهول التكلفة يبقي التقييم غير موثوق.

## التواريخ والمبالغ العابرة

يعيد `SalesAccountingService` بناء حدث البيع الأصلي من البضاعة المتبقية والمرتجعات المرتبطة، ثم يعكس كل مرتجع في تاريخه مرة واحدة. يستخدم الملخص والتقارير ولوحة المتابعة هذه الأحداث. ملخص تصدير المبيعات وتفاصيله متطابقان حتى إذا حدث المرتجع بعد الفترة المطلوبة.

التسويات بتاريخ `settlement_date`، والمصاريف بتاريخ المصروف. لا تُعدّل دفعة إنشاء فاتورة سابقة لتسجيل دفعة جديدة اليوم؛ يوجد منع على الخادم وإرشاد في واجهة التعديل لاستخدام سجل الدفعات. لا يُسمح بإنشاء ذمة عبر تعديل فاتورة نقدية بلا طرف.

سلفة الخانات الإضافية تُعتبر مدفوعة عند إصدار فاتورة البيع كما في نموذج المشروع الحالي. تُخصم مرة واحدة نقداً، بينما تحصيل السيد يشمل المطلوب كاملاً. لا يوجد تخصيص مصطنع للدفعات الجزئية بين البضاعة والتوصيل والسلفة. عند الاسترداد الكامل، أثر السلفة النقدي صفر. لم يُنشأ شراء أو مورد أو مصروف أو حركة مخزون للخانات الإضافية.

الفواتير الملغاة مستبعدة من المجاميع النشطة؛ بقيت أسباب الإلغاء وسجلاته وحماية منع التكرار. النقد المعاد في مرتجع ذي سيد يُحسب من التسوية فقط؛ المرتجع النقدي بلا سيد يُحسب من سجل المرتجع، فلا يُخصم مرتين.

## الدقة والمخطط والترقية الفعلية

الحسابات تستخدم `App\Support\Decimal` المبني على `brick/math` الموجود في اعتمادات المشروع. الحسابات والمقارنات الحساسة تعيد سلاسل عشرية؛ الكمية ثلاث منازل، المبلغ منزلتان، والتكلفة أربع. حاصل الكمية × التكلفة يُجمع داخلياً حتى سبع منازل قبل التقريب النقدي. الكسور التي تتجاوز ثلاث منازل تُرفض، ولا تُقطع بصمت. توزيع الحسم يحافظ على إجمالي القروش، والمرتجعات المتتالية تحفظ بقية قيمة السطر حتى آخر كمية.

عُدلت ملفات الإنشاء الأصلية فقط:

- `products.quantity`: من عدد صحيح غير سالب إلى `decimal(14,3)`، مع القيمة الافتراضية صفر.
- `invoice_lines.quantity`: إلى `decimal(14,3)`.
- `quotation_lines.quantity`: إلى `decimal(14,3)` لأن العرض يتحول إلى فاتورة بيع.
- كميات الخانات الإضافية كانت عشرية بثلاث منازل مسبقاً ولم يحتج جدولها إلى تغيير.

لم تُنشأ أي migration ترقيعية. أضيف أمر `khaloo:decimal-quantities` لفحص قواعد التطوير الموجودة، و`--apply` لترقية نوع الأعمدة دون حذف البيانات بعد حفظ نسخة الكميات ومقارنة كل معرّف وكمية قبل وبعد.

طُبق الأمر على اتصال MySQL الحالي بنجاح، وتأكدت الأعمدة الثلاثة `decimal(14,3)` مع بقاء جميع المعرفات والكميات. إعادة تطبيقه لم تنفذ تعديلاً آخر. نسخة الكميات، وليست نسخة كاملة لقاعدة البيانات:

`storage/app/private/backups/decimal-quantities-20261011-003404-672729.json`

## الملفات المضافة أو المعدلة في هذا الطلب

هذه القائمة تخص هذا التنفيذ، ولا تنسب تعديلات الإعدادات أو CSS أو القص الموجودة سابقاً إلى هذا الطلب.

### الخدمات والنماذج والأمر

- `app/Support/Decimal.php` — جديد.
- `app/Services/SalesAccountingService.php` — جديد.
- `app/Services/InventoryCostService.php` — جديد.
- `app/Console/Commands/DecimalQuantities.php` — جديد.
- `app/Services/InvoicePricingService.php`
- `app/Services/InvoiceService.php`
- `app/Services/ReturnService.php`
- `app/Services/InvoiceCancellationService.php`
- `app/Services/FinancialService.php`
- `app/Services/FinancialStatusService.php`
- `app/Services/QuotationService.php`
- `app/Services/ReportService.php`
- `app/Services/ExportFileService.php`
- `app/Models/Product.php`
- `app/Models/Invoice.php`
- `app/Models/InvoiceLine.php`
- `app/Models/QuotationLine.php`

### المتحكمات والمكونات

- `app/Http/Controllers/InvoiceController.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Livewire/FinancialStatus.php`
- `app/Livewire/Invoices/Sales/Create.php`
- `app/Livewire/Invoices/Sales/Index.php`
- `app/Livewire/Invoices/Purchases/Create.php`
- `app/Livewire/Invoices/Purchases/Index.php`
- `app/Livewire/Invoices/Edit.php`
- `app/Livewire/Invoices/Returns/Create.php`
- `app/Livewire/Ledger/Index.php`
- `app/Livewire/Payments/Register.php`
- `app/Livewire/Partners/Index.php`
- `app/Livewire/Products/Edit.php`
- `app/Livewire/Quotations/Index.php`

### ملفات الإنشاء الأصلية

- `database/migrations/2026_02_26_112915_create_products_table.php`
- `database/migrations/2026_02_26_112917_create_invoice_lines_table.php`
- `database/migrations/2026_08_30_000001_create_quotations_table.php`

### العرض

- `resources/views/invoices/show.blade.php`
- `resources/views/invoices/receipt.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/livewire/financial-status.blade.php`
- `resources/views/livewire/invoices/sales/create.blade.php`
- `resources/views/livewire/invoices/purchases/create.blade.php`
- `resources/views/livewire/invoices/edit.blade.php`
- `resources/views/livewire/invoices/returns/create.blade.php`
- `resources/views/livewire/products/edit.blade.php`
- `resources/views/livewire/quotations/index.blade.php`
- `resources/views/livewire/reports/index.blade.php`
- `resources/views/quotations/print.blade.php`

### الاختبارات والتوثيق

- `tests/Feature/DecimalAccountingTest.php` — جديد، 13 اختباراً.
- `tests/Feature/CriticalAccountingAuditTest.php`
- `tests/Feature/CriticalFlowsTest.php`
- `tests/Feature/FinancialStatusTest.php`
- `tests/Feature/InvoiceCancellationAuditTest.php`
- `tests/Feature/PurchaseInvoiceLifecycleTest.php`
- `tests/Feature/PurchaseQuotationLedgerRebuildTest.php`
- `tests/Feature/ReturnBusinessRulesTest.php`
- `tests/Feature/SalesInvoiceChargesTest.php`
- `tests/Feature/SalesProductAvailabilityTest.php`
- `tests/Feature/V3FlowsTest.php`
- `docs/khaloo-accounting-decimals-audit.md`

ملفات CSS والتصميم الحراري والقص و`.env` لم تتغير بهذا الطلب. أُعيد توليد مخرجات `public/build` المعتادة.

## التحقق النهائي

أُجريت اختبارات الإنشاء والتعديل والمرتجع والإلغاء على SQLite مؤقتة، وليس على فواتير العمل الحقيقية. شملت الكميات 0.5 و1.25 و2.750، المخزون الكسري، المتوسط المرجح، الربح التاريخي، التوصيل، السلف، التحصيل الجزئي والكامل، تسديد المورد، المصاريف، الفترات، كشف الحساب، الترقية القابلة للتكرار، عرض الفاتورة والإيصال، وأسماء ومحتوى ملفات إكسل.

- المجموعة الكاملة: **119 اختباراً ناجحاً، 819 تحققاً، صفر إخفاقات**.
- أضيف 13 اختباراً؛ التوقعات القديمة عُدلت لأن الواجهات الحسابية أصبحت تعيد سلاسل عشرية، دون إلغاء فحص القيم.
- `npm run build`: ناجح.
- فحص التنسيق للخدمات الأساسية والأمر والاختبارات الجديدة: ناجح.
- `git diff --check`: ناجح.
- تشغيل الملخص على اتصال MySQL الحالي: ناجح.
- ترقية الأعمدة الحالية والتحقق وإعادة التطبيق دون تعديل: ناجح.
- الإيصال اختُبر بالعرض عبر اختبارات HTTP؛ لم تُنفذ طباعة ورقية فعلية.

أمر الاختبارات المستخدم:

```powershell
& 'C:\php-8.5\php.exe' -d 'extension_dir=C:\php-8.5\ext' -d 'extension=php_pdo_sqlite.dll' -d 'extension=php_sqlite3.dll' vendor/bin/pest --compact --colors=never
```
