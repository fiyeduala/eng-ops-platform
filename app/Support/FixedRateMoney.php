<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Exact conversion using the rate fixed on a commercial document. */
final class FixedRateMoney
{
    public static function exchangeRate(?string $currency, ?string $baseCurrency, mixed $rate): BigDecimal
    {
        if (mb_strtoupper((string) $currency) === mb_strtoupper((string) $baseCurrency)) {
            return BigDecimal::one();
        }

        $exchangeRate = BigDecimal::of($rate ?? '0');

        if ($exchangeRate->isLessThanOrEqualTo(0)) {
            throw new \DomainException('The purchase order needs an exchange rate before its cost can be posted.');
        }

        return $exchangeRate;
    }

    public static function toBase(
        mixed $amount,
        ?string $currency,
        mixed $rate,
        ?string $baseCurrency,
        int $scale = 2,
    ): string {
        return (string) BigDecimal::of($amount)
            ->multipliedBy(self::exchangeRate($currency, $baseCurrency, $rate))
            ->toScale($scale, RoundingMode::HALF_UP);
    }
}
