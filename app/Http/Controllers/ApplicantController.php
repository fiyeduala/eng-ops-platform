<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ApplicantRequest;
use App\Models\Applicant;
use App\Models\ApplicantStage;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Position;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\ApplicantService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The people who applied, what was asked of them, and who was taken on.
 *
 * AN APPLICANT IS RESTRICTED BY DEFAULT and the register is deliberately thin on search: names and
 * numbers only, never email, phone or notes, so a whole contact list cannot be dragged out of a
 * search box by somebody who may open one application (§61).
 *
 * THE CV IS NOT A PUBLIC URL. It is stored outside the web root under the organisation's own folder
 * with a generated name, and reaching it means passing this controller's `view` check on the
 * applicant it belongs to — a stored filename is never echoed back into a header (§67, §84).
 *
 * HIRING IS THE ONE ACTION THAT WRITES OUTSIDE RECRUITMENT. It needs `recruitment.hire`, it refuses
 * anybody who was not formally offered the job, and the service does the writing under a lock so a
 * double-click cannot enter two people into the personnel register.
 */
class ApplicantController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly ApplicantService $applicants,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'applicant';
    }

    protected function modelClass(): string
    {
        return Applicant::class;
    }

    protected function routePrefix(): string
    {
        return 'applicants';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Applicant::class);
        $user = $request->user();
        $query = $this->scopedQuery($user, 'recruitment.view')->with(['status', 'vacancy']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'live');

        // The vacancy filter is an id from this tenant's own list; one from elsewhere simply
        // matches nothing, because the query is already narrowed to the organisation (§75).
        if ($vacancyId = (int) $request->query('vacancy', 0)) {
            $query->forVacancy($vacancyId);
        }

        if ($filters['view'] === 'live') {
            $query->whereHas('status', fn ($q) => $q->whereIn('key', ApplicantService::LIVE));
        }

        return view('applicants.index', [
            'applicants' => $query->orderByDesc('id')->paginate(30)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('applicant'),
            'vacancies' => $this->vacancyOptions($user),
            'filters' => [...$filters, 'vacancy' => $request->query('vacancy')],
        ]);
    }

    public function show(Request $request, Applicant $applicant): View
    {
        $this->authorize('view', $applicant);
        $user = $request->user();
        $applicant->load(['status', 'vacancy.status', 'hiredEmployee', 'creator', 'stages.interviewer', 'stages.recorder']);

        return view('applicants.show', [
            'applicant' => $applicant,
            'stages' => $applicant->stages->sortBy('sequence'),
            'timeline' => $this->activity->timelineFor($applicant)->limit(25)->get(),
            'canEdit' => $user->can('update', $applicant),
            'canDelete' => $user->can('delete', $applicant),
            'canDownload' => $user->can('download', $applicant),
            'canAttachCv' => $user->can('attachCv', $applicant),
            'canRecordStage' => $user->can('recordStage', $applicant),
            'canDecide' => $user->can('decide', $applicant),
            'canHire' => $user->can('hire', $applicant),
            'people' => $user->can('recordStage', $applicant) ? $this->userOptions($user) : [],
            ...($user->can('hire', $applicant) ? $this->hireOptions($user) : ['positions' => [], 'units' => [], 'locations' => [], 'supervisors' => []]),
        ]);
    }

    public function create(Request $request, Vacancy $vacancy): View
    {
        $this->authorize('create', Applicant::class);
        // Sight of the vacancy is a separate question from the right to record an application,
        // and a vacancy id typed into the URL is answered by the policy, not by the form (§84, §94).
        $this->authorize('view', $vacancy);

        return view('applicants.create', [
            'vacancy' => $vacancy,
            'applicant' => new Applicant,
            'sources' => $this->sourceOptions(),
        ]);
    }

    public function store(ApplicantRequest $request, Vacancy $vacancy): RedirectResponse
    {
        $this->authorize('create', Applicant::class);
        $this->authorize('view', $vacancy);

        try {
            $applicant = $this->applicants->createFor(
                $vacancy,
                $request->safe()->except('cv'),
                $request->user(),
                $request->file('cv'),
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['cv' => $e->getMessage()]);
        }

        return redirect()->route('applicants.show', $applicant)
            ->with('status', $applicant->fullName().' has been recorded as an applicant.');
    }

    public function edit(Request $request, Applicant $applicant): View
    {
        $this->authorize('update', $applicant);

        return view('applicants.edit', [
            'applicant' => $applicant,
            'vacancy' => $applicant->vacancy,
            'sources' => $this->sourceOptions(),
        ]);
    }

    public function update(ApplicantRequest $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('update', $applicant);

        try {
            $this->applicants->update($applicant, $request->safe()->except('cv'), $request->user());

            if ($request->hasFile('cv')) {
                $this->authorize('attachCv', $applicant);
                $this->applicants->attachCv($applicant, $request->file('cv'), $request->user());
            }
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['cv' => $e->getMessage()]);
        }

        return redirect()->route('applicants.show', $applicant)
            ->with('status', 'The application has been updated.');
    }

    public function destroy(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('delete', $applicant);
        $this->recordDeletion($applicant, $request->user(), 'application');
        $applicant->delete();

        return redirect()->route('applicants.index')->with('status', 'The application has been deleted.');
    }

    // ------------------------------------------------------------------ the CV

    public function attachCv(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('attachCv', $applicant);

        $request->validate([
            'cv' => [
                'required', 'file',
                'max:'.(int) (ApplicantService::MAX_FILE_BYTES / 1024),
                'mimes:'.implode(',', ApplicantService::ALLOWED_EXTENSIONS),
            ],
        ]);

        try {
            $this->applicants->attachCv($applicant, $request->file('cv'), $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['cv' => $e->getMessage()]);
        }

        return back()->with('status', 'The CV has been attached.');
    }

    public function removeCv(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('attachCv', $applicant);

        try {
            $this->applicants->removeCv($applicant, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['cv' => $e->getMessage()]);
        }

        return back()->with('status', 'The CV has been removed.');
    }

    /**
     * Hand the file over, having asked the policy first.
     *
     * Same shape as every other private download in the platform: authorize the RECORD, 404 if the
     * file is not where the row says it is, write the download to the timeline, and build the name
     * given to the browser from the applicant's number rather than echoing a filename an outsider
     * chose (§67, §84).
     */
    public function download(Request $request, Applicant $applicant): StreamedResponse
    {
        $this->authorize('download', $applicant);

        abort_unless($applicant->hasCv(), 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists((string) $applicant->cv_path), 404);

        $this->activity->record(
            $applicant,
            'downloaded',
            $request->user()->name.' downloaded the CV.',
            ['file_name' => $applicant->cv_name],
            $request->user(),
        );

        $extension = mb_strtolower(pathinfo((string) $applicant->cv_path, PATHINFO_EXTENSION));

        $name = str($applicant->number.' '.$applicant->fullName())
            ->trim()->replaceMatches('/[^A-Za-z0-9 \-]/', '')->squish()->replace(' ', '-')->lower();

        return $disk->download(
            (string) $applicant->cv_path,
            ($name->isEmpty() ? 'cv' : (string) $name)
                .'.'.(in_array($extension, ApplicantService::ALLOWED_EXTENSIONS, true) ? $extension : 'bin'),
        );
    }

    // ------------------------------------------------------------------ stages

    public function addStage(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('recordStage', $applicant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scheduled_at' => ['nullable', 'date'],
            'interviewer_id' => ['nullable', 'integer'],
        ]);

        // The interviewer must be somebody this actor can actually see, not any id in the tenant.
        if (($interviewerId = $data['interviewer_id'] ?? null) !== null
            && ! $this->compiler->apply(User::query()->active(), $request->user(), 'employee.view')->whereKey($interviewerId)->exists()) {
            abort(403);
        }

        try {
            $this->applicants->addStage($applicant, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()]);
        }

        return back()->with('status', 'The stage has been added.');
    }

    public function recordStage(Request $request, ApplicantStage $stage): RedirectResponse
    {
        // Authorized against the APPLICANT, because a stage is nothing on its own and the parent is
        // where confidentiality and record access live. A stage id from another company does not
        // survive the tenant scope, and one from another applicant is answered by that applicant's
        // own policy (§94).
        $this->authorize('recordStage', $stage->applicant);

        $data = $request->validate([
            'outcome' => ['required', 'string', 'max:20'],
            'score' => ['nullable', 'integer', 'min:0', 'max:5'],
            'comments' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->applicants->recordStage(
                $stage,
                $data['outcome'],
                $data['score'] ?? null,
                $data['comments'] ?? null,
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['outcome' => $e->getMessage()]);
        }

        return back()->with('status', 'The stage has been recorded.');
    }

    // ------------------------------------------------------------------ decisions

    public function offer(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('decide', $applicant);

        try {
            $this->applicants->makeOffer($applicant, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The offer has been recorded.');
    }

    public function reject(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('decide', $applicant);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->applicants->reject($applicant, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The applicant has been marked as not taken forward.');
    }

    public function withdraw(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('decide', $applicant);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        try {
            $this->applicants->withdraw($applicant, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The applicant has been marked as withdrawn.');
    }

    public function hire(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('hire', $applicant);

        $data = $request->validate([
            'employee_no' => ['required', 'string', 'max:40'],
            'hired_on' => ['required', 'date'],
            'probation_ends_on' => ['nullable', 'date'],
            'position_id' => ['nullable', 'integer'],
            'org_unit_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'supervisor_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'string', 'max:40'],
        ]);

        $this->assertPlacementIsReachable($data);
        $this->assertHirePlacement($data, $request->user());

        try {
            $employee = $this->applicants->hire($applicant, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['employee_no' => $e->getMessage()]);
        }

        return redirect()->route('employees.show', $employee)
            ->with('status', $employee->fullName().' has been added to the personnel register as '.$employee->employee_no.'.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The three ids on the hiring form that RegisterController's placement check does not cover.
     *
     * A position, a location and a supervisor all resolve through models carrying the organisation
     * global scope, so an id belonging to another company does not resolve at all and the request
     * dies here rather than being written onto a personnel record (§75, §84).
     *
     * @param  array<string,mixed>  $data
     */
    private function assertHirePlacement(array $data, User $user): void
    {
        if (($positionId = $data['position_id'] ?? null) !== null) {
            abort_unless(Position::query()->whereKey($positionId)->exists(), 404);
        }

        if (($locationId = $data['location_id'] ?? null) !== null) {
            abort_unless(Location::query()->whereKey($locationId)->exists(), 404);
        }

        if (($supervisorId = $data['supervisor_id'] ?? null) !== null) {
            // A supervisor must also be somebody the person hiring can actually see, not merely a
            // row in the same company (§61).
            abort_unless(
                $this->compiler
                    ->apply(Employee::query(), $user, 'employee.view')
                    ->whereKey($supervisorId)->exists(),
                403,
            );
        }
    }

    /** @return array<int|string,string> */
    private function vacancyOptions(User $user): array
    {
        return $this->compiler
            ->apply(Vacancy::query(), $user, 'recruitment.view')
            ->orderByDesc('id')->limit(300)->get()
            ->mapWithKeys(fn (Vacancy $v) => [$v->id => $v->displayName()])
            ->all();
    }

    /** @return array<string,string> */
    private function sourceOptions(): array
    {
        return $this->lists->for('applicant.sources', [
            'referral', 'website', 'agency', 'job board', 'walk-in', 'internal',
        ]);
    }

    /** @return array<string,mixed> */
    private function hireOptions(User $user): array
    {
        return [
            'positions' => $user->hasPermission('position.view')
                ? $this->compiler
                    ->apply(Position::query()->where('is_active', true), $user, 'position.view')
                    ->orderBy('title')->limit(500)->pluck('title', 'id')->all()
                : [],
            'units' => $this->unitOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'supervisors' => $this->compiler
                ->apply(Employee::query()->active(), $user, 'employee.view')
                ->orderBy('last_name')->limit(500)->get()
                ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])
                ->all(),
        ];
    }
}
