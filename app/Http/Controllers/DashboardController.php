<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\OrgUnit;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The landing page.
 *
 * Every number here is produced by running the SAME ScopeCompiler the index pages use. That is
 * the point, and it is not an optimisation: a dashboard that counted rows directly would tell a
 * department head "312 employees" and then show them 41, which either teaches them the system is
 * wrong or leaks the size of the organization they cannot see (§61 applies the same rule to
 * search — a count is a query result too).
 *
 * Phase 1 shows the structural picture. The executive command center of §51 replaces this in
 * Phase 8; the contract that every figure is scope-filtered is what stays.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $cards = [];

        if ($user->hasPermission('employee.view')) {
            $cards[] = [
                'label' => 'People you can see',
                'value' => $this->compiler->apply(Employee::query()->active(), $user, 'employee.view')->count(),
                'icon' => 'users',
                'url' => route('employees.index'),
            ];
        }

        if ($user->hasPermission('org_unit.view')) {
            $cards[] = [
                'label' => 'Organization units',
                'value' => $this->compiler->apply(OrgUnit::query()->active(), $user, 'org_unit.view')->count(),
                'icon' => 'sitemap',
                'url' => route('org-units.index'),
            ];
        }

        if ($user->hasPermission('user.view')) {
            $cards[] = [
                'label' => 'Direct reports',
                'value' => $user->employee?->subordinates()->count() ?? 0,
                'icon' => 'user',
                'url' => route('employees.index', ['supervisor' => $user->employee?->id]),
            ];
        }

        return view('dashboard', [
            'cards' => $cards,
            'me' => $user->employee?->loadMissing(['position', 'orgUnit', 'supervisor', 'location']),
            'activity' => $this->recentActivity($request),
            'roles' => $user->activeRoles()->get(),
        ]);
    }

    /**
     * The timeline, filtered to records the viewer may actually open.
     *
     * Activity rows describe other records, so they inherit those records' visibility. Until the
     * per-subject filter of §60 lands with the modules that write these rows, this shows only
     * activity the viewer caused — which is trivially safe and never empty for the wrong reason.
     */
    private function recentActivity(Request $request)
    {
        return ActivityLog::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('causer_id', $request->user()->id)
            ->latest('created_at')
            ->limit(8)
            ->get();
    }
}
