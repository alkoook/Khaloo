<?php

namespace App\Livewire\Settings;

use App\Services\SettingsService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('الإعدادات')]
class Index extends Component
{
    use WithFileUploads;

    public string $tab='general', $site_name='Khaloo', $company_name='Khaloo', $company_description='', $company_phone='', $company_whatsapp='', $company_email='', $company_address='', $currency='ليرة سورية', $currency_symbol='ل.س', $timezone='Asia/Damascus', $language='ar', $theme='marine-night', $font_family='cairo', $receipt_width='80', $receipt_footer='شكراً لتعاملكم معنا', $telegram_bot_token='', $telegram_chat_id='';
    public bool $receipt_show_logo=true, $receipt_show_company=true, $receipt_show_phone=true, $receipt_show_address=true, $receipt_show_partner=true, $receipt_show_number=true, $receipt_show_footer=true, $receipt_auto_print=true;
    public bool $telegram_enabled=false, $telegram_sales=true, $telegram_purchases=true, $telegram_payments=true, $telegram_alerts=true;
    public bool $notify_stock_low=true, $notify_stock_zero=true, $notify_receivable=true, $notify_payable=true;
    public int $receivable_alert_threshold=500, $payable_alert_threshold=500;
    public $logo, $favicon, $invoice_logo;
    public ?string $logo_path=null, $favicon_path=null, $invoice_logo_path=null;

    public function mount(SettingsService $settings): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        foreach (['site_name','company_name','company_description','company_phone','company_whatsapp','company_email','company_address','currency','currency_symbol','timezone','language','theme','font_family','receipt_width','receipt_show_logo','receipt_show_company','receipt_show_phone','receipt_show_address','receipt_show_partner','receipt_show_number','receipt_show_footer','receipt_auto_print','receipt_footer','telegram_enabled','telegram_chat_id','telegram_sales','telegram_purchases','telegram_payments','telegram_alerts','notify_stock_low','notify_stock_zero','notify_receivable','notify_payable','receivable_alert_threshold','payable_alert_threshold'] as $key) {
            $value=$settings->get($key,$this->{$key}); if ($value!==null) $this->{$key}=$value;
        }
        if (! in_array($this->theme, ['obsidian-ruby','arctic-cobalt','marine-night'], true)) $this->theme = 'marine-night';
        if (! in_array($this->font_family, ['cairo','changa','reem-kufi','baloo'], true)) $this->font_family = 'cairo';
        $this->logo_path=$settings->get('logo'); $this->favicon_path=$settings->get('favicon'); $this->invoice_logo_path=$settings->get('invoice_logo');
    }

    public function saveGeneral(SettingsService $settings): void
    {
        $data=$this->validate(['site_name'=>['required','string','max:120'],'company_name'=>['required','string','max:160'],'company_description'=>['nullable','string','max:500'],'company_phone'=>['nullable','string','max:40'],'company_whatsapp'=>['nullable','string','max:40'],'company_email'=>['nullable','email','max:160'],'company_address'=>['nullable','string','max:255'],'currency'=>['required','string','max:50'],'currency_symbol'=>['required','string','max:12'],'timezone'=>['required','timezone'],'language'=>['required','in:ar']]);
        $settings->setMany($data,'general'); $this->saved();
    }
    public function saveTheme(SettingsService $settings): void
    {
        $this->validate([
            'theme' => ['required', 'in:obsidian-ruby,arctic-cobalt,marine-night'],
            'font_family' => ['required', 'in:cairo,changa,reem-kufi,baloo'],
        ]);
        $settings->setMany(['theme' => $this->theme, 'font_family' => $this->font_family], 'appearance');
        $this->dispatch('appearance-changed', theme: $this->theme, font: $this->font_family);
        $this->saved();
    }
    public function savePrinting(SettingsService $settings): void
    {
        $data=$this->validate(['receipt_width'=>['required','in:58,80'],'receipt_show_logo'=>['boolean'],'receipt_show_company'=>['boolean'],'receipt_show_phone'=>['boolean'],'receipt_show_address'=>['boolean'],'receipt_show_partner'=>['boolean'],'receipt_show_number'=>['boolean'],'receipt_show_footer'=>['boolean'],'receipt_auto_print'=>['boolean'],'receipt_footer'=>['nullable','string','max:300']]); $settings->setMany($data,'printing'); $this->saved();
    }
    public function saveAlerts(SettingsService $settings): void
    {
        $data=$this->validate(['notify_stock_low'=>['boolean'],'notify_stock_zero'=>['boolean'],'notify_receivable'=>['boolean'],'notify_payable'=>['boolean'],'receivable_alert_threshold'=>['required','integer','min:0','max:1000000000'],'payable_alert_threshold'=>['required','integer','min:0','max:1000000000']]); $settings->setMany($data,'alerts'); $this->saved();
    }
    public function saveTelegram(SettingsService $settings): void
    {
        $data=$this->validate(['telegram_enabled'=>['boolean'],'telegram_bot_token'=>['nullable','string','max:255'],'telegram_chat_id'=>['nullable','string','max:100'],'telegram_sales'=>['boolean'],'telegram_purchases'=>['boolean'],'telegram_payments'=>['boolean'],'telegram_alerts'=>['boolean']]); $token=$data['telegram_bot_token']; unset($data['telegram_bot_token']); $settings->setMany($data,'telegram'); if($token!=='')$settings->set('telegram_bot_token',encrypt($token),'telegram'); $this->telegram_bot_token=''; $this->saved();
    }
    public function testTelegram(TelegramService $telegram): void { $ok=$telegram->sendMessage('✅ اتصال Khaloo مع Telegram يعمل بنجاح.'); $this->dispatch('flash',message:$ok?'تم إرسال رسالة الاختبار.':'تعذر الاتصال. راجع الإعدادات.',type:$ok?'success':'error'); }
    public function saveBranding(SettingsService $settings): void
    {
        $this->validate(['logo'=>['nullable','image','max:2048'],'favicon'=>['nullable','image','max:1024'],'invoice_logo'=>['nullable','image','max:2048']]); foreach(['logo','favicon','invoice_logo'] as $key){if(!$this->{$key})continue;$old=$settings->get($key);$path=$this->{$key}->store('branding','public');$settings->set($key,$path,'branding');if($old&&str_starts_with($old,'branding/'))Storage::disk('public')->delete($old);$this->{$key.'_path'}=$path;$this->reset($key);} $this->saved();
    }
    private function saved(): void { $this->dispatch('flash',message:'تم حفظ الإعدادات.',type:'success'); }
    public function render(){return view('livewire.settings.index');}
}
