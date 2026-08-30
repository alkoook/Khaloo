@php
    $width = in_array((string)$settings->get('receipt_width','80'),['58','80']) ? $settings->get('receipt_width','80') : '80';
    $currency = $settings->get('currency_symbol','ل.س');
    $logo = $settings->get('invoice_logo',$settings->get('logo'));
    $printFonts = ['cairo'=>'Cairo, Tahoma, Arial, sans-serif','changa'=>'Changa, Tahoma, Arial, sans-serif','reem-kufi'=>'"Reem Kufi", Tahoma, Arial, sans-serif','baloo'=>'"Baloo Bhaijaan 2", Tahoma, Arial, sans-serif'];
    $printFont = $printFonts[$settings->get('font_family','cairo')] ?? $printFonts['cairo'];
@endphp
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>فاتورة #{{ $invoice->id }}</title>
<style>
@page{size:{{ $width }}mm auto;margin:0}*{box-sizing:border-box}body{margin:0;background:#eee;color:#000;font-family:{!! $printFont !!};font-size:12px}.receipt{width:{{ $width }}mm;min-height:40mm;margin:12px auto;padding:4mm;background:#fff}.center{text-align:center}.logo{max-width:28mm;max-height:18mm;object-fit:contain}.company{font-size:17px;margin:5px 0}.muted{font-size:10px}.divider{border-top:1px dashed #000;margin:8px 0}.meta{display:grid;grid-template-columns:1fr 1fr;gap:4px}.meta span:nth-child(even){text-align:left}.items{width:100%;border-collapse:collapse;table-layout:fixed}.items th,.items td{padding:5px 2px;border-bottom:1px dotted #888;text-align:right;vertical-align:top}.items th:first-child,.items td:first-child{width:42%;overflow-wrap:anywhere}.items .num{text-align:center;width:18%}.totals{display:grid;grid-template-columns:1fr auto;gap:5px;margin-top:8px}.grand{font-size:16px;font-weight:bold;border-top:2px solid #000;padding-top:6px}.actions{width:{{ $width }}mm;margin:10px auto;display:flex;gap:8px}.actions button{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px;border:1px solid #bfd8dc;border-radius:9px;background:#f4fbfc;color:#12343c;font-weight:700;cursor:pointer;transition:.2s}.actions button:first-child{background:#237f8e;border-color:#237f8e;color:#fff}.actions button:hover{transform:translateY(-1px);box-shadow:0 7px 18px #12343c24}.actions svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}@media print{body{background:#fff}.receipt{margin:0}.actions{display:none}}@media(max-width:400px){.receipt,.actions{width:100%;margin:0}.receipt{padding:12px}}
</style></head><body>
<main class="receipt">
    <header class="center">
        @if($settings->get('receipt_show_logo',true) && $logo)<img class="logo" src="{{ Storage::url($logo) }}" alt="الشعار">@endif
        @if($settings->get('receipt_show_company',true))<h1 class="company">{{ $settings->get('company_name','Khaloo') }}</h1>@endif
        @if($settings->get('receipt_show_phone',true) && $settings->get('company_phone'))<div>{{ $settings->get('company_phone') }}</div>@endif
        @if($settings->get('receipt_show_address',true) && $settings->get('company_address'))<div class="muted">{{ $settings->get('company_address') }}</div>@endif
    </header>
    <div class="divider"></div><h2 class="center">{{ $invoice->isSale()?'فاتورة مبيعات':'فاتورة مشتريات' }}</h2>
    <div class="meta">
        @if($settings->get('receipt_show_number',true))<span>رقم الفاتورة</span><span>#{{ $invoice->id }}</span>@endif
        <span>التاريخ</span><span>{{ $invoice->created_at->format('Y-m-d H:i') }}</span>
        @if($settings->get('receipt_show_partner',true))<span>{{ $invoice->isSale()?'العميل':'المورد' }}</span><span>{{ $invoice->partner?->name??'نقدي' }}</span>@endif
    </div><div class="divider"></div>
    <table class="items"><thead><tr><th>المنتج</th><th class="num">الكمية</th><th class="num">السعر</th><th class="num">الإجمالي</th></tr></thead><tbody>
        @foreach($invoice->lines as $line)<tr><td>{{ $line->product_name }}</td><td class="num">{{ $line->quantity }}</td><td class="num">{{ number_format($line->unit_price,2) }}</td><td class="num">{{ number_format($line->line_total,2) }}</td></tr>@endforeach
    </tbody></table>
    <div class="totals"><span>المدفوع</span><b>{{ number_format($invoice->amount_paid,2) }} {{ $currency }}</b><span>المتبقي</span><b>{{ number_format($invoice->remaining,2) }} {{ $currency }}</b><span class="grand">الإجمالي</span><b class="grand">{{ number_format($invoice->total,2) }} {{ $currency }}</b></div>
    @if($settings->get('receipt_show_footer',true))<div class="divider"></div><footer class="center"><p>{{ $settings->get('receipt_footer','شكرًا لتعاملكم معنا') }}</p><small>طُبع في {{ now()->format('Y-m-d H:i') }}</small></footer>@endif
</main><div class="actions"><button onclick="window.print()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V3h10v5M7 17h10v4H7zM5 17H3v-7h18v7h-2M17 12h1"/></svg><span>طباعة الإيصال</span></button><button onclick="window.close()">إغلاق</button></div>
@if($settings->get('receipt_auto_print',true))<script>addEventListener('load',()=>setTimeout(()=>window.print(),250))</script>@endif
</body></html>
