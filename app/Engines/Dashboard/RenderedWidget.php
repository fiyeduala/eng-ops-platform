<?php

declare(strict_types=1);

namespace App\Engines\Dashboard;

use App\Models\DashboardWidget;

/**
 * One tile, filled in for one person, ready for a Blade file to print without thinking.
 *
 * WHY THE VIEW IS HANDED A VALUE OBJECT AND NOT A MODEL. A Blade file given a DashboardWidget would
 * have to decide for itself whether to show the stored figure or count a live one, and a decision
 * about whose visibility produced a number is not a decision that belongs in a template. Every such
 * question is settled here, before rendering, by WidgetComposer.
 *
 * `shared` IS THE FIELD THAT MATTERS. True means this figure was counted over the whole scope and
 * handed over because the viewer's reach contains all of it. False means it was counted for this
 * person alone, and `footnote` says why in words the tile prints. A screen that ignores `footnote`
 * shows two people different numbers under one heading with no explanation, which is how somebody
 * decides the platform is broken — the honesty is not decoration, it is what keeps the figure
 * usable.
 */
final readonly class RenderedWidget
{
    /**
     * @param  string  $kind  one of WidgetCatalogue::KINDS
     * @param  string  $value  the figure as it should be printed, or an em dash
     * @param  string  $standing  on_target | warning | critical | unknown
     * @param  bool  $available  false where there was nothing to count at all
     * @param  string|null  $reason  why not, in words a screen can print
     * @param  bool  $shared  true where this is the scope's figure rather than the viewer's
     * @param  string|null  $footnote  the sentence printed under the figure: whose it is, or when
     *                                 it was read
     * @param  array<int,array{label:string,value:float|null}>  $points  a trend, oldest first
     * @param  array<string,string>  $columns  field => heading, for a list tile
     * @param  array<int,array<string,string>>  $rows  the printed rows of a list tile
     */
    public function __construct(
        public DashboardWidget $widget,
        public string $kind,
        public string $heading,
        public string $width,
        public string $value = '—',
        public string $standing = 'unknown',
        public bool $available = true,
        public ?string $reason = null,
        public bool $shared = false,
        public ?string $footnote = null,
        public ?string $target = null,
        public array $points = [],
        public array $columns = [],
        public array $rows = [],
        public ?string $href = null,
    ) {}

    public function isFigure(): bool
    {
        return $this->kind === 'kpi_tile';
    }

    public function isTrend(): bool
    {
        return $this->kind === 'kpi_trend';
    }

    public function isTable(): bool
    {
        return $this->kind === 'report_table';
    }

    /**
     * The highest and lowest points on the line, for drawing it.
     *
     * Returned as a pair rather than computed in the template, and returning the same number twice
     * where every point is equal, so that a flat line is drawn flat rather than dividing by zero.
     *
     * @return array{float,float}
     */
    public function bounds(): array
    {
        $values = array_values(array_filter(
            array_column($this->points, 'value'),
            fn ($value) => $value !== null,
        ));

        if ($values === []) {
            return [0.0, 0.0];
        }

        return [(float) min($values), (float) max($values)];
    }

    public function hasPoints(): bool
    {
        foreach ($this->points as $point) {
            if ($point['value'] !== null) {
                return true;
            }
        }

        return false;
    }
}
