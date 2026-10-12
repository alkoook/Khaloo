<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Calculation and persistence use decimal strings, never floating-point arithmetic. */
final class Decimal
{
    public static function of(mixed $value): BigDecimal
    {
        return BigDecimal::of($value === null || $value === '' ? '0' : (string) $value);
    }

    public static function scale(mixed $value, int $scale = 2): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function quantity(mixed $value): string
    {
        return (string) self::of($value)->toScale(3, RoundingMode::UNNECESSARY);
    }

    public static function add(mixed $a, mixed $b, int $scale = 2): string
    {
        return self::scale(self::of($a)->plus(self::of($b)), $scale);
    }

    public static function sub(mixed $a, mixed $b, int $scale = 2): string
    {
        return self::scale(self::of($a)->minus(self::of($b)), $scale);
    }

    public static function mul(mixed $a, mixed $b, int $scale = 2): string
    {
        return self::scale(self::of($a)->multipliedBy(self::of($b)), $scale);
    }

    public static function div(mixed $a, mixed $b, int $scale = 2): string
    {
        return (string) self::of($a)->dividedBy(self::of($b), $scale, RoundingMode::HALF_UP);
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function sum(iterable $values, int $scale = 2): string
    {
        $total = self::of(0);
        foreach ($values as $value) {
            $total = $total->plus(self::of($value));
        }

        return self::scale($total, $scale);
    }

    public static function max(mixed $a, mixed $b): string
    {
        return (string) (self::cmp($a, $b) >= 0 ? $a : $b);
    }

    public static function min(mixed $a, mixed $b): string
    {
        return (string) (self::cmp($a, $b) <= 0 ? $a : $b);
    }

    public static function format(mixed $value, int $scale = 2): string
    {
        [$whole, $fraction] = array_pad(explode('.', self::scale($value, $scale)), 2, '');

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).($scale ? '.'.$fraction : '');
    }

    public static function displayQuantity(mixed $value): string
    {
        return rtrim(rtrim(self::quantity($value), '0'), '.');
    }
}
