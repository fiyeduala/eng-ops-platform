<?php

declare(strict_types=1);

namespace App\Engines\Access\Concerns;

use App\Engines\Access\ScopeCompiler;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gives a model `visibleTo()`.
 *
 * THE CONTRACT (docs/04-access-model.md section 11): every index query, export and search on a
 * scopeable model must be written as
 *
 *     Document::visibleTo($user, 'document.view')->paginate();
 *
 * A model overrides the small `access*` hooks below when its columns differ from the defaults,
 * or when "assigned to me" means something richer than a single column.
 *
 * Note the hooks are named `access*` rather than `scope*`: Laravel treats any method named
 * scopeFoo() as a local query scope, and these are plain configuration methods.
 */
trait Scopeable
{
    /**
     * Parent whose tenant, ACL and classification also protect this record. Child permissions
     * still govern the action; missing unit/project columns are resolved through this relation.
     * Relations must form an acyclic hierarchy.
     */
    public function accessParentRelation(): ?string
    {
        return null;
    }

    /**
     * Restrict a query to records the user may reach under a permission.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $user, ?string $permission = null): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return app(ScopeCompiler::class)->apply(
            $query,
            $user,
            $permission ?? static::defaultViewPermission()
        );
    }

    /**
     * The permission checked when a caller does not name one.
     * Derived from the model name: Document -> document.view, HseIncident -> hse_incident.view.
     */
    public static function defaultViewPermission(): string
    {
        return static::permissionModule().'.view';
    }

    public static function permissionModule(): string
    {
        return str(class_basename(static::class))->snake()->toString();
    }

    /**
     * Columns that mean "this record is mine".
     *
     * @return array<int,string>
     */
    public function accessOwnerColumns(): array
    {
        return ['created_by', 'owner_id'];
    }

    /**
     * Columns that mean "this record is assigned to me".
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['assignee_id'];
    }

    /** Column carrying the owning organization unit, or null if the model has none. */
    public function accessOrgUnitColumn(): ?string
    {
        return 'org_unit_id';
    }

    /**
     * Abilities for which a narrower scope is MEANINGLESS on this model, not merely narrower.
     *
     * A few models are organization-level reference data: a site, a role definition, a currency.
     * They belong to no unit, no project and no person, so "department scope" cannot select a
     * subset — it selects nothing, and a Department Head granted `*.view` would find the site
     * list empty. Naming an ability here says: holding it AT ALL reaches every row of this model
     * inside the tenant.
     *
     * This is deliberately opt-in and per-ability, never per-model. LIST an ability here only
     * when reaching every row is harmless; never list a write ability, because "edit roles in my
     * department" quietly becoming "edit every role" is a privilege-escalation path — a role's
     * grants apply everywhere it is assigned.
     *
     * Gates 1, 2, 4 and 5 all still apply: the tenant scope, the permission check, an explicit
     * DENY row, and confidentiality are untouched by this.
     *
     * @return array<int,string> full permission keys
     */
    public function accessOrganizationWideAbilities(): array
    {
        return [];
    }

    /** Column carrying the project, or null if the model is not project-attached. */
    public function accessProjectColumn(): ?string
    {
        return 'project_id';
    }

    /**
     * Timestamp column that decides whether this record has been PUBLISHED yet, or null for
     * models where every row is real the moment it is saved.
     *
     * A memo and an announcement are the two records in the system that exist in a state before
     * they are meant to be read. Ordinary scope cannot express that: a memo drafted for the whole
     * of Engineering has `org_unit_id = Engineering` from the first keystroke, so every scope rule
     * that will reach it once issued already reaches it while it is half-written. That is not a
     * cosmetic problem — an unissued memo is a document somebody is still deciding whether to
     * send, and the recipients reading it before that decision is made is the worst possible
     * outcome for the module.
     *
     * When this returns a column, ScopeCompiler adds one more predicate: the column is not null,
     * OR the record is the actor's own, OR the actor has been shared it explicitly, OR the actor
     * holds the publication ability below. Drafts are otherwise invisible to everyone, including
     * a director with organization-wide reach — the whole point is that reach does not apply yet.
     */
    public function accessPublicationColumn(): ?string
    {
        return null;
    }

    /**
     * The permission whose holders may see this model's DRAFTS.
     *
     * Not `edit` and not `manage`: the question is who decides that a draft becomes a
     * communication, which is exactly the ability behind the publish transition (`memo.issue`,
     * `announcement.publish`). Their ordinary scope still applies on top — this lifts the draft
     * curtain, it does not widen reach — so a department-scoped issuer sees their own
     * department's drafts and nobody else's.
     *
     * Deliberately mirrors how `<module>.view_confidential` works in applyConfidentiality().
     */
    public function accessPublicationAbility(): ?string
    {
        return null;
    }

    /**
     * Extend "assigned to me" beyond simple columns — participants, followers, reviewers.
     *
     * Called inside an OR group, so implementations must use orWhere / orWhereExists.
     *
     * @param  Builder<static>  $query
     */
    public function applyAssignedScope(Builder $query, User $user): void
    {
        foreach ($this->accessAssigneeColumns() as $column) {
            $query->orWhere($this->qualifyColumn($column), $user->id);
        }
    }

    /**
     * What confidentiality a NEW record of this kind starts at.
     *
     * Internal is right for nearly everything: a deliverable, an RFI, a vendor and a task are all
     * things the company is meant to be able to read. The exception is the contract register, and
     * a migration-level column default was not enough to express it — RegisterService stamps this
     * value onto every record before saving, so the database default never applies and a contract
     * created by a seeder, an import or another module would silently come out internal while the
     * policy, the form and the docs all said confidential.
     *
     * Putting it on the model rather than in the service keeps the answer next to the reason. A
     * model that knows it is dangerous says so once, and every path that creates one obeys.
     */
    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Internal;
    }
}
