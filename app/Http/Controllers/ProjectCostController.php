<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Models\Project;
use App\Models\ProjectCost;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only project-cost ledger. Rows are posted by their source service. */
class ProjectCostController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProjectCost::class);
        $user = $request->user();
        $query = $this->compiler->apply(ProjectCost::query(), $user, 'expense.view')
            ->with(['project:id,number,name', 'orgUnit:id,name', 'vendor:id,name', 'creator:id,name']);

        $query->search((string) $request->query('q', ''));
        if ($project = (int) $request->query('project', 0)) {
            $query->where('project_id', $project);
        }
        if ($from = (string) $request->query('from', '')) {
            $query->whereDate('incurred_at', '>=', $from);
        }
        if ($to = (string) $request->query('to', '')) {
            $query->whereDate('incurred_at', '<=', $to);
        }

        $baseTotals = (clone $query)->selectRaw('base_currency, SUM(base_amount) total')
            ->groupBy('base_currency')->pluck('total', 'base_currency');

        /** @var LengthAwarePaginator $costs */
        $costs = $query->orderByDesc('incurred_at')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('project-costs.index', [
            'costs' => $costs,
            'baseTotals' => $baseTotals,
            'projects' => Project::visibleTo($user, 'project.view')->orderBy('name')
                ->pluck('name', 'id')->all(),
            'filters' => [
                'q' => $request->query('q'),
                'project' => $request->query('project'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
        ]);
    }

    public function show(ProjectCost $projectCost): View
    {
        $this->authorize('view', $projectCost);
        $projectCost->loadMissing([
            'project:id,number,name', 'orgUnit:id,name', 'vendor:id,name', 'creator:id,name', 'source',
        ]);

        return view('project-costs.show', [
            'cost' => $projectCost,
            'timeline' => $this->activity->timelineFor($projectCost)->limit(25)->get(),
        ]);
    }
}
