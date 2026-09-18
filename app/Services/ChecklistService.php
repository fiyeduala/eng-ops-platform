<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Starting somebody's joining or leaving list, and ticking it off.
 *
 * STARTING ONE COPIES THE TEMPLATE. Not a foreign key to the template's rows — a copy. The template
 * is what the company asks for today; the copy is what this person was actually asked to do, and an
 * audit a year later, or a dispute about whether somebody was ever inducted, is asking about the
 * copy. A live link would let an administrator editing a template in June change the answer to a
 * question about March.
 *
 * COMPLETION IS COUNTED, NEVER DECLARED. There is no "mark complete" — the checklist closes itself
 * when the last item is ticked, and reopens if one is unticked. A half-finished exit list that can
 * report itself complete is how a leaver keeps a laptop.
 *
 * A TICK IS SOMEBODY'S WORD, WITH THEIR NAME ON IT. `done_by` and `done_at` are recorded on every
 * item, and an item may demand a note. It is still only somebody's word, which is exactly why the
 * exit clearance in EmployeeExitService asks the asset and petty cash registers instead.
 */
class ChecklistService
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Start a checklist for somebody, from a template or empty.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function start(Employee $employee, string $kind, ?ChecklistTemplate $template, array $attributes, User $actor): EmployeeChecklist
    {
        if (! in_array($kind, ChecklistTemplate::KINDS, true)) {
            throw new \DomainException('A checklist is either an onboarding or an exit one.');
        }

        if ($template !== null && $template->kind !== $kind) {
            throw new \DomainException('That template is a '.$template->kind.' list, not a '.$kind.' one.');
        }

        // Cross-tenant belt and braces (§75). The global scope means a template from another
        // organization should never have been resolvable, and a mismatch here means something
        // reached the service another way.
        if ($template !== null && $template->organization_id !== $employee->organization_id) {
            throw new \DomainException('That template belongs to another organization.');
        }

        return DB::transaction(function () use ($employee, $kind, $template, $attributes, $actor) {
            $checklist = new EmployeeChecklist([
                'kind' => $kind,
                'checklist_template_id' => $template?->getKey(),
                'org_unit_id' => $employee->org_unit_id,
                'started_on' => $attributes['started_on'] ?? CarbonImmutable::today()->toDateString(),
                'due_on' => $attributes['due_on'] ?? null,
            ]);

            $checklist->organization_id = $employee->organization_id;   // never from input (§74)
            $checklist->employee_id = $employee->getKey();
            $checklist->created_by = $actor->getKey();
            $checklist->save();

            if ($template !== null) {
                foreach ($template->items()->get() as $item) {
                    $copy = new EmployeeChecklistItem([
                        'label' => $item->label,
                        'description' => $item->description,
                        'responsible_role_id' => $item->responsible_role_id,
                        'sequence' => $item->sequence,
                        'requires_note' => $item->requires_note,
                    ]);

                    $copy->organization_id = $checklist->organization_id;
                    $copy->employee_checklist_id = $checklist->getKey();
                    $copy->save();
                }
            }

            $checklist->load('items');

            $this->activity->record(
                $checklist,
                'created',
                $actor->name.' started a '.$kind.' checklist for '.$employee->fullName().'.',
                ['items' => $checklist->items->count()],
                $actor,
            );

            return $checklist;
        });
    }

    /** Add a step that the template did not have. */
    public function addItem(EmployeeChecklist $checklist, string $label, ?string $description, bool $requiresNote, User $actor): EmployeeChecklistItem
    {
        $label = trim($label);

        if ($label === '') {
            throw new \DomainException('Say what has to be done.');
        }

        return DB::transaction(function () use ($checklist, $label, $description, $requiresNote, $actor) {
            $item = new EmployeeChecklistItem([
                'label' => mb_substr($label, 0, 200),
                'description' => $description,
                'requires_note' => $requiresNote,
                'sequence' => (int) $checklist->items()->max('sequence') + 1,
            ]);

            $item->organization_id = $checklist->organization_id;
            $item->employee_checklist_id = $checklist->getKey();
            $item->save();

            // A new open item reopens a checklist that had closed itself.
            $this->refreshCompletion($checklist, $actor);

            $this->activity->record($checklist, 'item_added', $actor->name.' added “'.$item->label.'”.', [], $actor);

            return $item;
        });
    }

    public function tick(EmployeeChecklistItem $item, ?string $note, User $actor): EmployeeChecklistItem
    {
        if ($item->isDone()) {
            return $item;
        }

        if ($item->requires_note && blank($note)) {
            throw new \DomainException('“'.$item->label.'” needs a note saying what was done.');
        }

        return DB::transaction(function () use ($item, $note, $actor) {
            $item->forceFill([
                'done_at' => now(),
                'done_by' => $actor->getKey(),
                'note' => $note === null ? null : mb_substr($note, 0, 500),
            ])->save();

            $checklist = $item->checklist()->with('items')->first();

            $this->activity->record($checklist, 'item_done', $actor->name.' did “'.$item->label.'”.', [], $actor);

            $this->refreshCompletion($checklist, $actor);

            return $item;
        });
    }

    /**
     * Undo a tick, with a reason.
     *
     * Kept rather than left out because the alternative is worse: an item ticked by mistake that
     * cannot be unticked teaches people to start a second checklist, and the company then has two.
     */
    public function untick(EmployeeChecklistItem $item, string $reason, User $actor): EmployeeChecklistItem
    {
        if (! $item->isDone()) {
            return $item;
        }

        if (blank($reason)) {
            throw new \DomainException('Say why this is being reopened.');
        }

        return DB::transaction(function () use ($item, $reason, $actor) {
            $item->forceFill(['done_at' => null, 'done_by' => null])->save();

            $checklist = $item->checklist()->with('items')->first();

            $this->activity->record($checklist, 'item_reopened', $actor->name.' reopened “'.$item->label.'”.', ['reason' => $reason], $actor);

            $this->refreshCompletion($checklist, $actor);

            return $item;
        });
    }

    /**
     * Close the checklist when nothing is outstanding, and reopen it when something is.
     *
     * The only writer of `completed_at`, which is what makes the column trustworthy: there is no
     * path that stamps it while items are open.
     */
    public function refreshCompletion(EmployeeChecklist $checklist, User $actor): EmployeeChecklist
    {
        $checklist->load('items');

        $open = $checklist->items->whereNull('done_at')->count();
        $total = $checklist->items->count();
        $shouldBeComplete = $total > 0 && $open === 0;

        if ($shouldBeComplete && ! $checklist->isComplete()) {
            $checklist->forceFill(['completed_at' => now(), 'completed_by' => $actor->getKey()])->save();
            $this->activity->record($checklist, 'completed', $checklist->displayName().' is complete.', [], $actor);
        }

        if (! $shouldBeComplete && $checklist->isComplete()) {
            $checklist->forceFill(['completed_at' => null, 'completed_by' => null])->save();
            $this->activity->record($checklist, 'reopened', $checklist->displayName().' has an item outstanding again.', [], $actor);
        }

        return $checklist;
    }

    /** The template a new checklist of this kind starts from, when nobody picks one. */
    public function defaultTemplate(int $organizationId, string $kind): ?ChecklistTemplate
    {
        return ChecklistTemplate::query()
            ->where('organization_id', $organizationId)
            ->ofKind($kind)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }
}
