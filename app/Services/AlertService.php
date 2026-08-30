<?php

namespace App\Services;

use App\Models\AlertState;
use App\Models\InAppNotification;
use App\Models\Partner;
use App\Models\Product;

class AlertService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly FinancialService $financial,
        private readonly TelegramService $telegram,
    ) {}

    /** @param iterable<int>|int $productIds */
    public function evaluateProducts(iterable|int $productIds): void
    {
        $ids = is_int($productIds) ? [$productIds] : collect($productIds)->map(fn ($id) => (int) $id)->unique()->all();
        Product::query()->whereKey($ids)->get()->each(function (Product $product): void {
            $zero = $product->quantity <= 0;
            $low = ! $zero && $product->quantity <= $product->alert_stock_at;
            $this->transition('product', $product->id, 'stock_zero', $zero, 'نفاد مخزون',
                "نفد مخزون {$product->name} بالكامل.", 'danger', ['product_id' => $product->id], 'notify_stock_zero');
            $this->transition('product', $product->id, 'stock_low', $low, 'مخزون منخفض',
                "تبقّى {$product->quantity} من {$product->name} (حد التنبيه {$product->alert_stock_at}).",
                'warning', ['product_id' => $product->id], 'notify_stock_low');
        });
    }

    /** @param iterable<int>|int $partnerIds */
    public function evaluatePartners(iterable|int $partnerIds): void
    {
        $ids = is_int($partnerIds) ? [$partnerIds] : collect($partnerIds)->map(fn ($id) => (int) $id)->unique()->all();
        $partners = $this->financial->partnerBalancesQuery()->whereKey($ids)->get();
        $receivableThreshold = (float) $this->settings->get('receivable_alert_threshold', 500);
        $payableThreshold = (float) $this->settings->get('payable_alert_threshold', 500);

        $partners->each(function (Partner $partner) use ($receivableThreshold, $payableThreshold): void {
            $receivable = $this->financial->receivable($partner);
            $payable = $this->financial->payable($partner);
            $this->transition('partner', $partner->id, 'receivable_high', $receivableThreshold > 0 && $receivable >= $receivableThreshold,
                'ذمة مدينة مرتفعة', "بلغت ذمة {$partner->name} المدينة ".number_format($receivable, 2).'.',
                'warning', ['partner_id' => $partner->id, 'amount' => $receivable], 'notify_receivable');
            $this->transition('partner', $partner->id, 'payable_high', $payableThreshold > 0 && $payable >= $payableThreshold,
                'ذمة دائنة مرتفعة', "بلغت مستحقات {$partner->name} ".number_format($payable, 2).'.',
                'danger', ['partner_id' => $partner->id, 'amount' => $payable], 'notify_payable');
        });
    }

    private function transition(
        string $subjectType, int $subjectId, string $alertType, bool $shouldBeActive,
        string $title, string $message, string $severity, array $data, string $toggle,
    ): void {
        $state = AlertState::query()->firstOrCreate(
            ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'alert_type' => $alertType],
            ['active' => false],
        );

        if (! $shouldBeActive) {
            if ($state->active) $state->update(['active' => false]);
            return;
        }
        if ($state->active || ! (bool) $this->settings->get($toggle, true)) return;

        InAppNotification::query()->create([
            'type' => $alertType, 'title' => $title, 'message' => $message,
            'severity' => $severity, 'data' => $data,
        ]);
        $state->update(['active' => true, 'last_triggered_at' => now()]);
        if ((bool) $this->settings->get('telegram_alerts', true)) $this->telegram->sendMessage("{$title}\n{$message}");
    }
}
