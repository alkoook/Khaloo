<?php

namespace App\Services;

use App\Support\Decimal as D;
use Illuminate\Validation\ValidationException;

class InvoicePricingService
{
    public const FIXED = 'fixed';

    public const PERCENTAGE = 'percentage';

    /** @return array{subtotal: string, discount_type: ?string, discount_value: string, discount_amount: string, total: string, lines: array<int, array<string, mixed>>} */
    public function calculate(array $lines, ?string $discountType, mixed $discountValue): array
    {
        $prepared = collect($lines)->values()->map(function (array $line): array {
            try {
                $quantity = D::quantity($line['quantity'] ?? 0);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['lines' => 'الكمية يجب أن تكون رقماً صالحاً حتى ثلاث منازل عشرية.']);
            }
            if (D::cmp($quantity, 0) <= 0 || D::cmp($line['unit_price'] ?? 0, 0) < 0) {
                throw ValidationException::withMessages(['lines' => 'الكمية يجب أن تكون أكبر من صفر والسعر غير سالب.']);
            }
            $unitPrice = D::scale($line['unit_price'] ?? 0);

            return array_merge($line, [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => D::mul($quantity, $unitPrice),
            ]);
        })->all();

        $subtotal = D::sum(array_column($prepared, 'line_total'));
        $type = in_array($discountType, [self::FIXED, self::PERCENTAGE], true) ? $discountType : null;
        $value = D::scale($discountValue);

        if (D::cmp($value, 0) < 0) {
            throw ValidationException::withMessages(['discount_value' => 'قيمة الحسم لا يمكن أن تكون سالبة.']);
        }
        if ($type === self::PERCENTAGE && D::cmp($value, 100) > 0) {
            throw ValidationException::withMessages(['discount_value' => 'نسبة الحسم يجب أن تكون بين 0 و100.']);
        }

        $discount = $type === self::PERCENTAGE
            ? D::div(D::of($subtotal)->multipliedBy($value), 100)
            : ($type === self::FIXED ? $value : '0.00');

        if (D::cmp($discount, $subtotal) > 0) {
            throw ValidationException::withMessages(['discount_value' => 'قيمة الحسم لا يمكن أن تتجاوز المجموع قبل الحسم.']);
        }

        $remainingDiscount = $discount;
        $lastIndex = count($prepared) - 1;
        foreach ($prepared as $index => &$line) {
            $allocation = '0.00';
            if (D::cmp($discount, 0) > 0 && D::cmp($subtotal, 0) > 0) {
                $allocation = $index === $lastIndex
                    ? $remainingDiscount
                    : D::div(D::of($discount)->multipliedBy($line['line_total']), $subtotal);
                $allocation = D::min($remainingDiscount, D::min($allocation, $line['line_total']));
            }
            $line['discount_amount'] = D::scale($allocation);
            $line['net_line_total'] = D::sub($line['line_total'], $allocation);
            $remainingDiscount = D::sub($remainingDiscount, $allocation);
        }
        unset($line);
        // Very small lines can all round their proportional share down. Allocate
        // residual cents only into remaining line capacity so totals always reconcile.
        foreach (array_reverse(array_keys($prepared)) as $index) {
            if (D::cmp($remainingDiscount, 0) <= 0) {
                break;
            }
            $extra = D::min($remainingDiscount, $prepared[$index]['net_line_total']);
            $prepared[$index]['discount_amount'] = D::add($prepared[$index]['discount_amount'], $extra);
            $prepared[$index]['net_line_total'] = D::sub($prepared[$index]['net_line_total'], $extra);
            $remainingDiscount = D::sub($remainingDiscount, $extra);
        }

        return [
            'subtotal' => $subtotal,
            'discount_type' => D::cmp($discount, 0) > 0 ? $type : null,
            'discount_value' => D::cmp($discount, 0) > 0 ? $value : '0.00',
            'discount_amount' => $discount,
            'total' => D::sub($subtotal, $discount),
            'lines' => $prepared,
        ];
    }
}
