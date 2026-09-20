<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\ActorProfile;
use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\ReportCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A set of conditions somebody uses often enough to name (§54, docs/11 §2).
 *
 * WHAT IS SHARED IS THE QUESTION, NOT THE ANSWER. This is the property that makes sharing one of
 * these safe at all, and it is worth being explicit about because the opposite is the obvious
 * feature request. "Send me that list" is answered here by sending the conditions; the recipient
 * runs them through their own visibility and gets their own rows. There is no column on this table
 * in which a result could be stored, and that absence is the design.
 *
 * SO A SHARED FILTER CANNOT WIDEN ANYBODY'S REACH. A director shares "open incidents, company
 * wide"; the storeman who applies it sees the incidents a storeman may see, which may be none. The
 * filter narrows a set it never chooses — every method the compiler runs only ever removes rows —
 * and the set itself comes from `visibleTo()` before a single condition is applied.
 *
 * WHY THIS IS NOT A SECOND ACCESS ENGINE. `visibility` decides who may see the SAVED FILTER; the
 * five-gate engine decides who may see the records it selects, and nothing here touches that. The
 * distinction is the same one the platform already draws between a document's ACL row and a
 * document's contents: an owner sharing their own named view is an act of sharing, not a grant of
 * permission, and `readableBy` never appears in the path that fetches a row of company data.
 *
 * THE CONDITIONS ARE RE-CHECKED ON APPLY, NOT MERELY ON SAVE (§84). A saved filter outlives the
 * register it names — an upgrade withdraws a field, a custom field is deleted, a module is switched
 * off — and "it was valid in March" is not a property the run screen may rely on in October.
 */
class SavedFilter extends Model
{
    use Auditable, BelongsToOrganization;

    /**
     * Who may see the question.
     *
     * `organization` is a genuine disclosure decision rather than a convenience. The NAME of a
     * filter is company-entered text on everybody's screen, and "contractors under investigation"
     * discloses that there is an investigation to every person who opens the register. The service
     * gates that choice; these are only the words for it.
     *
     * @var array<string,string>
     */
    public const VISIBILITIES = [
        'private' => 'Only me',
        'org_unit' => 'My department',
        'organization' => 'Everyone in the company',
    ];

    public const MAX_FILTERS = 10;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ================================================================== reading

    /**
     * Every saved filter this person may see, for one register.
     *
     * THREE CASES, AND THE MIDDLE ONE RUNS UPWARD. A filter shared with Engineering must reach
     * somebody sitting two levels below it in Civil, because that is what the person who shared it
     * meant by "my department". So the question is not "which units are under me" but "which units
     * am I inside", and the answer is `ancestorOrgUnitIds()` — the same routing the announcement
     * module uses to decide who a division-wide notice appears for.
     *
     * That method's own docblock warns it is never for authorization, and this is not
     * authorization: it decides which named questions appear in a list, in the same category as
     * which announcements reach a noticeboard. Reach for authorization always runs downward, and
     * it does so in the completely separate query that fetches the rows.
     *
     * Note what is also NOT here: a permission check. Seeing your own saved views, and the ones a
     * colleague shared into your department, is the same category as seeing your own drafts.
     */
    public function scopeReadableBy(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $units = app(ActorProfile::class)->ancestorOrgUnitIds($user);

        return $query->where(function (Builder $scoped) use ($user, $units) {
            $scoped->where('user_id', $user->getKey())
                ->orWhere('visibility', 'organization');

            if ($units !== []) {
                $scoped->orWhere(fn (Builder $shared) => $shared
                    ->where('visibility', 'org_unit')
                    ->whereIn('org_unit_id', $units));
            }
        });
    }

    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    // ================================================================== the conditions

    /**
     * The stored conditions, cleaned on the way OUT as well as on the way in.
     *
     * Filtered against the catalogue every time they are read, not merely when they were saved.
     * A condition on a field the register no longer offers is dropped rather than passed to the
     * compiler — and the compiler would refuse it anyway, which is the belt to this braces (§84).
     *
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    public function conditions(): array
    {
        $clean = [];

        foreach ((array) ($this->filters ?? []) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? '');

            if ($field === '' || ! array_key_exists($operator, KpiDefinition::FILTER_OPERATORS)) {
                continue;
            }

            if (! ReportCatalogue::offersFilter($this->entity_type, $field)) {
                continue;
            }

            $clean[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $filter['value'] ?? null,
            ];
        }

        return array_slice($clean, 0, self::MAX_FILTERS);
    }

    public function isShared(): bool
    {
        return $this->visibility !== 'private';
    }

    public function ownedBy(?User $user): bool
    {
        return $user !== null && (int) $this->user_id === (int) $user->getKey();
    }

    public function visibilityLabel(): string
    {
        return self::VISIBILITIES[$this->visibility] ?? $this->visibility;
    }

    public function displayName(): string
    {
        return $this->name;
    }
}
