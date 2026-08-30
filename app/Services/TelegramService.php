<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function sendMessage(string $text): bool
    {
        if (! $this->enabled()) return false;
        [$token, $chatId] = $this->credentials();
        if (! $token || ! $chatId) return false;
        try {
            $response = Http::timeout(8)->connectTimeout(4)->retry(2, 250, throw: false)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chatId, 'text' => $text]);
            if (! $response->successful()) Log::warning('Telegram rejected a message.', ['status' => $response->status()]);
            return $response->successful();
        } catch (\Throwable $exception) {
            Log::warning('Telegram message failed.', ['message' => $exception->getMessage()]);
            return false;
        }
    }

    public function sendDocument(string $filePath, string $caption = ''): bool
    {
        if (! $this->enabled() || ! is_file($filePath)) return false;
        [$token, $chatId] = $this->credentials();
        if (! $token || ! $chatId) return false;
        try {
            return Http::timeout(15)->connectTimeout(4)->retry(2, 250, throw: false)
                ->attach('document', file_get_contents($filePath), basename($filePath))
                ->post("https://api.telegram.org/bot{$token}/sendDocument", ['chat_id' => $chatId, 'caption' => $caption])
                ->successful();
        } catch (\Throwable $exception) {
            Log::warning('Telegram document failed.', ['message' => $exception->getMessage()]);
            return false;
        }
    }

    public function sendInvoiceNotification(string $type, string $partnerName, string $total, string $paid, string $remaining, string $invoiceId, array $lines = []): bool
    {
        $toggle = $type === 'purchase' ? 'telegram_purchases' : 'telegram_sales';
        if (! $this->settings->get($toggle, true)) return false;
        $label = match ($type) { 'sale' => 'مبيعات', 'purchase' => 'مشتريات', default => 'مرتجع مبيعات' };
        $text = "🧾 فاتورة {$label} #{$invoiceId}\nالطرف: {$partnerName}\nالإجمالي: {$total}\nالمدفوع: {$paid}\nالمتبقي: {$remaining}";
        if ($lines) {
            $text .= "\n──────────";
            foreach ($lines as $line) $text .= "\n• ".($line['product_name'] ?? 'منتج').' × '.($line['quantity'] ?? 0).' = '.number_format((float) ($line['line_total'] ?? 0), 2);
        }
        return $this->sendMessage($text);
    }

    public function notifyCreated(string $entity, string $label): bool { return $this->sendMessage("✅ تمت الإضافة\n{$this->entity($entity)}: {$label}"); }
    public function notifyUpdated(string $entity, string $label): bool { return $this->sendMessage("✏️ تم التعديل\n{$this->entity($entity)}: {$label}"); }
    public function notifyDeleted(string $entity, string $label): bool { return $this->sendMessage("🗑️ تم الحذف\n{$this->entity($entity)}: {$label}"); }

    private function enabled(): bool
    {
        return (bool) $this->settings->get('telegram_enabled', (bool) config('telegram.bot_token'));
    }

    private function credentials(): array
    {
        $encrypted = $this->settings->get('telegram_bot_token');
        try { $token = $encrypted ? decrypt($encrypted) : config('telegram.bot_token'); }
        catch (\Throwable) { $token = config('telegram.bot_token'); }
        return [$token, $this->settings->get('telegram_chat_id', config('telegram.chat_id'))];
    }

    private function entity(string $entity): string
    {
        return ['category'=>'تصنيف','product'=>'منتج','partner'=>'زبون/مورد','invoice_sale'=>'فاتورة بيع','invoice_purchase'=>'فاتورة شراء','invoice_return'=>'مرتجع'][$entity] ?? $entity;
    }
}
