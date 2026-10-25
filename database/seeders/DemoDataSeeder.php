<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Expense;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Quotation;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $categories=collect(['تمديدات مياه','خلاطات وحنفيات','أدوات صحية','إكسسوارات حمام'])->map(fn($name)=>Category::query()->create(compact('name')));
        $customers=collect(['أحمد الحسن','شركة البناء الحديث','محمد الخطيب','مؤسسة الإعمار'])->map(fn($name)=>Partner::query()->create(['name'=>$name,'phone'=>'09'.fake()->numerify('########'),'type'=>Partner::TYPE_CUSTOMER]));
        $suppliers=collect(['مورد الشام','شركة النور للتجارة','مؤسسة الوفاء'])->map(fn($name)=>Partner::query()->create(['name'=>$name,'phone'=>'09'.fake()->numerify('########'),'type'=>Partner::TYPE_SUPPLIER]));
        $names=['كوع 20 مم','كوع 25 مم','بوري 32 مم','محبس زاوية','خلاط مغسلة','خلاط مطبخ','سيفون أرضي','خرطوم مرن','حنفية حديقة','مصفاة ستانلس','جلبة تحويل','رأس دش'];
        $products=collect();
        foreach(range(0,35) as $index){$purchase=fake()->randomFloat(2,4,120);$products->push(Product::query()->create(['category_id'=>$categories[$index%$categories->count()]->id,'partner_id'=>$suppliers[$index%$suppliers->count()]->id,'name'=>$names[$index%count($names)].' '.($index+1),'description'=>'بيانات تجريبية لفحص الواجهة والتقارير.','purchase_price'=>$purchase,'sale_price'=>round($purchase*1.35,2),'quantity'=>$index<3?$index:fake()->numberBetween(8,180),'alert_stock_at'=>$index<3?5:fake()->numberBetween(3,12),'country_origin'=>$index%2?'سوريا':'تركيا']));}

        $sale=Invoice::query()->create(['partner_id'=>$customers[0]->id,'type'=>Invoice::TYPE_SALE,'total'=>270,'initial_paid'=>120,'amount_paid'=>120]);
        $sale->lines()->create(['product_id'=>$products[5]->id,'product_name'=>$products[5]->name,'quantity'=>2,'unit_price'=>135,'purchase_price'=>$products[5]->purchase_price,'sale_price'=>135,'line_total'=>270]);
        $products[5]->decrement('quantity',2);
        $purchase=Invoice::query()->create(['partner_id'=>$suppliers[0]->id,'type'=>Invoice::TYPE_PURCHASE,'total'=>480,'initial_paid'=>180,'amount_paid'=>180]);
        $purchase->lines()->create(['product_id'=>$products[8]->id,'product_name'=>$products[8]->name,'quantity'=>8,'unit_price'=>60,'purchase_price'=>60,'sale_price'=>$products[8]->sale_price,'line_total'=>480]);
        $products[8]->increment('quantity',8);
        Invoice::query()->create(['partner_id'=>null,'type'=>Invoice::TYPE_SALE,'total'=>85,'initial_paid'=>85,'amount_paid'=>85]);
        LedgerSettlement::query()->create(['partner_id'=>$customers[0]->id,'amount'=>40,'type'=>LedgerSettlement::TYPE_INCOMING,'settlement_date'=>now()->subDay()->toDateString(),'notes'=>'دفعة تجريبية']);
        Expense::query()->create(['amount'=>35,'reason'=>'نقل وشحن','date'=>now()->toDateString()]);

        $quote=Quotation::query()->create(['number'=>'Q-'.now()->format('Ym').'-00001','partner_id'=>$customers[1]->id,'quotation_date'=>now()->toDateString(),'expires_at'=>now()->addDays(14)->toDateString(),'status'=>'sent','subtotal'=>310,'discount'=>10,'total'=>300,'notes'=>'عرض تجريبي جاهز للطباعة والتحويل.']);
        $quote->lines()->create(['product_id'=>$products[10]->id,'product_name'=>$products[10]->name,'quantity'=>2,'unit_price'=>155,'line_total'=>310]);
        InAppNotification::query()->create(['type'=>'stock_zero','title'=>'نفاد مخزون','message'=>'نفد مخزون '.$products[0]->name.' بالكامل.','severity'=>'danger','data'=>['product_id'=>$products[0]->id]]);
        InAppNotification::query()->create(['type'=>'receivable_high','title'=>'ذمة تحتاج متابعة','message'=>'يوجد رصيد تجريبي مرتفع للسيد.','severity'=>'warning','data'=>['partner_id'=>$customers[0]->id]]);
    }
}
