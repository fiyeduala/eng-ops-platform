<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StatusConfigRequest;
use App\Http\Requests\StatusTransitionRequest;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Services\StatusConfigService;
use App\Support\PermissionCatalogue;
use App\Support\StatusCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The status and transition editor — the state machine, handed to the company that runs on it.
 *
 * ONE PAGE PER REGISTER, WITH THE MOVES ON IT. Statuses and transitions are shown and edited
 * together because they are not two things: a status nobody can reach is a dead word, and a
 * transition is meaningless without the two states it joins. An administrator renaming "Issued" to
 * "Released" needs to see, in the same eyeful, that the move into it is gated on `permit.issue`.
 *
 * WHAT MAKES THIS SCREEN DANGEROUS is that `status_transitions` is the whitelist StatusMachine
 * consults before it moves anything. Writing to it is writing the rules. Every rule that matters is
 * therefore in StatusConfigService rather than here — the entity type check, the system-status
 * protections, the workflow-outcome guard — because this controller is one caller and a rule that
 * lives in a caller is a rule the next caller does not have.
 *
 * WHAT THIS CONTROLLER OWES on top of that is the two things a service cannot see:
 *
 *   1. AUTHORIZATION. Every action consults StatusPolicy, which folds create/update/delete onto
 *      `status.manage`. The routes carry `permission:` middleware as well; neither is redundant,
 *      because the middleware cannot see WHICH record and the policy cannot see the request (§6).
 *   2. THE PATH SEGMENTS ARE NOT EVIDENCE (§84). `{entityType}` is checked against the catalogue,
 *      and a bound status or transition that belongs to a different register 404s even though it
 *      belongs to this company — otherwise an id typed into the address bar would let somebody
 *      editing the leave vocabulary reach into payroll's.
 */
class StatusConfigController extends Controller
{
    public function __construct(
        private readonly StatusConfigService $service,
    ) {}

    /** The registers a company may configure, and how much vocabulary each carries. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Status::class);

        return view('configuration.statuses.index', [
            'registers' => $this->service->registers(),
            'canManage' => $request->user()->can('create', Status::class),
        ]);
    }

    /** One register: its vocabulary, and every move permitted between its words. */
    public function show(Request $request, string $entityType): View
    {
        $this->authorize('viewAny', Status::class);

        $entityType = $this->knownRegister($entityType);

        return view('configuration.statuses.show', [
            'entityType' => $entityType,
            'registerName' => Str::of($entityType)->replace('_', ' ')->title()->toString(),
            'statuses' => $this->service->statusesFor($entityType),
            'transitions' => $this->service->transitionsFor($entityType),

            // Shown on the page, not merely enforced on submit. An administrator who is refused
            // without being told which statuses an approval route confers will read the refusal as
            // a bug and go looking for a way round it.
            'workflowOutcomes' => $this->service->workflowOutcomes($entityType),

            'permissions' => PermissionCatalogue::keys(),
            'canManage' => $request->user()->can('create', Status::class),
        ]);
    }

    // ------------------------------------------------------------------ statuses

    public function store(StatusConfigRequest $request, string $entityType): RedirectResponse
    {
        $this->authorize('create', Status::class);

        $entityType = $this->knownRegister($entityType);

        return $this->attempt($entityType, fn () => $this->service
            ->createStatus($entityType, $request->statusAttributes(), $request->user())
            ->label.' has been added.');
    }

    public function update(StatusConfigRequest $request, string $entityType, Status $status): RedirectResponse
    {
        $this->authorize('update', $status);

        $entityType = $this->sameRegister($entityType, $status->entity_type);

        return $this->attempt($entityType, fn () => $this->service
            ->updateStatus($status, $request->statusAttributes(), $request->user())
            ->label.' has been updated.');
    }

    /**
     * Delete a state nothing is using.
     *
     * THE AUTHORITY CHECK IS `update`, NOT `delete`, AND THAT IS DELIBERATE. StatusPolicy::delete()
     * folds two unlike facts into one false: "you may not administer statuses" and "records are
     * sitting in this one". The first deserves a 403; the second deserves a sentence telling the
     * administrator to retire it instead, because they have done nothing wrong and a 403 page
     * answers a question they did not ask. So the authority question — may this person administer
     * this company's vocabulary at all, which is `status.manage` either way — is asked here, and the
     * referential refusal is raised by StatusConfigService as something readable. The policy keeps
     * its own guard for every other caller, which is the point of having it.
     */
    public function destroy(Request $request, string $entityType, Status $status): RedirectResponse
    {
        $this->authorize('update', $status);

        $entityType = $this->sameRegister($entityType, $status->entity_type);
        $label = $status->label;

        return $this->attempt($entityType, function () use ($status, $request, $label) {
            $this->service->deleteStatus($status, $request->user());

            return $label.' has been deleted.';
        });
    }

