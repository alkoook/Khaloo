<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class InvoicePricingService
{
    public const FIXED = 'fixed';

    public const PERCENTAGE = 'percentage';

    /** @return array{subtotal: float, discount_type: ?string, discount_value: float, discount_amount: float, total: float, lines: array<int, array<string, mixed>>} */
    public function calculate(array $lines, ?string $discountType, mixed $discountValue): array
    {
        $prepared = collect($lines)->values()->map(function (array $line): array {
            $quantity = (int) ($line['quantity'] ?? 0);
            $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

            return array_merge($line, [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($quantity * $unitPrice, 2),
            ]);
        })->all();

        $subtotal = round((float) collect($prepared)->sum('line_total'), 2);
        $type = in_array($discountType, [self::FIXED, self::PERCENTAGE], true) ? $discountType : null;
        $value = filled($discountValue) ? round((float) $discountValue, 2) : 0.0;

        if ($value < 0) {
            throw ValidationException::withMessages(['discount_value' => 'قيمة الحسم لا يمكن أن تكون سالبة.']);
        }
        if ($type === self::PERCENTAGE && $value > 100) {
            throw ValidationException::withMessages(['discount_value' => 'نسبة الحسم يجب أن تكون بين 0 و100.']);
        }

        $discount = $type === self::PERCENTAGE
            ? round($subtotal * $value / 100, 2)
            : ($type === self::FIXED ? $value : 0.0);

        if ($discount > $subtotal) {
            throw ValidationException::withMessages(['discount_value' => 'قيمة الحسم لا يمكن أن تتجاوز المجموع قبل الحسم.']);
        }

        $remainingDiscount = $discount;
        $lastIndex = count($prepared) - 1;
        foreach ($prepared as $index => &$line) {
            $allocation = 0.0;
            if ($discount > 0 && $subtotal > 0) {
                $allocation = $index === $lastIndex
                    ? $remainingDiscount
                    : round($discount * ((float) $line['line_total'] / $subtotal), 2);
                $allocation = min($allocation, (float) $line['line_total']);
            }
            $line['discount_amount'] = round($allocation, 2);
            $line['net_line_total'] = round((float) $line['line_total'] - $allocation, 2);
            $remainingDiscount = round($remainingDiscount - $allocation, 2);
        }
        unset($line);

        return [
            'subtotal' => $subtotal,
            'discount_type' => $discount > 0 ? $type : null,
            'discount_value' => $discount > 0 ? $value : 0.0,
            'discount_amount' => $discount,
            'total' => round($subtotal - $discount, 2),
            'lines' => $prepared,
        ];
    }
}
