<?php

namespace App\Livewire\Invoices\Sales;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Services\AlertService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public ?int $partner_id=null;
    public array $lines=[];
    public string $amount_paid='0';
    public string $productSearch='';

    public function selectProduct(int $id): void
    {
        $product=Product::query()->findOrFail($id);
        foreach($this->lines as $index=>$line){if((int)$line['product_id']===$id){$this->lines[$index]['quantity']++;$this->productSearch='';$this->dispatch('focus-product-search');return;}}
        $this->lines[]=['product_id'=>$id,'product_name'=>$product->name ?: 'منتج #'.$id,'available'=>$product->quantity,'quantity'=>1,'unit_price'=>(float)$product->sale_price];
        $this->productSearch=''; $this->dispatch('focus-product-search');
    }
    public function removeLine(int $index): void { unset($this->lines[$index]);$this->lines=array_values($this->lines); }
    public function getTotalProperty(): float { return round(collect($this->lines)->sum(fn($line)=>(int)($line['quantity']??0)*(float)($line['unit_price']??0)),2); }

    public function save(): mixed
    {
        $data=$this->validate([
            'partner_id'=>['nullable',Rule::exists('partners','id')->where('type',Partner::TYPE_CUSTOMER)],'amount_paid'=>['required','numeric','min:0'],
            'lines'=>['required','array','min:1'],'lines.*.product_id'=>['required','exists:products,id'],'lines.*.quantity'=>['required','integer','min:1'],'lines.*.unit_price'=>['required','numeric','min:0'],
        ]);
        if(collect($this->lines)->pluck('product_id')->duplicates()->isNotEmpty()){$this->addError('lines','لا يمكن تكرار المنتج في الفاتورة.');return null;}
        $total=$this->total;$paid=round((float)$this->amount_paid,2);
        if($total<=0){$this->addError('lines','يجب أن يكون إجمالي الفاتورة أكبر من صفر.');return null;}
        if($paid>$total){$this->addError('amount_paid','المدفوع لا يمكن أن يتجاوز الإجمالي.');return null;}
        if(!$this->partner_id&&$paid<$total){$this->addError('partner_id','يجب اختيار عميل عند وجود مبلغ متبقٍ.');return null;}
        try{
            [$invoice,$messageLines]=DB::transaction(function()use($total,$paid):array{
                $invoice=Invoice::query()->create(['partner_id'=>$this->partner_id,'type'=>Invoice::TYPE_SALE,'total'=>$total,'initial_paid'=>$paid,'amount_paid'=>$paid]);$messageLines=[];
                $products=Product::query()->whereIn('id',collect($this->lines)->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');
                foreach($this->lines as $line){$product=$products->get((int)$line['product_id']);$qty=(int)$line['quantity'];if(!$product||$product->quantity<$qty)throw new \RuntimeException("المخزون غير كافٍ للمنتج {$line['product_name']}.");$price=(float)$line['unit_price'];$lineTotal=round($qty*$price,2);InvoiceLine::query()->create(['invoice_id'=>$invoice->id,'product_id'=>$product->id,'product_name'=>$product->name,'quantity'=>$qty,'unit_price'=>$price,'purchase_price'=>$product->purchase_price,'sale_price'=>$price,'line_total'=>$lineTotal]);$product->decrement('quantity',$qty);$messageLines[]=['product_name'=>$product->name,'quantity'=>$qty,'unit_price'=>$price,'line_total'=>$lineTotal];}
                return[$invoice->load('partner'),$messageLines];
            });
        }catch(\Throwable $e){$this->dispatch('flash',message:$e->getMessage(),type:'error');return null;}
        app(AlertService::class)->evaluateProducts(collect($this->lines)->pluck('product_id'));if($invoice->partner_id)app(AlertService::class)->evaluatePartners($invoice->partner_id);
        app(TelegramService::class)->sendInvoiceNotification('sale',$invoice->partner?->name??'عميل نقدي',number_format($total,2),number_format($paid,2),number_format($total-$paid,2),(string)$invoice->id,$messageLines);
        session()->flash('success','تم إنشاء فاتورة البيع بنجاح.');return $this->redirectRoute('invoices.sales.show',$invoice,navigate:true);
    }

    public function render()
    {
        $term=trim($this->productSearch);
        return view('livewire.invoices.sales.create',['customers'=>Partner::query()->where('type',Partner::TYPE_CUSTOMER)->orderBy('name')->get(['id','name']), 'productResults'=>$term===''?collect():Product::query()->with('category:id,name')->where(fn($q)=>$q->where('name','like',"%{$term}%")->when(is_numeric($term),fn($q)=>$q->orWhere($q->qualifyColumn('id'),(int)$term))->orWhereHas('category',fn($c)=>$c->where('name','like',"%{$term}%")))->orderByDesc('quantity')->limit(15)->get()]);
    }
}
