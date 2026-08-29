<?php

declare(strict_types=1);

namespace App\Engines\Access;

use App\Support\Enums\AccessScope;

/**
 * One resolved (permission, scope) grant, optionally pinned to a single org unit or project.
 *
 * The pin comes from role_user.scope_type/scope_id and NARROWS the grant. It is what makes a
 * "Project Administrator" administrative on exactly one project, and a "Department
 * Administrator" administrative in exactly one unit (section 10).
 */
final readonly class ScopeGrant
{
    public function __construct(
        public AccessScope $scope,
        public ?int $pinnedOrgUnitId = null,
        public ?int $pinnedProjectId = null,
    ) {}

    public function isPinned(): bool
    {
        return $this->pinnedOrgUnitId !== null || $this->pinnedProjectId !== null;
    }

    /** Stable identity so a scope set can de-duplicate identical grants. */
    public function signature(): string
    {
        return $this->scope->value.'|'.($this->pinnedOrgUnitId ?? '-').'|'.($this->pinnedProjectId ?? '-');
    }

    /**
     * An unpinned grant subsumes a pinned grant of the same-or-broader scope, so the compiler
     * can drop redundant predicates instead of emitting a wall of ORs.
     */
    public function subsumes(self $other): bool
    {
        if ($this->isPinned()) {
            return false;
        }

        if ($this->scope === AccessScope::Organization) {
            return true;
        }

        if ($this->scope === AccessScope::Project) {
            return $other->scope === AccessScope::Project;
        }

        if ($other->scope === AccessScope::Project) {
            return false;
        }

        return $this->scope->rank() >= $other->scope->rank();
    }

    /** @return array{scope:string,unit:?int,project:?int} */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'unit' => $this->pinnedOrgUnitId,
            'project' => $this->pinnedProjectId,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            AccessScope::from($data['scope']),
            $data['unit'] ?? null,
            $data['project'] ?? null,
        );
    }
}
