<?php

use App\Livewire\Invoices\Sales\Create as CreateSale;
use App\Livewire\MessageToYou;
use App\Livewire\Quotations\Index as QuotationsIndex;
use App\Models\Category;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Models\User;
use App\Services\AlertService;
use App\Services\QuotationService;
use App\Services\ReportService;
use Livewire\Livewire;

function v3Product(int $quantity=10): Product
{
    $category=Category::query()->create(['name'=>'اختبار V3']);
    return Product::query()->create(['category_id'=>$category->id,'name'=>'منتج V3','purchase_price'=>5,'sale_price'=>10,'quantity'=>$quantity,'alert_stock_at'=>2]);
}

test('fully paid sale may be anonymous while debt requires a customer', function () {
    $user=User::factory()->create();$product=v3Product();
    Livewire::actingAs($user)->test(CreateSale::class)->call('selectProduct',$product->id)->set('amount_paid','10')->call('save');
    expect(Invoice::query()->whereNull('partner_id')->where('type','sale')->count())->toBe(1)->and($product->fresh()->quantity)->toBe(9);

    $second=v3Product();
    Livewire::actingAs($user)->test(CreateSale::class)->call('selectProduct',$second->id)->set('amount_paid','0')->call('save')->assertHasErrors(['partner_id']);
});

