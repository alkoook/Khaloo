<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function sendMessage(string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        [$token, $chatId] = $this->credentials();
        if (! $token || ! $chatId) {
            return false;
        }
        try {
            $response = Http::timeout(8)->connectTimeout(4)->retry(2, 250, throw: false)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chatId, 'text' => $text]);
            if (! $response->successful()) {
                Log::warning('Telegram rejected a message.', ['status' => $response->status()]);
            }

            return $response->successful();
        } catch (\Throwable $exception) {
            Log::warning('Telegram message failed.', ['exception' => $exception::class]);

            return false;
        }
    }

    public function sendDocument(string $filePath, string $caption = ''): bool
    {
        if (! $this->enabled() || ! is_file($filePath)) {
            return false;
        }
        [$token, $chatId] = $this->credentials();
        if (! $token || ! $chatId) {
            return false;
        }
        try {
            return Http::timeout(15)->connectTimeout(4)->retry(2, 250, throw: false)
                ->attach('document', file_get_contents($filePath), basename($filePath))
                ->post("https://api.telegram.org/bot{$token}/sendDocument", ['chat_id' => $chatId, 'caption' => $caption])
                ->successful();
        } catch (\Throwable $exception) {
            Log::warning('Telegram document failed.', ['exception' => $exception::class]);

            return false;
        }
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array
    {
        [$token, $chatId] = $this->credentials();
        if (! $token) {
            return ['ok' => false, 'message' => 'رمز البوت غير محفوظ.'];
        }

        try {
            $me = Http::acceptJson()->connectTimeout(4)->timeout(8)->get("https://api.telegram.org/bot{$token}/getMe");
            if (! $me->successful() || ! $me->json('ok')) {
                return ['ok' => false, 'message' => in_array($me->status(), [401, 404], true) ? 'رمز البوت غير صالح.' : 'تعذر التحقق من البوت لدى تيليغرام.'];
            }
            if (! $chatId) {
                return ['ok' => true, 'message' => 'تم الاتصال بالبوت بنجاح، لكن معرّف المحادثة غير محفوظ.'];
            }

            $message = Http::acceptJson()->connectTimeout(4)->timeout(8)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                ['chat_id' => $chatId, 'text' => 'تم ربط تيليغرام بنجاح مع Khaloo ✅'],
            );
            if ($message->successful() && $message->json('ok')) {
                return ['ok' => true, 'message' => 'تم الاتصال بالبوت وإرسال رسالة الاختبار بنجاح.'];
            }
            if ($message->status() === 403) {
                return ['ok' => false, 'message' => 'البوت لا يستطيع الإرسال إلى هذه المحادثة. ابدأ المحادثة معه وتحقق من الصلاحيات.'];
            }
            if ($message->status() === 400) {
                return ['ok' => false, 'message' => 'معرّف المحادثة غير صالح أو لا يمكن للبوت الوصول إليه.'];
            }

            return ['ok' => false, 'message' => 'خدمة تيليغرام غير متاحة حاليًا.'];
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'تعذر الوصول إلى الشبكة. تحقق من اتصال الإنترنت.'];
        } catch (\Throwable $exception) {
            Log::warning('Telegram connection test failed.', ['exception' => $exception::class]);

            return ['ok' => false, 'message' => 'تعذر اختبار تيليغرام حاليًا.'];
        }
    }

    public function sendInvoiceNotification(string $type, string $partnerName, string $total, string $paid, string $remaining, string $invoiceId, array $lines = []): bool
    {
        $toggle = $type === 'purchase' ? 'telegram_purchases' : 'telegram_sales';
        if (! $this->settings->get($toggle, true)) {
            return false;
        }
        $label = match ($type) {
            'sale' => 'مبيعات', 'purchase' => 'مشتريات', default => 'مرتجع مبيعات'
        };
        $text = "🧾 فاتورة {$label} #{$invoiceId}\nالطرف: {$partnerName}\nالإجمالي: {$total}\nالمدفوع: {$paid}\nالمتبقي: {$remaining}";
        if ($lines) {
            $text .= "\n──────────";
            foreach ($lines as $line) {
                $text .= "\n• ".($line['product_name'] ?? 'منتج').' × '.($line['quantity'] ?? 0).' = '.number_format((float) ($line['line_total'] ?? 0), 2);
            }
        }

        return $this->sendMessage($text);
    }

    public function notifyCreated(string $entity, string $label): bool
    {
        return $this->sendMessage("✅ تمت الإضافة\n{$this->entity($entity)}: {$label}");
    }

    public function notifyUpdated(string $entity, string $label): bool
    {
        return $this->sendMessage("✏️ تم التعديل\n{$this->entity($entity)}: {$label}");
    }

    public function notifyDeleted(string $entity, string $label): bool
    {
        return $this->sendMessage("🗑️ تم الحذف\n{$this->entity($entity)}: {$label}");
    }

    private function enabled(): bool
    {
        $all = $this->settings->all();

        return array_key_exists('telegram_enabled', $all) ? (bool) $all['telegram_enabled'] : (bool) config('telegram.bot_token');
    }

    private function credentials(): array
    {
        $all = $this->settings->all();
        if (array_key_exists('telegram_bot_token', $all)) {
            try {
                $token = filled($all['telegram_bot_token']) ? decrypt($all['telegram_bot_token']) : null;
            } catch (\Throwable) {
                $token = null;
            }
        } else {
            $token = config('telegram.bot_token');
        }
        $chatId = array_key_exists('telegram_chat_id', $all) ? $all['telegram_chat_id'] : config('telegram.chat_id');

        return [$token, $chatId];
    }

    private function entity(string $entity): string
    {
        return ['category' => 'تصنيف', 'product' => 'منتج', 'partner' => 'السيد/المورد', 'invoice_sale' => 'فاتورة بيع', 'invoice_purchase' => 'فاتورة شراء', 'invoice_return' => 'مرتجع'][$entity] ?? $entity;
    }
}
