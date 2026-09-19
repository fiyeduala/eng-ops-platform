<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Models\NumberingScheme;
use App\Models\NumberSequence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules behind the numbering editor (§64, §73).
 *
 * A reference number is the only name a record has in the world outside this platform. It is
 * quoted in emails, printed in title blocks, typed into a client's own system and read aloud in
 * meetings. Two records bearing the same one is not a display bug — it is two contracts nobody can
 * tell apart in a thread six months later, and it cannot be fixed afterwards, because the wrong
 * number is already on paper somewhere.
 *
 * So the editor's job is mostly to refuse. Four refusals, in order of how much damage they prevent:
 *
 *   1. THE PATTERN MUST CONSUME THE COUNTER. A pattern with no {SEQ} renders the same string for
 *      every record forever. On registers with a unique index this surfaces as a create form that
 *      has stopped working; on those without one it surfaces as a register where forty documents
 *      are all called MEMO/2026.
 *
 *   2. A RESETTING COUNTER NEEDS A DATE IN THE PATTERN. This is the one an administrator actually
 *      walks into. `{PREFIX}-{SEQ}` with a yearly reset issues PRJ-0001 in January 2026 and
 *      PRJ-0001 again in January 2027 — a duplicate that appears twelve months after the setting
 *      was changed, by which time nobody connects the two. Monthly needs the month as well.
 *
 *   3. UNKNOWN TOKENS ARE REFUSED RATHER THAN DROPPED. NumberGenerator strips any token it cannot
 *      resolve, so an administrator who types {DEPARTMENT} gets a pattern that silently ignores
 *      the thing they were trying to add, with no error and no clue.
 *
 *   4. THE COUNTER NEVER GOES BACKWARDS. `start_at` seeds periods that have not begun yet, and
 *      lowering it below a value already issued would hand out the same number twice.
 *
 * WHAT THIS SERVICE DELIBERATELY DOES NOT OFFER is deleting a scheme or editing a counter. A
 * deleted scheme is re-provisioned by NumberGenerator on the very next create, starting at 1,
 * which is the collision in point 4 arriving through a different door. Numbers already issued are
 * stored strings on their records and are never recomputed (§64), so changing a pattern in March
 * is safe and renumbering January is not on offer.
 */
final class NumberingSchemeService
{
    /**
     * Tokens a pattern may name.
     *
     * {ORG} {PREFIX} {YYYY} {YY} {MM} are resolved by NumberGenerator for every module; {PROJECT}
     * {TYPE} {UNIT} are supplied by the registers that have such a thing and resolve to nothing
     * elsewhere, which is why they are allowed but not required.
     *
     * @var array<string,string>
     */
    public const TOKENS = [
        'SEQ' => 'The counter — 0007. Write {SEQ:6} to pad to a different width just here.',
        'PREFIX' => 'The prefix set below — PRJ.',
        'YYYY' => 'Four-digit year — 2026.',
        'YY' => 'Two-digit year — 26.',
        'MM' => 'Two-digit month — 03.',
        'ORG' => 'Your company short code, from Settings.',
        'PROJECT' => 'The project code, on registers that belong to a project.',
        'DISCIPLINE' => 'The discipline, on registers that record one.',
        'TYPE' => 'The record type, on registers that have one.',
        'UNIT' => 'The department code, on registers that belong to one.',
    ];

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Amend how future records of one module are numbered.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(NumberingScheme $scheme, array $attributes, User $actor): NumberingScheme
    {
        $pattern = trim((string) ($attributes['pattern'] ?? ''));
        $resetPeriod = (string) ($attributes['reset_period'] ?? $scheme->reset_period);
        $startAt = (int) ($attributes['start_at'] ?? $scheme->start_at);

        $this->assertPatternIsUsable($pattern);
        $this->assertCounterCannotRepeatItself($pattern, $resetPeriod);
        $this->assertCounterDoesNotGoBackwards($scheme, $startAt);

        return DB::transaction(function () use ($scheme, $attributes, $pattern, $resetPeriod, $startAt, $actor) {
            $before = $scheme->pattern;

            $scheme->fill([
                'label' => trim((string) ($attributes['label'] ?? '')) ?: null,
                'pattern' => $pattern,
                'prefix' => trim((string) ($attributes['prefix'] ?? '')) ?: null,
                'reset_period' => $resetPeriod,
                'padding' => max(1, min(12, (int) ($attributes['padding'] ?? $scheme->padding))),
                'start_at' => max(1, $startAt),
            ])->save();

            $this->activity->record(
                $scheme,
                'updated',
                $actor->name.' changed how '.$this->registerName($scheme->entity_type).' records are numbered.',
                ['from' => $before, 'to' => $scheme->pattern],
                $actor,
            );

            return $scheme;
        });
    }

