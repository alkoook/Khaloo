<?php

namespace App\Livewire\Invoices\Purchases;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MoneyParty;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseFundingSource;
use App\Services\AlertService;
use App\Services\FinancialStatusService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public ?int $partner_id=null; public array $lines=[]; public string $amount_paid='0'; public string $productSearch='';
    public string $funding_source='direct_project_cash'; public ?int $money_party_id=null;
    public function selectProduct(int $id):void{$product=Product::query()->findOrFail($id);foreach($this->lines as $index=>$line){if((int)$line['product_id']===$id){$this->lines[$index]['quantity']++;$this->productSearch='';$this->dispatch('focus-product-search');return;}}$this->lines[]=['product_id'=>$id,'product_name'=>$product->name?:'منتج #'.$id,'available'=>$product->quantity,'quantity'=>1,'unit_price'=>(float)$product->purchase_price,'sale_price'=>(float)$product->sale_price];$this->productSearch='';$this->dispatch('focus-product-search');}
    public function removeLine(int $index):void{unset($this->lines[$index]);$this->lines=array_values($this->lines);}
    public function getTotalProperty():float{return round(collect($this->lines)->sum(fn($line)=>(int)($line['quantity']??0)*(float)($line['unit_price']??0)),2);}
    public function save():mixed
    {
        $this->validate(['partner_id'=>['nullable',Rule::exists('partners','id')->where('type',Partner::TYPE_SUPPLIER)],'amount_paid'=>['required','numeric','min:0'],'funding_source'=>['required',Rule::in(['direct_project_cash','money_party_balance'])],'money_party_id'=>[Rule::requiredIf($this->funding_source==='money_party_balance'),'nullable','exists:money_parties,id'],'lines'=>['required','array','min:1'],'lines.*.product_id'=>['required','exists:products,id'],'lines.*.quantity'=>['required','integer','min:1'],'lines.*.unit_price'=>['required','numeric','min:0'],'lines.*.sale_price'=>['nullable','numeric','min:0']]);
        if(collect($this->lines)->pluck('product_id')->duplicates()->isNotEmpty()){$this->addError('lines','لا يمكن تكرار المنتج في الفاتورة.');return null;}$total=$this->total;$paid=round((float)$this->amount_paid,2);if($total<=0){$this->addError('lines','يجب أن يكون الإجمالي أكبر من صفر.');return null;}if($paid>$total){$this->addError('amount_paid','المدفوع لا يمكن أن يتجاوز الإجمالي.');return null;}if(!$this->partner_id&&$paid<$total){$this->addError('partner_id','يجب اختيار مورد عند وجود مبلغ متبقٍ.');return null;}
        try {
        [$invoice,$messageLines]=DB::transaction(function()use($total,$paid):array{
            if($this->funding_source==='money_party_balance'){
                MoneyParty::query()->lockForUpdate()->findOrFail($this->money_party_id);
                $available=app(FinancialStatusService::class)->availableAtParty((int)$this->money_party_id,true);
                if($paid>$available) throw new \RuntimeException('رصيد جهة المال لا يكفي لتغطية المبلغ المدفوع.');
            }
            $invoice=Invoice::query()->create(['partner_id'=>$this->partner_id,'type'=>Invoice::TYPE_PURCHASE,'total'=>$total,'initial_paid'=>$paid,'amount_paid'=>$paid]);
            if($this->funding_source==='money_party_balance' && $paid>0) PurchaseFundingSource::query()->create(['invoice_id'=>$invoice->id,'money_party_id'=>$this->money_party_id,'amount_base_currency'=>$paid]);
            $messageLines=[];$products=Product::query()->whereIn('id',collect($this->lines)->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');foreach($this->lines as $line){$product=$products->get((int)$line['product_id']);if(!$product)throw new \RuntimeException('أحد المنتجات لم يعد موجودًا.');$qty=(int)$line['quantity'];$price=(float)$line['unit_price'];$salePrice=filled($line['sale_price']??null)?(float)$line['sale_price']:null;$lineTotal=round($qty*$price,2);InvoiceLine::query()->create(['invoice_id'=>$invoice->id,'product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>$qty,'unit_price'=>$price,'purchase_price'=>$price,'sale_price'=>$salePrice,'line_total'=>$lineTotal]);$product->increment('quantity',$qty);$product->update(array_filter(['purchase_price'=>$price,'sale_price'=>$salePrice],fn($v)=>$v!==null));$messageLines[]=['product_name'=>$product->name,'quantity'=>$qty,'unit_price'=>$price,'line_total'=>$lineTotal];}return[$invoice->load('partner'),$messageLines];
        });
        } catch (\Throwable $exception) {
            $this->addError('amount_paid', $exception->getMessage());
            return null;
        }
        app(AlertService::class)->evaluateProducts(collect($this->lines)->pluck('product_id'));if($invoice->partner_id)app(AlertService::class)->evaluatePartners($invoice->partner_id);app(TelegramService::class)->sendInvoiceNotification('purchase',$invoice->partner?->name??'مورد نقدي',number_format($total,2),number_format($paid,2),number_format($total-$paid,2),(string)$invoice->id,$messageLines);session()->flash('success','تم إنشاء فاتورة الشراء بنجاح.');return $this->redirectRoute('invoices.purchases.show',$invoice,navigate:true);
    }
    public function render(){ $term=trim($this->productSearch);return view('livewire.invoices.purchases.create',['suppliers'=>Partner::query()->where('type',Partner::TYPE_SUPPLIER)->orderBy('name')->get(['id','name']),'moneyParties'=>MoneyParty::query()->orderBy('name')->get(['id','name']),'productResults'=>$term===''?collect():Product::query()->with('category:id,name')->where(fn($q)=>$q->where('name','like',"%{$term}%")->when(is_numeric($term),fn($q)=>$q->orWhere($q->qualifyColumn('id'),(int)$term))->orWhereHas('category',fn($c)=>$c->where('name','like',"%{$term}%")))->limit(15)->get()]); }
}
