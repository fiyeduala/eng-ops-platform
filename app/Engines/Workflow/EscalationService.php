<?php

declare(strict_types=1);

namespace App\Engines\Workflow;

use App\Engines\Access\ActorProfile;
use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStageInstance;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Chases overdue approvals up the chain (§25).
 *
 * An SLA that nobody acts on is a number in a database. This is the part that makes `sla_hours`
 * mean something: a nightly pass that finds pending stages past their due time and escalates them
 * one level at a time.
 *
 * THREE LEVELS, each a wider circle:
 *
 *   1. The assignees themselves. Overwhelmingly the whole story — people are busy, not obstructive.
 *   2. The assignees' supervisors, and the record's owner, who is the person actually being held up.
 *   3. Whoever can do something about it: holders of `approval.reassign`.
 *
 * IDEMPOTENCE IS THE WHOLE DESIGN. `last_escalated_level` is written in the same transaction as the
 * send, so a cron that fires twice, a job that retries, or an administrator running the command by
 * hand cannot produce two reminders. Getting this wrong does not corrupt data — it trains people to
 * ignore the notification, which quietly disables the feature.
 *
 * Every send goes through NotificationDispatcher with the SUBJECT as the record, so §68 applies
 * unchanged: a supervisor who cannot see a confidential document is not told it is late. The
 * escalation is silently narrower in that case, which is correct — the alternative is leaking the
 * existence and title of a restricted record to somebody's line manager.
 */
class EscalationService
{
    /** Hours after the due time before each level fires, unless settings say otherwise. */
    private const DEFAULT_STEP_HOURS = 24;

    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly SettingsRepository $settings,
        private readonly ActorProfile $profile,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * Sweep every tenant. This is what the scheduler calls.
     *
     * Runs each organization inside its own context so the global scope applies normally — the
     * alternative, an unscoped query across tenants, is exactly the shape of bug §75 exists to
     * prevent, and a nightly job is the easiest place in a codebase to introduce one.
     *
     * @return int the number of stages escalated
     */
    public function sweep(): int
    {
        $escalated = 0;

        Organization::query()->where('is_active', true)->cursor()->each(
            function (Organization $organization) use (&$escalated): void {
                $escalated += $this->context->within($organization, fn () => $this->sweepCurrentTenant());
            }
        );

        return $escalated;
    }

