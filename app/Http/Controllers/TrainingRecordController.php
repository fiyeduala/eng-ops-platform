<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\TrainingRecordRequest;
use App\Models\Document;
use App\Models\TrainingCourse;
use App\Models\TrainingRecord;
use App\Models\User;
use App\Services\TrainingRecordService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who holds what, and until when.
 *
 * THE EXPIRY REPORT IS THE PRODUCT. Nobody opens a training register to admire it; they open it
 * because somebody is going into a confined space on Thursday and the question is whether their
 * ticket is still good. Every default on this screen is built around `expires_on`, and the index
 * sorts by it before anything else.
 *
 * `own` SCOPE HERE MEANS "ABOUT ME", NOT "ENTERED BY ME" — TrainingRecord widens its owner columns
 * to include `user_id`, so an employee at `own` scope sees their own certificates. The policy then
 * takes the edit rights back off them, because a register in which the holder can adjust their own
 * expiry date records wishes rather than facts. Nothing in this controller re-decides either half;
 * `scopedQuery()` narrows the list and `authorize()` decides the row (§6, §84).
 *
 * RENEWAL CREATES A NEW RECORD AND LEAVES THE OLD ONE ALONE. The certificate somebody held in 2024
 * was valid in 2024, and that is precisely the fact an investigation asks about two years later.
 * Overwriting it would erase the only evidence that the answer was once yes (§59).
 *
 * THE CERTIFICATE FILE IS A DOCUMENT (§67). `document_id` points into document control; downloads
 * authorize against the document. This register never becomes a second file store.
 */
