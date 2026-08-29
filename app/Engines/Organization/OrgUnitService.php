<?php

declare(strict_types=1);

namespace App\Engines\Organization;

use App\Engines\Access\AccessResolver;
use App\Models\OrgUnit;
use App\Support\Enums\OrgUnitType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Engine 1: the configurable organization tree (sections 3, 73).
 *
 * All structural writes go through here so that three invariants can never be broken:
 *
 *   1. `path` and `depth` always describe the true ancestry of every node, including after a
 *      reorganization that moves a whole division.
 *   2. A unit can never become its own ancestor.
 *   3. Nesting respects the configured type hierarchy, so "a department inside a team" cannot be
 *      created by accident — while still allowing a company to skip levels entirely (a division
 *      may contain teams directly) because not every company has four layers.
 *
 * Moving a subtree rewrites descendant paths in ONE statement rather than N, because a
 * reorganization at a 4,000-person company must not be a 4,000-query operation.
 */
class OrgUnitService
{
    public function __construct(
        private readonly AccessResolver $resolver,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes): OrgUnit
    {
        $parent = isset($attributes['parent_id'])
            ? OrgUnit::find($attributes['parent_id'])
            : null;

        $this->assertNestingAllowed(
            OrgUnitType::from((string) $attributes['type']),
            $parent,
        );

        return DB::transaction(function () use ($attributes, $parent) {
            $unit = new OrgUnit($attributes);
            $unit->depth = $parent ? $parent->depth + 1 : 0;
            $unit->path = '/';           // placeholder; the real path needs the id
            $unit->save();

            $unit->path = ($parent?->path ?? '/').$unit->id.'/';
            $unit->saveQuietly();

            $this->bumpPermissions($unit);

            return $unit->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(OrgUnit $unit, array $attributes): OrgUnit
    {
        $newParentId = array_key_exists('parent_id', $attributes)
            ? ($attributes['parent_id'] !== null ? (int) $attributes['parent_id'] : null)
            : $unit->parent_id;

        $type = isset($attributes['type']) ? OrgUnitType::from((string) $attributes['type']) : $unit->type;

        unset($attributes['path'], $attributes['depth']);

        return DB::transaction(function () use ($unit, $attributes, $newParentId, $type) {
            $moved = $newParentId !== $unit->parent_id;

            if ($moved || $type !== $unit->type) {
                $parent = $newParentId !== null ? OrgUnit::find($newParentId) : null;
                $this->assertNestingAllowed($type, $parent, $unit);
            }

            $unit->fill($attributes);
            $unit->type = $type;
            $unit->parent_id = $newParentId;
            $unit->save();

            if ($moved) {
                $this->reparent($unit, $newParentId !== null ? OrgUnit::find($newParentId) : null);
            }

            $this->bumpPermissions($unit);

            return $unit->refresh();
        });
    }

    /**
     * Move a unit — and everything under it — to a new parent (section 73).
     *
     * Reporting lines, permissions and dashboards follow automatically, because every one of them
     * derives reach from `path` rather than from a stored copy of the hierarchy.
     */
    public function move(OrgUnit $unit, ?OrgUnit $newParent): OrgUnit
    {
        $this->assertNestingAllowed($unit->type, $newParent, $unit);

        return DB::transaction(function () use ($unit, $newParent) {
            $unit->parent_id = $newParent?->id;
            $unit->save();

            $this->reparent($unit, $newParent);
            $this->bumpPermissions($unit);

            return $unit->refresh();
        });
    }

    /**
     * Rewrite this unit's path and, in one UPDATE, every descendant's.
     */
    private function reparent(OrgUnit $unit, ?OrgUnit $newParent): void
    {
        $oldPath = (string) $unit->path;
        $newPath = ($newParent?->path ?? '/').$unit->id.'/';

        if ($oldPath === $newPath) {
            return;
        }

        $oldDepth = (int) $unit->depth;
        $newDepth = $newParent ? (int) $newParent->depth + 1 : 0;
        $depthShift = $newDepth - $oldDepth;

        // Descendants first, while $oldPath still identifies them. Cutting the old prefix by
        // length and prepending the new one is exact even when one unit id is a prefix of
        // another, because paths are delimited on both sides ("/1/23/" never matches "/1/2/").
        $offset = strlen($oldPath) + 1;

        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "? || substr(path, {$offset})"
            : "CONCAT(?, SUBSTRING(path, {$offset}))";

        DB::table('org_units')
            ->where('organization_id', $unit->organization_id)
            ->where('path', 'like', $oldPath.'%')
            ->where('id', '!=', $unit->id)
            ->update([
                'path' => DB::raw(vsprintf(str_replace('?', '%s', $expression), [
                    DB::connection()->getPdo()->quote($newPath),
                ])),
                'depth' => DB::raw('depth + '.(int) $depthShift),
                'updated_at' => now(),
            ]);

        $unit->path = $newPath;
        $unit->depth = $newDepth;
        $unit->saveQuietly();
    }

    /**
     * Reject impossible structures.
     *
     * Note what is NOT rejected: skipping levels. A company with no "sections" must be able to
     * put teams directly under a department (section 3), so only inversions are refused.
     */
    private function assertNestingAllowed(OrgUnitType $type, ?OrgUnit $parent, ?OrgUnit $moving = null): void
    {
        if ($parent === null) {
            return;
        }

        if ($moving !== null) {
            if ($parent->id === $moving->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A unit cannot report to itself.',
                ]);
            }

            // The decisive check: moving a division under one of its own departments would
            // create a cycle and an infinite subtree.
            if ($parent->isDescendantOf($moving)) {
                throw ValidationException::withMessages([
                    'parent_id' => sprintf(
                        '“%s” sits inside “%s”, so it cannot also be its parent.',
                        $parent->name,
                        $moving->name,
                    ),
                ]);
            }
        }

        if ($parent->organization_id !== ($moving?->organization_id ?? $parent->organization_id)) {
            throw ValidationException::withMessages([
                'parent_id' => 'The parent unit belongs to a different organization.',
            ]);
        }

        if ($type->depth() <= $parent->type->depth()) {
            throw ValidationException::withMessages([
                'parent_id' => sprintf(
                    'A %s cannot sit inside a %s.',
                    strtolower($type->defaultLabel()),
                    strtolower($parent->type->defaultLabel()),
                ),
            ]);
        }
    }

    /**
     * Structure changes change who can see what, so every cached permission set for the tenant
     * must be considered stale.
     */
    private function bumpPermissions(OrgUnit $unit): void
    {
        $unit->organization?->bumpPermissionsVersion();
    }

    /**
     * The whole tree as a nested array, for the org chart and for tree selects.
     *
     * @return \Illuminate\Support\Collection<int,OrgUnit>
     */
    public function tree(?int $organizationId = null)
    {
        return OrgUnit::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->with('head.user')
            ->withCount('employees')
            ->orderBy('path')
            ->get()
            ->groupBy('parent_id')
            ->pipe(fn ($grouped) => $this->assemble($grouped, null));
    }

    private function assemble($grouped, ?int $parentId)
    {
        return ($grouped[$parentId] ?? collect())->each(function (OrgUnit $unit) use ($grouped) {
            $unit->setRelation('children', $this->assemble($grouped, $unit->id));
        });
    }
}