    /** One tenant's overdue approvals. */
    public function sweepCurrentTenant(): int
    {
        $stepHours = max(1, (int) $this->settings->int('workflow.escalation_step_hours', self::DEFAULT_STEP_HOURS));
        $maxLevel = min(3, max(1, (int) $this->settings->int('workflow.escalation_max_level', 3)));
        $count = 0;

        WorkflowStageInstance::query()
            ->overdue()
            ->whereHas('instance', fn ($q) => $q->where('status', WorkflowInstance::STATUS_RUNNING))
            ->with(['instance.subject', 'stage'])
            ->orderBy('due_at')
            ->cursor()
            ->each(function (WorkflowStageInstance $stageInstance) use ($stepHours, $maxLevel, &$count): void {
                if ($this->escalate($stageInstance, $stepHours, $maxLevel)) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Escalate one stage by at most one level.
     *
     * At most one, even if it is four days late and nobody has looked at it. Firing levels 1, 2 and
     * 3 in the same minute would land three notifications about one document on somebody's desk and
     * tell them nothing that one could not — the point of the ladder is that it is a ladder.
     */
    public function escalate(WorkflowStageInstance $stageInstance, ?int $stepHours = null, int $maxLevel = 3): bool
    {
        if (! $stageInstance->isOverdue()) {
            return false;
        }

        $stepHours ??= max(1, (int) $this->settings->int('workflow.escalation_step_hours', self::DEFAULT_STEP_HOURS));
        $current = (int) ($stageInstance->last_escalated_level ?? 0);

        if ($current >= $maxLevel) {
            return false;
        }

        $next = $current + 1;

        // Level 1 fires the moment it is late; each further level waits another step. Comparing
        // against due_at rather than the previous send means a stage that was late before the
        // scheduler was set up catches up one level per run, instead of firing the whole ladder.
        if ($stageInstance->due_at->copy()->addHours($stepHours * ($next - 1))->isFuture()) {
            return false;
        }

        $instance = $stageInstance->instance;
        $subject = $instance?->subject;

        if (! $subject instanceof Model) {
            return false;
        }

        $recipients = $this->recipientsFor($next, $stageInstance, $subject);

        return DB::transaction(function () use ($stageInstance, $next, $recipients, $subject): bool {
            // Stamped whether or not anybody was reachable. A level that resolved to nobody has
            // still been tried, and retrying it nightly forever would mean level 3 never arrives.
            $stageInstance->update([
                'last_escalated_level' => $next,
                'last_escalated_at' => now(),
            ]);

            if ($recipients === []) {
                return true;
            }

            $this->notifications->dispatch(
                NotificationMessage::make(
                    key: 'approval.overdue',
                    title: $this->titleFor($next, $subject),
                    body: $this->bodyFor($next, $stageInstance),
                    actionUrl: route('approvals.index'),
                ),
                Audience::user($recipients),
                $subject,
                'view',
                null,
                $subject->getAttribute('project_id') !== null ? (int) $subject->getAttribute('project_id') : null,
            );

            return true;
        });
    }

    // ------------------------------------------------------------------ who hears about it

    /**
     * @return array<int,int>
     */
    private function recipientsFor(int $level, WorkflowStageInstance $stageInstance, Model $subject): array
    {
        $ids = match ($level) {
            1 => $stageInstance->assigneeIds(),
            2 => array_merge(
                $this->supervisorsOf($stageInstance->assigneeIds()),
                array_filter([$this->ownerOf($subject)]),
            ),
            default => $this->holdersOf('approval.reassign'),
        };

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }

    /**
     * @param  array<int,int>  $userIds
     * @return array<int,int>
     */
    private function supervisorsOf(array $userIds): array
    {
        $out = [];

        foreach (User::query()->whereKey($userIds)->active()->get() as $user) {
            $employeeId = $this->profile->employeeId($user);

            if ($employeeId === null) {
                continue;
            }

            $chain = Employee::query()->find($employeeId)?->chainUpward(1) ?? [];

            foreach ($chain as $supervisor) {
                if ($supervisor->user_id !== null) {
                    $out[] = (int) $supervisor->user_id;
                }
            }
        }

        return $out;
    }

    private function ownerOf(Model $subject): ?int
    {
        return method_exists($subject, 'workflowOwnerId') ? $subject->workflowOwnerId() : null;
    }

    /**
     * Everybody in this tenant who could actually unstick it.
     *
     * Read off roles rather than a hard-coded "administrator", because §5 says the role names are
     * the company's to choose. The permission is the stable thing; the job title is not.
     *
     * @return array<int,int>
     */
    private function holdersOf(string $permission): array
    {
        return Role::query()
            ->active()
            // `permissions.key`, qualified. The catalogue column is `key`; an unqualified `name`
            // here resolves to `roles.name` in the correlated subquery, compares a role's title
            // with a permission string, and escalates to nobody without erroring (§25).
            ->whereHas('permissions', fn ($q) => $q->where('permissions.key', $permission))
            ->with('users:id')
            ->get()
            ->flatMap(fn (Role $role) => $role->users->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------ wording

    /**
     * Identifier only, never content.
     *
     * An escalation reaches wider and wider circles of people, and a subject line is the one part of
     * a notification that survives into inbox previews, phone lock screens and mail archives. The
     * dispatcher has already checked each recipient may see the record, but "may see it" and "should
     * have its title on their lock screen" are not the same claim (§68).
     */
    private function titleFor(int $level, Model $subject): string
    {
        $label = method_exists($subject, 'workflowLabel')
            ? $subject->workflowLabel()
            : class_basename($subject).' #'.$subject->getKey();

        return match ($level) {
            1 => 'Overdue approval: '.$label,
            2 => 'An approval is overdue: '.$label,
            default => 'An approval has been overdue for some time: '.$label,
        };
    }

    private function bodyFor(int $level, WorkflowStageInstance $stageInstance): string
    {
        $late = $stageInstance->due_at->diffForHumans(['parts' => 1, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);

        return match ($level) {
            1 => 'The "'.$stageInstance->displayName().'" stage was due '.$late.' ago and is still waiting on you.',
            2 => 'The "'.$stageInstance->displayName().'" stage has been waiting '.$late.' past its due time.',
            default => 'The "'.$stageInstance->displayName().'" stage is '.$late.' past due and has not moved. '
                .'It may need reassigning.',
        };
    }
}
