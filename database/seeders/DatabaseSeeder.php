<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => env('KHALOO_ADMIN_PASSWORD', '123456'),
                'is_admin' => true,
            ]
        );

        foreach ([
            'site_name'=>['Khaloo','string','general'], 'company_name'=>['Khaloo Business Studio','string','general'],
            'timezone'=>['Asia/Damascus','string','general'], 'currency'=>['ليرة سورية','string','general'], 'currency_symbol'=>['ل.س','string','general'],
            'theme'=>['marine-night','string','appearance'], 'font_family'=>['cairo','string','appearance'], 'notify_stock_low'=>['1','boolean','alerts'],
            'notify_stock_zero'=>['1','boolean','alerts'], 'notify_receivable'=>['1','boolean','alerts'],
            'notify_payable'=>['1','boolean','alerts'], 'receivable_alert_threshold'=>['100','integer','alerts'],
            'payable_alert_threshold'=>['250','integer','alerts'], 'telegram_alerts'=>['1','boolean','telegram'],
        ] as $key=>$value) Setting::query()->updateOrCreate(['key'=>$key],['value'=>$value[0],'type'=>$value[1],'group'=>$value[2]]);

        $this->call(DemoDataSeeder::class);
    }
}
