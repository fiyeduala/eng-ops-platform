<?php

declare(strict_types=1);

namespace App\Engines\Access;

use App\Support\Enums\AccessScope;

/**
 * The union of every grant a user holds for one permission key.
 *
 * Scopes are ADDITIVE. Holding `assigned` and `project` yields the union, never the
 * intersection — this is what lets an engineer see both their own department's documents and
 * everything project-wide on their projects. See docs/04-access-model.md section 5.
 */
final class ScopeSet
{
    /** @var array<string,ScopeGrant> keyed by signature */
    private array $grants = [];

    /** @param iterable<ScopeGrant> $grants */
    public function __construct(iterable $grants = [])
    {
        foreach ($grants as $grant) {
            $this->add($grant);
        }
    }

    public static function empty(): self
    {
        return new self;
    }

    public function add(ScopeGrant $grant): self
    {
        $this->grants[$grant->signature()] = $grant;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->grants === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->grants !== [];
    }

    /** @return array<int,ScopeGrant> */
    public function all(): array
    {
        return array_values($this->grants);
    }

    /**
     * Drop grants that a broader unpinned grant already covers, so the compiled SQL stays small.
     *
     * @return array<int,ScopeGrant>
     */
    public function effective(): array
    {
        $grants = $this->all();
        $kept = [];

        foreach ($grants as $candidate) {
            foreach ($grants as $other) {
                if ($other->signature() === $candidate->signature()) {
                    continue;
                }
                if ($other->subsumes($candidate)) {
                    continue 2;
                }
            }
            $kept[] = $candidate;
        }

        return $kept === [] ? $grants : $kept;
    }

    public function hasOrganizationWide(): bool
    {
        foreach ($this->grants as $grant) {
            if ($grant->scope === AccessScope::Organization && ! $grant->isPinned()) {
                return true;
            }
        }

        return false;
    }

    public function has(AccessScope $scope): bool
    {
        foreach ($this->grants as $grant) {
            if ($grant->scope === $scope) {
                return true;
            }
        }

        return false;
    }

    /** The broadest scope on the organizational axis, ignoring pins. */
    public function broadestOrganizational(): ?AccessScope
    {
        $best = null;

        foreach ($this->grants as $grant) {
            if (! $grant->scope->isOrganizational()) {
                continue;
            }
            $best = $best === null ? $grant->scope : AccessScope::broadest($best, $grant->scope);
        }

        return $best;
    }

    public function merge(self $other): self
    {
        foreach ($other->grants as $grant) {
            $this->add($grant);
        }

        return $this;
    }

    /** @return array<int,array> */
    public function toArray(): array
    {
        return array_map(fn (ScopeGrant $g) => $g->toArray(), array_values($this->grants));
    }

    public static function fromArray(array $data): self
    {
        return new self(array_map(
            fn (array $row) => ScopeGrant::fromArray($row),
            $data
        ));
    }
}