    /**
     * What this pattern would produce, without consuming anything.
     *
     * Rendered from the UNSAVED pattern so an administrator sees the consequence before committing
     * to it. The counter shown is the live one, so the example is the number that would actually
     * come out — a preview built from 1 would look right for a scheme already at 431.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function preview(NumberingScheme $scheme, array $attributes): string
    {
        // A key present but null means "not supplied" here, not "blank". The preview endpoint
        // validates its fields as nullable, so a half-typed form posts nulls for the parts the
        // administrator has not reached yet, and those must fall back to what is stored rather
        // than render an empty prefix the scheme does not have.
        $given = fn (string $key, mixed $fallback) => match (true) {
            ! array_key_exists($key, $attributes) => $fallback,
            $attributes[$key] === null || $attributes[$key] === '' => $fallback,
            default => $attributes[$key],
        };

        $draft = (clone $scheme)->forceFill([
            'pattern' => trim((string) $given('pattern', $scheme->pattern)),
            'prefix' => trim((string) $given('prefix', $scheme->prefix)),
            'reset_period' => (string) $given('reset_period', $scheme->reset_period),
            'padding' => (int) $given('padding', $scheme->padding),
            'start_at' => (int) $given('start_at', $scheme->start_at),
        ]);

        try {
            return $this->numbers->previewScheme($draft);
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * Every module that carries a reference number, with the scheme in force.
     *
     * Listed from NumberGenerator::defaults() rather than from the table, because a company that
     * has not created a project yet has no project scheme row — and an editor that showed nothing
     * until the first record existed would be useless exactly when it is needed. Schemes the
     * company already has are merged on top, including any the generator provisioned for a series
     * the defaults never named.
     *
     * @return array<int,array{key:string,name:string,scheme:?NumberingScheme,pattern:string,issued:int,example:string}>
     */
    public function registers(): array
    {
        $schemes = NumberingScheme::query()->get()->keyBy('entity_type');

        // Scoped through the model rather than DB::table, so a company's page counts its own
        // counters and nobody else's (§75). One aggregate for the whole page, not one per row (§89).
        $issued = NumberSequence::query()
            ->selectRaw('numbering_scheme_id, SUM(next_value - 1) as taken')
            ->whereIn('numbering_scheme_id', $schemes->modelKeys())
            ->groupBy('numbering_scheme_id')
            ->pluck('taken', 'numbering_scheme_id');

        $keys = array_unique([
            ...array_keys(NumberGenerator::defaults()),
            ...$schemes->keys()->all(),
        ]);

        $out = [];

        foreach ($keys as $entityType) {
            $scheme = $schemes->get($entityType);

            $default = NumberGenerator::defaults()[$entityType] ?? null;

            $out[] = [
                'key' => $entityType,
                'name' => $this->registerName($entityType),
                'scheme' => $scheme,

                // A register with no scheme row yet shows the pattern it WOULD be given, because
                // that is the pattern its first record will actually carry. Showing a dash would
                // suggest the company has not decided, when in fact the platform has.
                'pattern' => (string) ($scheme?->pattern ?? $default['pattern'] ?? '—'),

                'issued' => (int) ($scheme !== null ? ($issued[$scheme->getKey()] ?? 0) : 0),
                'example' => $scheme !== null ? $this->preview($scheme, []) : $this->exampleFromDefault($entityType),
            ];
        }

        usort($out, fn (array $a, array $b) => $a['name'] <=> $b['name']);

        return $out;
    }

    /**
     * The scheme for a module, provisioned if the company has never had one.
     *
     * The same call NumberGenerator makes when a record is created, so opening the editor shows
     * the scheme actually in use rather than a hypothetical one.
     */
    public function schemeFor(string $entityType): NumberingScheme
    {
        $this->assertKnownRegister($entityType);

        return $this->numbers->schemeFor($entityType);
    }

    /**
     * How many numbers this scheme has handed out, per period.
     *
     * Shown on the editor because it is the only honest answer to "what happens if I change this".
     * A scheme sitting at 1 can be rewritten freely; one that has issued four hundred references
     * cannot, and an administrator who cannot see which of the two they are looking at will find
     * out from the refusal instead.
     *
     * @return array<int,array{period:string,next:int,issued:int}>
     */
    public function counters(NumberingScheme $scheme): array
    {
        return NumberSequence::query()
            ->where('numbering_scheme_id', $scheme->getKey())
            ->orderByDesc('period_key')
            ->get(['period_key', 'next_value'])
            ->map(fn (NumberSequence $row) => [
                'period' => $row->period_key === 'all' ? 'All time' : (string) $row->period_key,
                'next' => (int) $row->next_value,
                'issued' => max(0, (int) $row->next_value - 1),
            ])
            ->all();
    }

    // ================================================================== guards