    /**
     * Put the statuses in the order the company reads them in.
     *
     * The ids arrive as a flat list and are trusted for their ORDER only: the service matches each
     * one against a scoped query and drops anything that is not this register's, so a foreign id
     * posted into this endpoint reorders nothing (§75, §84).
     */
    public function reorder(Request $request, string $entityType): RedirectResponse
    {
        $this->authorize('create', Status::class);

        $entityType = $this->knownRegister($entityType);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200'],
            'order.*' => ['integer'],
        ]);

        return $this->attempt($entityType, function () use ($entityType, $validated, $request) {
            $this->service->reorder($entityType, $validated['order'], $request->user());

            return 'The order has been saved.';
        });
    }

    // ------------------------------------------------------------------ transitions

    public function storeTransition(StatusTransitionRequest $request, string $entityType): RedirectResponse
    {
        $this->authorize('create', StatusTransition::class);

        $entityType = $this->knownRegister($entityType);

        return $this->attempt($entityType, function () use ($entityType, $request) {
            $this->service->createTransition($entityType, $request->transitionAttributes(), $request->user());

            return 'The move has been allowed.';
        });
    }

    public function updateTransition(
        StatusTransitionRequest $request,
        string $entityType,
        StatusTransition $transition,
    ): RedirectResponse {
        $this->authorize('update', $transition);

        $entityType = $this->sameRegister($entityType, $transition->entity_type);

        return $this->attempt($entityType, function () use ($transition, $request) {
            $this->service->updateTransition($transition, $request->transitionAttributes(), $request->user());

            return 'The move has been updated.';
        });
    }

    public function destroyTransition(
        Request $request,
        string $entityType,
        StatusTransition $transition,
    ): RedirectResponse {
        $this->authorize('delete', $transition);

        $entityType = $this->sameRegister($entityType, $transition->entity_type);

        return $this->attempt($entityType, function () use ($transition, $request) {
            $this->service->deleteTransition($transition, $request->user());

            return 'The move is no longer allowed.';
        });
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Run a write and turn a refusal into something an administrator can act on.
     *
     * The service raises DomainException for every integrity refusal — "that status is load-bearing",
     * "records are sitting in it", "an approval confers that status, so name a permission". Those
     * are not programming errors and must not read like one: they are the screen explaining itself,
     * so they come back as a validation message above the form rather than a 500 page.
     *
     * @param  callable():string  $write
     */
    private function attempt(string $entityType, callable $write): RedirectResponse
    {
        try {
            $message = $write();
        } catch (\DomainException $e) {
            // Deliberately WITHOUT withInput(). This page carries one form per row plus the two
            // "add" forms, all sharing field names, so repopulating from old() would push the
            // rejected row's text into every other row on the screen. The message says what was
            // refused and the row still shows what is actually stored, which is the truth.
            return back()->withErrors(['configuration' => $e->getMessage()]);
        }

        return redirect()->route('configuration.statuses.show', $entityType)->with('status', $message);
    }

    /** §84: a path segment naming a register the platform does not have is not a register. */
    private function knownRegister(string $entityType): string
    {
        if (! in_array($entityType, StatusCatalogue::entityTypes(), true)) {
            throw new NotFoundHttpException;
        }

        return $entityType;
    }

    /**
     * The bound record has to belong to the register in the URL.
     *
     * Both are this company's — the model binding saw to that — but "this company's" is not the
     * question. Without this, the id of a payroll status pasted into the leave editor's URL would
     * be edited by somebody whose business is leave, and the audit entry would name the wrong
     * register while it happened.
     */
    private function sameRegister(string $entityType, string $recordEntityType): string
    {
        $entityType = $this->knownRegister($entityType);

        if ($entityType !== $recordEntityType) {
            throw new NotFoundHttpException;
        }

        return $entityType;
    }
}
