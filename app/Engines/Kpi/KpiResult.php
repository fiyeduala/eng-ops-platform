<?php

declare(strict_types=1);

namespace App\Engines\Kpi;

use App\Models\KpiDefinition;
use Carbon\CarbonInterface;

/**
 * One number, and everything needed to read it honestly.
 *
 * WHY A NUMBER IS NOT ENOUGH. A tile showing "0" has to say whether that means nothing happened or
 * nothing could be counted, and the two are opposite pieces of news: the first is a quiet month,
 * the second is a KPI pointed at a register that no longer exists, or one being read by somebody
 * whose visibility reaches none of it. So `available` and `reason` travel with the value, and a
 * screen that ignores them will print a plausible lie rather than an obvious blank.
 *
 * THE VALUE IS THE VIEWER'S. Every result was counted through one person's visibility, which is why
 * `countedFor` is carried alongside it — a figure cached or passed around without that label is a
 * figure somebody will eventually show to the wrong person (§61).
 */
final readonly class KpiResult
{
    /**
     * @param  float|null  $value  the number, or null where there is none to give
     * @param  int  $population  rows the base question matched, before the aggregate
     * @param  bool  $available  false when the KPI could not be counted at all
     * @param  string|null  $reason  why not, in words a screen can print
     * @param  int|null  $countedFor  the user whose visibility produced this figure
     */
    public function __construct(
        public ?float $value,
        public int $population = 0,
        public bool $available = true,
        public ?string $reason = null,
        public ?int $countedFor = null,
        public ?CarbonInterface $windowStart = null,
        public ?CarbonInterface $windowEnd = null,
    ) {}

    public static function unavailable(string $reason, ?int $countedFor = null): self
    {
        return new self(value: null, available: false, reason: $reason, countedFor: $countedFor);
    }

    /**
     * How the number reads next to the target the company set.
     *
     * `on_target`, `warning`, `critical` or `unknown`. Read through `direction`, because the same
     * two thresholds mean opposite things on an incident count and a throughput count — a platform
     * that assumed bigger is better would quietly congratulate a company on its injury rate.
     */
    public function standing(KpiDefinition $kpi): string
    {
        if ($this->value === null) {
            return 'unknown';
        }

        [$warning, $critical] = array_values($kpi->thresholdValues());

        $worseThan = fn (?float $bound): bool => $bound !== null && (
            $kpi->higherIsBetter() ? $this->value < $bound : $this->value > $bound
        );

        return match (true) {
            $worseThan($critical) => 'critical',
            $worseThan($warning) => 'warning',
            $warning === null && $critical === null => 'unknown',
            default => 'on_target',
        };
    }

    /** The figure as the company asked for it to be written, unit and all. */
    public function display(KpiDefinition $kpi): string
    {
        if ($this->value === null) {
            return '—';
        }

        $decimals = match ($kpi->aggregate) {
            'count' => 0,
            'ratio' => 2,
            default => 1,
        };

        $number = number_format($this->value, $decimals);

        if ($kpi->aggregate === 'percent') {
            return $number.'%';
        }

        return filled($kpi->unit) ? $number.' '.$kpi->unit : $number;
    }
}
