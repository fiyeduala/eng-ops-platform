<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DashboardWidget;

/**
 * What a tile may be, and what each kind needs to be one (§73, §84).
 *
 * THE WHITELIST SIDE OF THE WIDGET BUILDER, and it sits beside KpiCatalogue and ReportCatalogue for
 * the same reason they exist: every field on the widget form is a select box, which is to say a
 * string from a browser, and "looks like a widget kind" is not a security property. Nothing here is
 * derived from the request and nothing here is stored in the database, so a kind the platform no
 * longer offers stops rendering the moment it is removed from this file rather than the moment
 * somebody notices a board is wrong.
 *
 * WHAT IS NOT HERE IS AS IMPORTANT. There is no entry mapping a widget to a permission a company
 * could choose. A tile's required permission is whatever its SOURCE requires — the register the
 * measure counts, the register the report lists — and it is read off that source at render time by
 * WidgetComposer. A board cannot publish a figure under a permission looser than the register it
 * came from, because there is nowhere to say so.
 */
final class WidgetCatalogue
{
    /**
     * The boards a company can arrange, and the permission each one is read under.
     *
     * Four, and deliberately not "one per role". The board is the ALTITUDE a set of tiles is about;
     * who may open it is a permission question answered separately, which is what stops a company
     * accumulating a dashboard per job title (§52, §53).
     *
     * `personal` is the landing page every signed-in person gets. It never serves a stored figure,
     * whatever a tile asks for, because there is no scope wide enough to contain one person.
     *
     * @var array<string,array{label:string,permission:string,scope:string|null,description:string}>
     */
    public const BOARDS = [
        'executive' => [
            'label' => 'Executive board',
            'permission' => 'dashboard.view_executive',
            'scope' => 'organization',
            'description' => 'The company at a glance, for the people accountable for all of it.',
        ],
        'department' => [
            'label' => 'Department board',
            'permission' => 'dashboard.view_department',
            'scope' => 'department',
            'description' => 'One department or division, shown to whoever opens it for their own.',
        ],
        'project' => [
            'label' => 'Project board',
            'permission' => 'dashboard.view',
            'scope' => 'project',
            'description' => 'One project, on the project’s own page.',
        ],
        'personal' => [
            'label' => 'Everyone’s landing page',
            'permission' => 'dashboard.view',
            'scope' => null,
            'description' => 'What each person sees when they sign in. Always their own figures.',
        ],
    ];

    /**
     * The shapes a tile can take.
     *
     * `source` names the column that must be filled in, which is what lets one validator serve
     * every kind rather than a match statement that grows a leg each time a kind is added.
     *
     * @var array<string,array{label:string,source:string,description:string}>
     */
    public const KINDS = [
        'kpi_tile' => [
            'label' => 'A single figure',
            'source' => 'kpi_definition_id',
            'description' => 'One measure, with its target and its standing.',
        ],
        'kpi_trend' => [
            'label' => 'A figure over time',
            'source' => 'kpi_definition_id',
            'description' => 'The line the nightly reading draws. Shown only to people who see the whole scope.',
        ],
        'report_table' => [
            'label' => 'The top of a report',
            'source' => 'report_definition_id',
            'description' => 'The first few rows of a saved report, counted through whoever is looking.',
        ],
    ];

    /**
     * How a figure is obtained.
     *
     * A PREFERENCE AND NOT A GRANT. `stored` asks for the snapshot; ReachInspector decides whether
     * this particular viewer may have it, and a viewer who falls short quietly gets their own live
     * figure with a sentence explaining the difference. Choosing `stored` therefore trades page
     * speed for freshness and can never trade away privacy.
     *
     * @var array<string,string>
     */
    public const REFRESH = [
        'live' => 'Counted now, for whoever is looking',
        'stored' => 'Last night’s reading where it can be shared',
    ];

    /** @var array<string,string> */
    public const WIDTHS = [
        'quarter' => 'A quarter of the row',
        'third' => 'A third of the row',
        'half' => 'Half the row',
        'full' => 'The whole row',
    ];

    /** The most tiles one board may carry. */
    public const MAX_PER_BOARD = 24;

    /** The most rows a list tile may print, and the furthest back a trend may reach. */
    public const MAX_ROW_LIMIT = 20;

    public const MAX_SPAN = 60;

    public static function isBoard(string $board): bool
    {
        return array_key_exists($board, self::BOARDS);
    }

    public static function isKind(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS);
    }

    /** The permission somebody must hold to open a board at all. */
    public static function permissionForBoard(string $board): ?string
    {
        return self::BOARDS[$board]['permission'] ?? null;
    }

    /**
     * Which KPI altitude a board's tiles are about, or null where there is none.
     *
     * The personal board answers null, and WidgetComposer reads that as "never serve a stored
     * figure here" rather than as "no scope given, assume the company".
     */
    public static function scopeForBoard(string $board): ?string
    {
        return self::BOARDS[$board]['scope'] ?? null;
    }

    /** Which column a kind draws from: `kpi_definition_id` or `report_definition_id`. */
    public static function sourceColumnFor(string $kind): ?string
    {
        return self::KINDS[$kind]['source'] ?? null;
    }

    public static function usesKpi(string $kind): bool
    {
        return self::sourceColumnFor($kind) === 'kpi_definition_id';
    }

    public static function usesReport(string $kind): bool
    {
        return self::sourceColumnFor($kind) === 'report_definition_id';
    }

    /**
     * A trend is stored by definition, whatever the row says.
     *
     * There is no such thing as a live trend. A line redrawn from current data every night moves
     * its own history whenever an old record is edited, which is the failure the snapshot table
     * exists to prevent (docs/11 §5) — so this kind reads the stored series or draws nothing.
     */
    public static function alwaysStored(string $kind): bool
    {
        return $kind === 'kpi_trend';
    }

    /** @return array<string,string> board => label */
    public static function boardOptions(): array
    {
        return array_map(fn (array $board) => $board['label'], self::BOARDS);
    }

    /** @return array<string,string> kind => label */
    public static function kindOptions(): array
    {
        return array_map(fn (array $kind) => $kind['label'], self::KINDS);
    }

    /**
     * Where a new tile goes: the end of its board.
     *
     * Asked of the model rather than computed on the form, because two people arranging the same
     * board in the same minute should get two tiles rather than one on top of the other.
     */
    public static function nextSortFor(int $organizationId, string $board): int
    {
        return (int) DashboardWidget::query()
            ->where('organization_id', $organizationId)
            ->where('board', $board)
            ->max('sort') + 1;
    }
}
