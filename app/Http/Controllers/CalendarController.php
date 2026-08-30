<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The calendar (§19, §90).
 *
 * Read-only, and deliberately not a module. There is no `calendar` permission, no calendar table and
 * no calendar record: a calendar is a VIEW over things that already exist and are already governed
 * by their own rules. Giving it a permission of its own would create a second, weaker door onto
 * meetings and tasks — which is exactly the "collection of unconnected modules" §90 forbids, and the
 * classic way an aggregation screen becomes the hole in an otherwise sound authorization model.
 *
 * So each source is fetched through its OWN scope-compiled query with its OWN ability:
 *
 *   meetings   `meeting.view`   — starts_at
 *   tasks      `task.view`      — due_on, open only
 *   projects   `project.view`   — planned_start / planned_end as milestones
 *
 * Three queries rather than one union, because a union would need a single ability and there is no
 * honest choice of one. Somebody who can see meetings but not tasks gets a calendar of meetings; the
 * route requires only that they hold at least one of the two, and each source independently returns
 * nothing when they do not.
 *
 * Nothing here re-implements reach, and nothing here filters in PHP. If a record is on this screen,
 * the same compiler that governs its own index put it there.
 */
class CalendarController extends Controller
{
    /** A window wider than this is a report, not a calendar. */
    private const MAX_DAYS = 120;

    public function __construct(
        private readonly ScopeCompiler $compiler,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        [$from, $to] = $this->window($request);

        $showMeetings = $user->hasPermission('meeting.view');
        $showTasks = $user->hasPermission('task.view');
        $showProjects = $user->hasPermission('project.view');

        $entries = collect()
            ->concat($showMeetings ? $this->meetings($request, $from, $to) : [])
            ->concat($showTasks ? $this->tasks($request, $from, $to) : [])
            ->concat($showProjects ? $this->milestones($request, $from, $to) : [])
            ->sortBy(fn (array $entry) => [$entry['date']->timestamp, $entry['sort']])
            ->values();

        return view('calendar.index', [
            'from' => $from,
            'to' => $to,
            'previous' => $from->copy()->subMonthNoOverflow(),
            'next' => $from->copy()->addMonthNoOverflow(),
            'today' => Carbon::today(),

            // Grouped by day for the grid; the flat list is kept for the agenda beside it.
            'days' => $entries->groupBy(fn (array $entry) => $entry['date']->toDateString()),
            'entries' => $entries,
            'sources' => [
                'meetings' => $showMeetings,
                'tasks' => $showTasks,
                'projects' => $showProjects,
            ],
        ]);
    }

    /**
     * The month being viewed, clamped.
     *
     * A `?from=` of arbitrary width would let one request ask for a decade of every meeting in the
     * company — authorized, but a denial of service against the database all the same.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    private function window(Request $request): array
    {
        $month = (string) $request->query('month', '');

        try {
            $from = $month !== '' ? Carbon::parse($month.'-01')->startOfMonth() : Carbon::now()->startOfMonth();
        } catch (\Throwable) {
            $from = Carbon::now()->startOfMonth();
        }

        $to = $from->copy()->endOfMonth();

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $to = $from->copy()->addDays(self::MAX_DAYS);
        }

        return [$from, $to->endOfDay()];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function meetings(Request $request, Carbon $from, Carbon $to): Collection
    {
        return $this->compiler
            ->apply(Meeting::query(), $request->user(), 'meeting.view')
            ->between($from, $to)
            // `key` is selected because isCancelled() reads it — without it the column is simply
            // absent and every cancelled meeting would quietly render as live.
            ->with(['status:id,key,label,color,is_terminal', 'project:id,name,number'])
            ->orderBy('starts_at')
            ->limit(500)
            ->get()
            ->map(fn (Meeting $meeting) => [
                'type' => 'meeting',
                'date' => $meeting->starts_at->copy()->startOfDay(),
                'time' => $meeting->starts_at->format('H:i'),
                'sort' => $meeting->starts_at->format('Hi'),
                'title' => $meeting->title,
                'reference' => $meeting->number,
                'url' => route('meetings.show', $meeting),
                'context' => $meeting->location ?: $meeting->project?->name,
                'muted' => $meeting->isCancelled(),
            ]);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function tasks(Request $request, Carbon $from, Carbon $to): Collection
    {
        return $this->compiler
            ->apply(Task::query(), $request->user(), 'task.view')
            ->whereNotNull('due_on')
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()])
            ->whereHas('status', fn ($s) => $s->where('is_terminal', false))
            ->with(['status:id,label,color', 'project:id,name,number', 'assignee:id,name'])
            ->orderBy('due_on')
            ->limit(500)
            ->get()
            ->map(fn (Task $task) => [
                'type' => 'task',
                'date' => $task->due_on->copy()->startOfDay(),
                'time' => null,
                'sort' => '9998',
                'title' => $task->title,
                'reference' => $task->number,
                'url' => route('tasks.show', $task),
                'context' => $task->assignee?->name ?? $task->project?->name,
                'muted' => false,
            ]);
    }

    /**
     * Project starts and ends, as milestones.
     *
     * Two entries per project rather than a bar across the month: a calendar answers "what happens
     * on this day", and a project spanning a year would otherwise paint every cell in the grid and
     * hide the things that actually need attention. The Gantt view is where duration belongs.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function milestones(Request $request, Carbon $from, Carbon $to): Collection
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $projects = $this->compiler
            ->apply(Project::query()->active(), $request->user(), 'project.view')
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('planned_start', [$fromDate, $toDate])
                    ->orWhereBetween('planned_end', [$fromDate, $toDate]);
            })
            ->limit(200)
            ->get();

        $entries = collect();

        foreach ($projects as $project) {
            foreach ([['planned_start', 'starts'], ['planned_end', 'ends']] as [$column, $label]) {
                $date = $project->{$column};

                if ($date === null || $date->lt($from) || $date->gt($to)) {
                    continue;
                }

                $entries->push([
                    'type' => 'project',
                    'date' => $date->copy()->startOfDay(),
                    'time' => null,
                    'sort' => '9999',
                    'title' => $project->name.' '.$label,
                    'reference' => $project->number,
                    'url' => route('projects.show', $project),
                    'context' => null,
                    'muted' => false,
                ]);
            }
        }

        return $entries;
    }
}
