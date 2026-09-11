<?php

namespace App\Domain\ReferralAffiliate\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use NumberFormatter;

final class Money
{
    public static function decimals(string $currency): int
    {
        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Invalid currency');
        }
        $formatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    public static function input(string $amount, string $currency, string $field): int
    {
        try {
            return self::minor($amount, $currency);
        } catch (InvalidArgumentException|MathException $e) {
            throw ValidationException::withMessages([$field => '金额小数位不符合币种精度，请检查后重试。']);
        }
    }

    public static function minor(string $amount, string $currency): int
    {
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $amount)) {
            throw new InvalidArgumentException('Money must be a decimal string');
        }

        return BigDecimal::of($amount)->multipliedBy(10 ** self::decimals($currency))
            ->toScale(0, RoundingMode::Unnecessary)->toInt();
    }

    public static function decimal(int $amount, string $currency): string
    {
        return (string) BigDecimal::of($amount)->dividedBy(10 ** self::decimals($currency), self::decimals($currency), RoundingMode::Unnecessary);
    }

    public static function proportional(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator < 0) {
            throw new InvalidArgumentException('Invalid monetary proportion');
        }

        return BigDecimal::of($amount)->multipliedBy($numerator)->dividedBy($denominator, 0, RoundingMode::HalfUp)->toInt();
    }
}
