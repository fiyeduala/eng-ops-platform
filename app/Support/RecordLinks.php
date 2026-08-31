<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;

/**
 * Where does a record live?
 *
 * The workflow engine is deliberately ignorant of what it is routing — a stage instance points at a
 * `subject` and never asks what class that is (§90). That ignorance is what lets one approvals inbox
 * serve documents, leave, purchases and timesheets, but it leaves exactly one gap: the inbox still
 * has to offer a link to the thing.
 *
 * Rather than let every generic screen grow its own `match ($subject::class)` — which is how five
 * screens end up with five slightly different ideas of where a Document lives, four of which are out
 * of date — the mapping lives here, once, keyed by the SAME morph aliases the database stores.
 *
 * TWO PROPERTIES WORTH KEEPING:
 *
 *   - It never throws. A record whose module has not been built yet, or which has been hard-deleted
 *     mid-flight, returns null and the caller renders plain text. A missing hyperlink is a small
 *     disappointment; a 500 on somebody's approval inbox is not.
 *
 *   - It is NOT an authorization check. This answers "where would that record be", not "may you open
 *     it". Callers ask the policy separately and only then use the URL — never the reverse, because
 *     a link is a disclosure of existence as much as the page behind it is (§61, §68).
 */
final class RecordLinks
{
    /**
     * Morph alias => route name. Every route named here takes the record as its only parameter.
     *
     * Keyed by alias rather than class so the map reads the same way the `*_type` columns do, and
     * so an entry for a module that has not been written yet is inert rather than a fatal
     * class-not-found.
     *
     * @var array<string,string>
     */
    private const ROUTES = [
        'project' => 'projects.show',
        'task' => 'tasks.show',
        'memo' => 'memos.show',
        'announcement' => 'announcements.show',
        'meeting' => 'meetings.show',
        'document' => 'documents.show',
        'transmittal' => 'transmittals.show',
        'employee' => 'employees.show',
        'user' => 'users.show',
        'org_unit' => 'org-units.show',
    ];

    /**
     * A URL for this record, or null if there is nowhere sensible to send somebody.
     *
     * The route is checked for existence rather than assumed: this map outlives individual phases,
     * and a stale entry should degrade to plain text rather than take a page down.
     */
    public static function for(?Model $record): ?string
    {
        if ($record === null || $record->getKey() === null) {
            return null;
        }

        $route = self::ROUTES[self::alias($record)] ?? null;

        if ($route === null || ! Route::has($route)) {
            return null;
        }

        return route($route, $record);
    }

    /**
     * What kind of thing this is, in words a person recognises.
     *
     * Derived from the alias — "document_category" reads as "Document category" — so a module added
     * to the morph map gets a sensible label here without a second list to keep in step.
     */
    public static function label(?Model $record): string
    {
        if ($record === null) {
            return 'Record';
        }

        return ucfirst(str_replace('_', ' ', self::alias($record)));
    }

    /** The morph alias, falling back to the class name for anything unmapped. */
    private static function alias(Model $record): string
    {
        $alias = array_search($record::class, Relation::morphMap(), true);

        return is_string($alias) ? $alias : class_basename($record);
    }
}