class TrainingRecordController extends RegisterController
{
    /** How far ahead the "expiring" view looks. A display horizon, not a rule — nothing enforces it. */
    private const EXPIRY_HORIZON_DAYS = 60;

    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly TrainingRecordService $records,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'hse_training';
    }

    protected function modelClass(): string
    {
        return TrainingRecord::class;
    }

    protected function routePrefix(): string
    {
        return 'training-records';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainingRecord::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'course:id,name,code,validity_months',
            'user:id,name',
            'project:id,name,number',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($courseId = (int) $request->query('course', 0)) {
            $query->where('training_course_id', $courseId);
        }

        if ($personId = (int) $request->query('person', 0)) {
            $query->where('user_id', $personId);
        }

        $view = (string) $request->query('view', 'valid');

        match ($view) {
            'expiring' => $query->expiringWithin(self::EXPIRY_HORIZON_DAYS),
            'expired' => $query->expired(),
            // "Mine" on this register is the one place in the application where it means the record
            // is ABOUT you rather than entered by you — see the class docblock.
            'mine' => $query->where('user_id', $user->id),
            'all' => $query,
            default => $query->where(fn ($q) => $q->whereNull('expires_on')
                ->orWhereDate('expires_on', '>=', now()->toDateString())),
        };

        /** @var LengthAwarePaginator $records */
        $records = $query
            ->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('training-records.index', [
            'records' => $records,
            'statuses' => $this->statuses->optionsFor('hse_training'),
            'projects' => $this->projectOptions($user),
            'courses' => $this->courseOptions(),
            'people' => $this->userOptions($user),
            'horizon' => self::EXPIRY_HORIZON_DAYS,
            'views' => [
                'valid' => 'Current',
                'expiring' => 'Expiring within '.self::EXPIRY_HORIZON_DAYS.' days',
                'expired' => 'Lapsed',
                'mine' => 'My certificates',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request, 'valid'),
                'course' => $request->query('course'),
                'person' => $request->query('person'),
            ],
            'canCreate' => $user->can('create', TrainingRecord::class),
        ]);
    }

    public function show(Request $request, TrainingRecord $trainingRecord): View
    {
        $this->authorize('view', $trainingRecord);

        $user = $request->user();

        $trainingRecord->loadMissing([
            'status', 'project', 'orgUnit',
            'course', 'user:id,name', 'creator:id,name', 'document:id,number,title',
        ]);

        // The certificate FILE lives in document control and is authorized there. Offering the link
        // only when the viewer can actually open the document keeps this page from advertising a
        // file it will then refuse to hand over (§67, §68).
        $document = $trainingRecord->document;

        return view('training-records.show', [
            'record' => $trainingRecord,
            'timeline' => $this->activity->timelineFor($trainingRecord)->limit(25)->get(),
            'transitions' => $this->statuses->available('hse_training', $trainingRecord->status_id, $user),
            'workflowInstance' => $trainingRecord->currentWorkflowInstance(),
            'canOpenCertificate' => $document !== null && $user->can('view', $document),
            'canEdit' => $user->can('update', $trainingRecord),
            'canDelete' => $user->can('delete', $trainingRecord),
            'canRenew' => $user->can('create', TrainingRecord::class),
            ...$this->formOptions($request),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', TrainingRecord::class);

        return view('training-records.create', [
            'record' => new TrainingRecord([
                // A certificate names a person and frequently carries a score. It is not something
                // the whole company needs to browse, and `restricted` is the honest default.
                'confidentiality' => Confidentiality::Restricted->value,
                'training_course_id' => (int) $request->query('course', 0) ?: null,
                'user_id' => (int) $request->query('person', 0) ?: null,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'completed_on' => now()->toDateString(),
                'result' => 'passed',
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(TrainingRecordRequest $request): RedirectResponse
    {
        $this->authorize('create', TrainingRecord::class);

        $data = $request->trainingAttributes();

        $this->assertPlacementIsReachable($data);

        $record = $this->records->create($data, $request->user());

        return redirect($this->showRoute($record))
            ->with('status', $record->displayName().' has been recorded.');
    }

    public function edit(Request $request, TrainingRecord $trainingRecord): View
    {
        $this->authorize('update', $trainingRecord);

        return view('training-records.edit', [
            'record' => $trainingRecord,
            ...$this->formOptions($request),
        ]);
    }

    public function update(TrainingRecordRequest $request, TrainingRecord $trainingRecord): RedirectResponse
    {
        $this->authorize('update', $trainingRecord);

        $data = $request->trainingAttributes();

        $this->assertPlacementIsReachable($data);

        $this->records->update($trainingRecord, $data, $request->user());

        return redirect($this->showRoute($trainingRecord))
            ->with('status', $trainingRecord->displayName().' has been updated.');
    }

    public function destroy(Request $request, TrainingRecord $trainingRecord): RedirectResponse
    {
        $this->authorize('delete', $trainingRecord);

        $name = $trainingRecord->displayName();

        $this->recordDeletion($trainingRecord, $request->user(), 'training record');

        $trainingRecord->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ renewal

    /**
     * Record the replacement ticket.
     *
     * TWO AUTHORIZATIONS, AND BOTH ARE NEEDED. `view` on the certificate being renewed, because the
     * new record is seeded from it — the course, the person, the company, the placement — and
     * copying those out of a record you cannot read is a disclosure dressed up as a convenience
     * (§68). Then `create` on the class, because what this endpoint actually does is create a
     * record, and it must not be a way around whatever `store` requires.
     *
     * There is no `renew` permission and there should not be: renewing IS creating, and a separate
     * checkbox would be a second answer to a question already answered (§32).
     */
    public function renew(TrainingRecordRequest $request, TrainingRecord $trainingRecord): RedirectResponse
    {
        $this->authorize('view', $trainingRecord);
        $this->authorize('create', TrainingRecord::class);

        $data = $request->trainingAttributes();

        $this->assertPlacementIsReachable($data);

        $renewal = $this->records->renew($trainingRecord, $data, $request->user());

        return redirect($this->showRoute($renewal))
            ->with('status', $renewal->displayName().' has been recorded — '.$trainingRecord->number.' is kept as it was.');
    }

    public function transition(Request $request, TrainingRecord $trainingRecord): RedirectResponse
    {
        return $this->moveStatus($request, $trainingRecord);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'courses' => $this->courseOptions(),
            'results' => $this->lists->for('hse_training.results', TrainingRecord::DEFAULT_RESULTS),
            'certificates' => $this->certificateOptions($request->user()),
            'nextNumber' => $this->numbers->preview('hse_training'),
        ];
    }

    /**
     * The documents this person could attach as the scanned certificate.
     *
     * SCOPED THROUGH `document.view` LIKE ANY OTHER DOCUMENT LIST. A picker is a list of what
     * exists, and offering numbers and titles somebody cannot open tells them about records they
     * were never meant to know of (§61). The form request re-validates the chosen id against the
     * tenant regardless, so nothing here is the authorization.
     *
     * @return array<int,string>
     */
    private function certificateOptions(User $user): array
    {
        return $this->compiler->apply(Document::query(), $user, 'document.view')
            ->orderByDesc('id')->limit(200)->get()
            ->mapWithKeys(fn (Document $document) => [$document->id => $document->displayName()])
            ->all();
    }

    /**
     * The active catalogue.
     *
     * Retired courses are left out of the picker but stay on the records already written against
     * them, which is the whole reason `is_active` exists rather than a delete button.
     *
     * @return array<int|string,string>
     */
    private function courseOptions(): array
    {
        return TrainingCourse::query()
            ->active()
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->mapWithKeys(fn (TrainingCourse $c) => [$c->id => $c->displayName()])
            ->all();
    }
}