    /**
     * The two pattern rules, asked without saving anything.
     *
     * Public so the live preview can refuse a pattern with the SAME sentence the save would use.
     * A preview that happily renders something the save then rejects teaches an administrator to
     * distrust the preview, and after that they stop reading it.
     */
    public function assertUsable(string $pattern, string $resetPeriod): void
    {
        $pattern = trim($pattern);

        $this->assertPatternIsUsable($pattern);
        $this->assertCounterCannotRepeatItself($pattern, $resetPeriod);
    }

    private function assertPatternIsUsable(string $pattern): void
    {
        if ($pattern === '') {
            throw new \DomainException('A numbering pattern cannot be empty.');
        }

        if (preg_match('/\{SEQ(?::\d+)?\}/', $pattern) !== 1) {
            throw new \DomainException(
                'The pattern has to include {SEQ}, the counter. Without it every record of this kind '
                .'would be given exactly the same reference.'
            );
        }

        preg_match_all('/\{([A-Z_]+)(?::\d+)?\}/', $pattern, $matches);

        $unknown = array_diff(array_unique($matches[1] ?? []), array_keys(self::TOKENS));

        if ($unknown !== []) {
            throw new \DomainException(
                'This pattern uses '.implode(', ', array_map(fn ($t) => '{'.$t.'}', $unknown))
                .', which the platform does not know how to fill in. It would be dropped from every '
                .'number silently. The tokens available are listed beside the field.'
            );
        }
    }

    /**
     * A counter that returns to 1 must be inside something that tells the periods apart.
     *
     * THE FAILURE IS DELAYED BY A YEAR, which is what makes it worth refusing rather than warning
     * about. `{PREFIX}-{SEQ}` reset yearly works perfectly for twelve months and then starts
     * issuing numbers that already exist, long after anybody remembers changing the setting.
     */
    private function assertCounterCannotRepeatItself(string $pattern, string $resetPeriod): void
    {
        if (! in_array($resetPeriod, NumberingScheme::RESET_PERIODS, true)) {
            throw new \DomainException('That is not a reset period this platform offers.');
        }

        if ($resetPeriod === 'none') {
            return;
        }

        $hasYear = str_contains($pattern, '{YYYY}') || str_contains($pattern, '{YY}');

        if (! $hasYear) {
            throw new \DomainException(
                'This counter starts again every '.($resetPeriod === 'monthly' ? 'month' : 'year')
                .', so the pattern has to carry the year — {YYYY} or {YY} — or the same reference '
                .'would be issued again next year.'
            );
        }

        if ($resetPeriod === 'monthly' && ! str_contains($pattern, '{MM}')) {
            throw new \DomainException(
                'This counter starts again every month, so the pattern has to carry {MM} as well, '
                .'or February would reissue January\'s references.'
            );
        }
    }

    /**
     * `start_at` seeds periods that have not begun. It may never point below a number already out.
     *
     * ONLY WHEN IT IS ACTUALLY CHANGING. The editor posts every field on every save, so a scheme
     * that has issued four hundred references still posts its original start_at of 1 when somebody
     * edits nothing but the prefix. Refusing that would make the whole screen read-only the moment
     * a company created its first record — the guard is about winding the counter back, not about
     * a number that was already there.
     */
    private function assertCounterDoesNotGoBackwards(NumberingScheme $scheme, int $startAt): void
    {
        if ($startAt === (int) $scheme->start_at) {
            return;
        }

        $highest = (int) NumberSequence::query()
            ->where('numbering_scheme_id', $scheme->getKey())
            ->max('next_value');

        if ($highest > 0 && $startAt < $highest) {
            throw new \DomainException(
                'This series has already reached '.$highest.'. Starting a new period below that would '
                .'hand out references that are already on records.'
            );
        }
    }

    private function assertKnownRegister(string $entityType): void
    {
        $known = array_keys(NumberGenerator::defaults());

        if (in_array($entityType, $known, true)) {
            return;
        }

        // A series the generator provisioned for itself — RegisterService::numberingEntityType()
        // splits some registers into several — counts as known once it exists (§84: it is known
        // because the platform made it, not because the URL said so).
        if (NumberingScheme::query()->forEntity($entityType)->exists()) {
            return;
        }

        throw new \DomainException('There is no numbered register called "'.$entityType.'".');
    }

    // ================================================================== helpers

    private function exampleFromDefault(string $entityType): string
    {
        $default = NumberGenerator::defaults()[$entityType] ?? null;

        if ($default === null) {
            return '—';
        }

        return str_replace(
            ['{PREFIX}', '{YYYY}', '{YY}', '{MM}', '{SEQ}'],
            [
                $default['prefix'],
                now()->format('Y'),
                now()->format('y'),
                now()->format('m'),
                str_pad('1', $default['padding'], '0', STR_PAD_LEFT),
            ],
            $default['pattern'],
        );
    }

    private function registerName(string $entityType): string
    {
        return Str::of($entityType)->replace('_', ' ')->title()->toString();
    }
}
