<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\PermissionCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The audit trail, read-only (§59, §88).
 *
 * There is no store, update or destroy here and there never will be. The refusal is stated three
 * independent times — this controller has no write route, AuditLogPolicy returns false for every
 * write ability, and the AuditLog model throws on update and delete — because an audit trail that
 * the people it audits can edit is not an audit trail.
 *
 * `audit_log.view` is marked sensitive in the catalogue: reading this page reveals who did what,
 * everywhere in the company, including field-level before/after values. The tenant scope on
 * AuditLog still applies (Gate 1), so this shows one company's history and never another's.
 */
class AuditLogController extends Controller
{
    /** Filters wide enough to answer a real question, narrow enough to stay indexed. */
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->with('user:id,name');

        if ($term = trim((string) $request->query('q', ''))) {
            $like = '%'.$term.'%';
            $query->where(fn ($q) => $q->where('description', 'like', $like)
                ->orWhere('user_name', 'like', $like)
                ->orWhere('action', 'like', $like));
        }

        if (($module = (string) $request->query('module', '')) !== ''
            && array_key_exists($module, PermissionCatalogue::modules())) {
            $query->where('module', $module);
        }

        // The actor filter is an id, so it is checked against this tenant rather than trusted.
        // A row from another company would find nothing anyway (Gate 1), but a filter that
        // silently returns an empty page teaches an attacker which ids exist elsewhere (§84).
        if ($actorId = (int) $request->query('user_id', 0)) {
            $query->where('user_id', User::whereKey($actorId)->value('id'));
        }

        if ($request->boolean('privileged')) {
            $query->where('is_privileged', true);
        }

        if ($from = $request->date('from')) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if ($to = $request->date('to')) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        return view('audit.index', [
            'entries' => $query->latest('created_at')->paginate(self::PER_PAGE)->withQueryString(),
            'modules' => $this->moduleOptions(),
            'actors' => $this->actorOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'module' => $module,
                'user_id' => $actorId ?: null,
                'privileged' => $request->boolean('privileged'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
        ]);
    }

    public function show(Request $request, AuditLog $auditLog): View
    {
        $this->authorize('view', $auditLog);

        $auditLog->loadMissing('user:id,name');

        // The subject may have been deleted since, or may be a record this viewer cannot reach.
        // The entry is still shown — that is the point of an audit trail — but the link to the
        // live record is offered only if they could open it, so the page never becomes a side
        // door into a record the access model refuses them (§61).
        $subject = null;
        $subjectVisible = false;

        try {
            $subject = $auditLog->auditable;
            $subjectVisible = $subject !== null && $request->user()->can('view', $subject);
        } catch (\Throwable) {
            // A morph class that no longer maps to a model must not fatal the audit page.
        }

        return view('audit.show', [
            'entry' => $auditLog,
            'subject' => $subject,
            'subjectVisible' => $subjectVisible,
            'subjectLabel' => $this->subjectLabel($auditLog, $subject),
            'subjectUrl' => $subjectVisible ? $this->subjectUrl($auditLog, $subject) : null,
            'changes' => $this->diff($auditLog),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A name for the record this entry is about.
     *
     * Attributes only, never a relation: the subject was loaded outside the eager-loading the rest
     * of this page was built with, and an audit page must not fall over because the record it
     * describes has since lost its unit. A subject that no longer exists still gets a label — the
     * whole point of the trail is that it outlives what it records.
     */
    private function subjectLabel(AuditLog $entry, ?object $subject): string
    {
        $fallback = Str::headline((string) $entry->auditable_type).' #'.$entry->auditable_id;

        if (! $subject instanceof Model) {
            return $fallback;
        }

        if (method_exists($subject, 'fullName')) {
            return $subject->fullName() ?: $fallback;
        }

        foreach (['name', 'title', 'display_name', 'key'] as $attribute) {
            if (filled($subject->getAttribute($attribute))) {
                return (string) $subject->getAttribute($attribute);
            }
        }

        return $fallback;
    }

    /**
     * A link to the live record — only when the viewer could have opened it anyway.
     *
     * Called only after `can('view', $subject)` has passed, so this never becomes a side door into
     * a record the access model refuses (§61). Types with no page of their own simply get no link.
     */
    private function subjectUrl(AuditLog $entry, ?object $subject): ?string
    {
        $routes = [
            'organization' => 'organization.edit',
            'org_unit' => 'org-units.show',
            'user' => 'users.show',
            'employee' => 'employees.show',
            'position' => 'positions.edit',
            'location' => 'locations.edit',
            'role' => 'roles.show',
        ];

        $name = $routes[$entry->auditable_type] ?? null;

        if ($name === null || ! Route::has($name) || ! $subject instanceof Model) {
            return null;
        }

        // The organization page is a singleton — it takes no parameter, and passing one would
        // produce a URL with a stray segment rather than an error.
        return $entry->auditable_type === 'organization' ? route($name) : route($name, $subject);
    }

    /**
     * Field-level before/after, aligned so the view renders one row per changed attribute.
     *
     * @return array<int,array{field:string,old:mixed,new:mixed}>
     */
    private function diff(AuditLog $entry): array
    {
        $old = $entry->old_values ?? [];
        $new = $entry->new_values ?? [];
        $fields = array_keys($old + $new);
        sort($fields);

        $rows = [];

        foreach ($fields as $field) {
            $rows[] = [
                'field' => (string) $field,
                'old' => $old[$field] ?? null,
                'new' => $new[$field] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Only modules that actually appear in this tenant's trail. Offering all ninety would be a
     * filter nobody can use.
     *
     * @return array<string,string>
     */
    private function moduleOptions(): array
    {
        $present = AuditLog::query()
            ->whereNotNull('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module');

        $catalogue = PermissionCatalogue::modules();
        $out = [];

        foreach ($present as $module) {
            $out[$module] = $catalogue[$module][1] ?? ucfirst(str_replace('_', ' ', (string) $module));
        }

        return $out;
    }

    /**
     * People who appear as actors in this tenant's trail.
     *
     * Names come from the audit rows themselves, not from a user lookup: an account deleted last
     * year still has to be identifiable in the history it left behind.
     *
     * @return array<int,string>
     */
    private function actorOptions(): array
    {
        return AuditLog::query()
            ->whereNotNull('user_id')
            ->orderBy('user_name')
            ->pluck('user_name', 'user_id')
            ->filter()
            ->all();
    }
}