test('quotation lifecycle does not touch stock until explicit conversion', function () {
    $product=v3Product(7);$customer=Partner::query()->create(['name'=>'عميل عرض','type'=>Partner::TYPE_CUSTOMER]);$service=app(QuotationService::class);
    $quotation=$service->save(['partner_id'=>$customer->id,'quotation_date'=>now()->toDateString(),'expires_at'=>now()->addWeek()->toDateString(),'status'=>'accepted','discount'=>0], [['product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>2,'unit_price'=>10]]);
    expect($product->fresh()->quantity)->toBe(7);
    $invoice=$service->convertToSale($quotation,20);
    expect($product->fresh()->quantity)->toBe(5)->and($invoice->type)->toBe(Invoice::TYPE_SALE)->and($quotation->fresh()->status)->toBe('converted');
    $same=$service->convertToSale($quotation->fresh(),20);
    expect($same->id)->toBe($invoice->id)->and($product->fresh()->quantity)->toBe(5);
});

test('stock alerts deduplicate reset and trigger again after recrossing threshold', function () {
    $product=v3Product(0);$alerts=app(AlertService::class);
    $alerts->evaluateProducts($product->id);$alerts->evaluateProducts($product->id);
    expect(InAppNotification::query()->where('type','stock_zero')->count())->toBe(1);
    $product->update(['quantity'=>8]);$alerts->evaluateProducts($product->id);
    $product->update(['quantity'=>0]);$alerts->evaluateProducts($product->id);
    expect(InAppNotification::query()->where('type','stock_zero')->count())->toBe(2);
});

test('report service creates a real xlsx workbook and account statement', function () {
    $product=v3Product();$partner=Partner::query()->create(['name'=>'عميل تقرير','type'=>Partner::TYPE_CUSTOMER]);$reports=app(ReportService::class);
    $report=$reports->export('inventory',[]);$statement=$reports->statement($partner);
    expect(is_file($report))->toBeTrue()->and(file_get_contents($report,false,null,0,2))->toBe('PK')->and(is_file($statement))->toBeTrue();
    @unlink($report);@unlink($statement);
});

test('arabic statement message and detailed invoice workbook contain no exposed movement codes', function () {
    $product=v3Product(); $partner=Partner::query()->create(['name'=>'عميل عربي','type'=>Partner::TYPE_CUSTOMER,'phone'=>'0990000000']);
    $invoice=Invoice::query()->create(['partner_id'=>$partner->id,'type'=>Invoice::TYPE_SALE,'total'=>20,'initial_paid'=>5,'amount_paid'=>5]);
    InvoiceLine::query()->create(['invoice_id'=>$invoice->id,'product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>2,'unit_price'=>10,'purchase_price'=>5,'line_total'=>20]);
    $reports=app(ReportService::class); $message=$reports->statementMessage($partner); $filters=['partner_id'=>$partner->id,'from'=>now()->subDay()->toDateString(),'to'=>now()->toDateString()]; $path=$reports->export('sales',$filters);
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path); $titles=$book->getSheetNames();
    expect($message)->toContain('كشف حساب')->toContain('الرصيد النهائي')->not->toContain('incoming')->not->toContain('outgoing')
        ->and($reports->reportTitle('sales',$filters))->toContain('الطرف: عميل عربي')->toContain('من ')
        ->and($titles)->toContain('المبيعات')->toContain('تفاصيل المبيعات')->toContain('حركات أطراف المبيعات');
    $book->disconnectWorksheets(); @unlink($path);
});

test('message page never repeats the immediately previous item', function () {
    config(['message_to_you.items'=>[['type'=>'wisdom','text'=>'الأولى'],['type'=>'dua','text'=>'الثانية']]]);$user=User::factory()->create();
    $component=Livewire::actingAs($user)->test(MessageToYou::class);$first=$component->get('message')['text'];$component->call('another');
    expect($component->get('message')['text'])->not->toBe($first);
});

test('all new workspaces render for an authenticated user', function () {
    $user=User::factory()->create(['is_admin'=>true]);
    foreach(['message-to-you','dashboard','invoices.sales.create','invoices.purchases.create','quotations.index','notifications.index','reports.index'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
    }
});

test('quotation numeric product search uses an OR id predicate without builder exceptions', function () {
    $user = User::factory()->create();
    $product = v3Product();

    Livewire::actingAs($user)->test(QuotationsIndex::class)
        ->set('productSearch', (string) $product->id)
        ->assertSet('productSearch', (string) $product->id)
        ->assertHasNoErrors();
});

test('quotation expiry is nullable on create and may be cleared on update', function () {
    $user=User::factory()->create(); $product=v3Product(9); $customer=Partner::query()->create(['name'=>'عميل تواريخ','type'=>Partner::TYPE_CUSTOMER]);
    $line=[['product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>1,'unit_price'=>10]];

    Livewire::actingAs($user)->test(QuotationsIndex::class)->set('partner_id',$customer->id)->set('lines',$line)->set('expires_at','')->call('save')->assertHasNoErrors();
    $withoutExpiry=\App\Models\Quotation::query()->latest('id')->first();
    expect($withoutExpiry->expires_at)->toBeNull()->and($product->fresh()->quantity)->toBe(9);

    $expiry=now()->addDays(10)->toDateString();
    Livewire::actingAs($user)->test(QuotationsIndex::class)->set('partner_id',$customer->id)->set('lines',$line)->set('expires_at',$expiry)->call('save')->assertHasNoErrors();
    $withExpiry=\App\Models\Quotation::query()->latest('id')->first();
    expect($withExpiry->expires_at?->toDateString())->toBe($expiry);

    Livewire::actingAs($user)->test(QuotationsIndex::class)->call('edit',$withExpiry->id)->set('expires_at','')->call('save')->assertHasNoErrors();
    expect($withExpiry->fresh()->expires_at)->toBeNull()->and($product->fresh()->quantity)->toBe(9);
});

test('quotation workspace lists quotations and never substitutes sales invoices', function () {
    $user=User::factory()->create(); $product=v3Product();
    $quotePartner=Partner::query()->create(['name'=>'عميل العرض المنفصل','type'=>Partner::TYPE_CUSTOMER]);
    $invoicePartner=Partner::query()->create(['name'=>'عميل فاتورة لا تظهر','type'=>Partner::TYPE_CUSTOMER]);
    app(QuotationService::class)->save(['partner_id'=>$quotePartner->id,'quotation_date'=>now()->toDateString(),'status'=>'draft','discount'=>0], [['product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>1,'unit_price'=>10]]);
    Invoice::query()->create(['partner_id'=>$invoicePartner->id,'type'=>Invoice::TYPE_SALE,'total'=>10,'initial_paid'=>10,'amount_paid'=>10]);

    Livewire::actingAs($user)->test(QuotationsIndex::class)->assertSee('عميل العرض المنفصل')->assertDontSee('عميل فاتورة لا تظهر');
});

test('sales reports apply partner and category to data title summary and detail sheet', function () {
    $category = Category::query()->create(['name'=>'الأدوات الصحية']);
    $otherCategory = Category::query()->create(['name'=>'مواد أخرى']);
    $product = Product::query()->create(['category_id'=>$category->id,'name'=>'خلاط مختبر','purchase_price'=>5,'sale_price'=>10,'quantity'=>20,'alert_stock_at'=>2]);
    $otherProduct = Product::query()->create(['category_id'=>$otherCategory->id,'name'=>'منتج مستبعد','purchase_price'=>4,'sale_price'=>8,'quantity'=>20,'alert_stock_at'=>2]);
    $partner = Partner::query()->create(['name'=>'أحمد الحسن','type'=>Partner::TYPE_CUSTOMER]);
    $otherPartner = Partner::query()->create(['name'=>'طرف مستبعد','type'=>Partner::TYPE_CUSTOMER]);
    $invoice = Invoice::query()->create(['partner_id'=>$partner->id,'type'=>Invoice::TYPE_SALE,'total'=>18,'initial_paid'=>18,'amount_paid'=>18,'created_at'=>'2026-08-12 10:00:00']);
    $invoice->forceFill(['created_at'=>'2026-08-12 10:00:00'])->save();
    InvoiceLine::query()->create(['invoice_id'=>$invoice->id,'product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>1,'unit_price'=>10,'purchase_price'=>5,'line_total'=>10]);
    InvoiceLine::query()->create(['invoice_id'=>$invoice->id,'product_id'=>$otherProduct->id,'product_name'=>$otherProduct->name,'quantity'=>1,'unit_price'=>8,'purchase_price'=>4,'line_total'=>8]);
    $excluded = Invoice::query()->create(['partner_id'=>$otherPartner->id,'type'=>Invoice::TYPE_SALE,'total'=>10,'initial_paid'=>10,'amount_paid'=>10,'created_at'=>'2026-08-13 10:00:00']);
    $excluded->forceFill(['created_at'=>'2026-08-13 10:00:00'])->save();
    InvoiceLine::query()->create(['invoice_id'=>$excluded->id,'product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>1,'unit_price'=>10,'purchase_price'=>5,'line_total'=>10]);

    $filters=['from'=>'2026-08-01','to'=>'2026-08-30','partner_id'=>$partner->id,'category_id'=>$category->id];
    $reports=app(ReportService::class);
    expect($reports->preview('sales',$filters)['count'])->toBe(1)
        ->and($reports->reportTitle('sales',$filters))->toContain('الأدوات الصحية')->toContain('أحمد الحسن');

    $path=$reports->export('sales',$filters); $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $summary=collect($book->getSheet(0)->toArray())->flatten()->filter()->implode(' | ');
    $details=collect($book->getSheet(1)->toArray())->flatten()->filter()->implode(' | ');
    expect($summary)->toContain('التصنيف: الأدوات الصحية')->toContain('الطرف: أحمد الحسن')->not->toContain('طرف مستبعد')
        ->and($details)->toContain('خلاط مختبر')->not->toContain('منتج مستبعد');
    $book->disconnectWorksheets(); @unlink($path);
});
