<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\RiskAssessmentHazardsRequest;
use App\Http\Requests\RiskAssessmentRequest;
use App\Models\RiskAssessment;
use App\Services\RiskAssessmentService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The hazards, the controls, and whether the residual risk is acceptable.
 *
 * `valid_to` IS THE COLUMN THIS REGISTER EXISTS FOR. A risk assessment nobody has looked at since
 * 2019 is not a control, it is a document, and the difference only shows up when somebody is hurt
 * doing the thing it describes. Every default view on this screen is built around expiry.
 *
 * THE HAZARD ROWS ARE POSTED WHOLE, THROUGH THEIR OWN ENDPOINT. Editing the assessment's own fields
 * and editing its hazard table are separate acts with separate forms, because a hazard table is a
 * repeating grid that nobody wants merged into a page of dates and references. The residual and
 * initial SCORES are not accepted from the form at all — the service multiplies likelihood by
 * severity itself, so a posted score that disagrees with its own factors cannot exist (§84).
 *
 * NOBODY APPROVES THEIR OWN, and an assessment with no hazards on it cannot be approved at all.
 * Both refusals live in the service as well as the policy: an empty risk assessment carrying an
 * approval signature is a signature on a blank page, and it is a common thing to find in a register
 * nobody checked.
 */
class RiskAssessmentController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly RiskAssessmentService $assessments,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'hse_risk';
    }

    protected function modelClass(): string
    {
        return RiskAssessment::class;
    }

    protected function routePrefix(): string
    {
        return 'risk-assessments';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', RiskAssessment::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'discipline:id,name',
            'assessor:id,name', 'approver:id,name',

            // Scores only, and only these columns. The register prints the worst residual on every
            // row, and asking each record for it one at a time is thirty queries a page.
            'hazards:id,risk_assessment_id,residual_score',
        ])->withCount('hazards');

        $this->applyCommonFilters($query, $request);

        if ($type = (string) $request->query('assessment_type', '')) {
            $query->where('assessment_type', $type);
        }

        $view = (string) $request->query('view', 'current');

        // A DISPLAY HORIZON, NOT A RULE. Nothing is enforced against it and no record changes
        // because of it — it only decides how far ahead the "expiring" tab looks, which is why it
        // is a number here rather than another setting for an administrator to maintain. What
        // matters, `valid_to`, is on the record.
        $warningDays = 30;

        match ($view) {
            'expiring' => $query->expiringWithin($warningDays),
            'expired' => $query->expired(),
            'draft' => $query->whereNull('approved_at'),
            'mine' => $query->where(fn ($q) => $q->where('assessed_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->whereNotNull('approved_at'),
        };

        /** @var LengthAwarePaginator $assessments */
        $assessments = $query
            ->orderByRaw('CASE WHEN valid_to IS NULL THEN 1 ELSE 0 END')
            ->orderBy('valid_to')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('risk-assessments.index', [
            'assessments' => $assessments,
            'statuses' => $this->statuses->optionsFor('hse_risk'),
            'projects' => $this->projectOptions($user),
            'types' => $this->lists->for('risk_assessment.types', RiskAssessment::DEFAULT_TYPES),
            'views' => [
                'current' => 'Approved and current',
                'expiring' => 'Expiring within '.$warningDays.' days',
                'expired' => 'Lapsed',
                'draft' => 'Not yet approved',
                'mine' => 'Written by me',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request, 'current'),
                'assessment_type' => $request->query('assessment_type'),
            ],
            'canCreate' => $user->can('create', RiskAssessment::class),
        ]);
    }

    public function show(Request $request, RiskAssessment $riskAssessment): View
    {
        $this->authorize('view', $riskAssessment);

        $user = $request->user();

        $riskAssessment->loadMissing([
            'status', 'project', 'discipline', 'orgUnit',
            'assessor:id,name', 'reviewer:id,name', 'approver:id,name', 'creator:id,name',
            'hazards.responsible:id,name',
        ]);

        return view('risk-assessments.show', [
            'assessment' => $riskAssessment,
            'timeline' => $this->activity->timelineFor($riskAssessment)->limit(25)->get(),
            'transitions' => $this->statuses->available('hse_risk', $riskAssessment->status_id, $user),
            'workflowInstance' => $riskAssessment->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $riskAssessment),
            'canDelete' => $user->can('delete', $riskAssessment),
            'canReview' => $user->can('review', $riskAssessment),
            'canApprove' => $user->can('approve', $riskAssessment),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', RiskAssessment::class);

        $user = $request->user();

        // How long an assessment stays current is a house rule, not a constant. A company that
        // reviews annually and one that reviews every two years both configure it (§73).
        $months = max(1, $this->settings->int('risk_assessment.default_validity_months', 12));

        return view('risk-assessments.create', [
            'assessment' => new RiskAssessment([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'assessed_by' => $user->id,
                'assessed_on' => now()->toDateString(),
                'valid_from' => now()->toDateString(),
                'valid_to' => now()->addMonths($months)->toDateString(),
                'review_frequency_months' => $months,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(RiskAssessmentRequest $request): RedirectResponse
    {
        $this->authorize('create', RiskAssessment::class);

        $data = $request->assessmentAttributes();

        $this->assertPlacementIsReachable($data);

        $assessment = $this->assessments->create($data, $request->user());

        return redirect(route('risk-assessments.hazards.edit', $assessment))
            ->with('status', $assessment->displayName().' has been created — now record the hazards.');
    }

    public function edit(Request $request, RiskAssessment $riskAssessment): View
    {
        $this->authorize('update', $riskAssessment);

        return view('risk-assessments.edit', [
            'assessment' => $riskAssessment,
            ...$this->formOptions($request),
        ]);
    }

    public function update(RiskAssessmentRequest $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        $this->authorize('update', $riskAssessment);

        $data = $request->assessmentAttributes();

        $this->assertPlacementIsReachable($data);

        $this->assessments->update($riskAssessment, $data, $request->user());

        return redirect($this->showRoute($riskAssessment))
            ->with('status', $riskAssessment->displayName().' has been updated.');
    }

    public function destroy(Request $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        $this->authorize('delete', $riskAssessment);

        $name = $riskAssessment->displayName();

        $this->recordDeletion($riskAssessment, $request->user(), 'risk assessment');

        $riskAssessment->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ hazards

    public function editHazards(Request $request, RiskAssessment $riskAssessment): View
    {
        $this->authorize('update', $riskAssessment);

        $riskAssessment->loadMissing('hazards.responsible:id,name');

        return view('risk-assessments.hazards', [
            'assessment' => $riskAssessment,
            'people' => $this->userOptions($request->user()),
        ]);
    }

    /**
     * Replace the hazard table wholesale.
     *
     * `setHazards()` deletes and rewrites rather than diffing, and the authorization for it is the
     * assessment's own `update` — there is no separate hazard permission and there should not be,
     * because a hazard row has no meaning apart from the assessment it sits in. The scores are
     * computed from the posted factors; nothing about them is trusted from the form (§84).
     */
    public function updateHazards(
        RiskAssessmentHazardsRequest $request,
        RiskAssessment $riskAssessment,
    ): RedirectResponse {
        $this->authorize('update', $riskAssessment);

        $this->assessments->setHazards($riskAssessment, $request->hazardRows(), $request->user());

        return redirect($this->showRoute($riskAssessment))
            ->with('status', 'The hazards on '.$riskAssessment->displayName().' have been recorded.');
    }

    // ------------------------------------------------------------------ lifecycle

    public function review(Request $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        $this->authorize('review', $riskAssessment);

        try {
            $this->assessments->review($riskAssessment, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['review' => $e->getMessage()]);
        }

        return back()->with('status', $riskAssessment->displayName().' has been reviewed.');
    }

    public function approve(Request $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        $this->authorize('approve', $riskAssessment);

        try {
            $this->assessments->approve($riskAssessment, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['approve' => $e->getMessage()]);
        }

        return back()->with('status', $riskAssessment->displayName().' has been approved.');
    }

    public function transition(Request $request, RiskAssessment $riskAssessment): RedirectResponse
    {
        return $this->moveStatus($request, $riskAssessment);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'types' => $this->lists->for('risk_assessment.types', RiskAssessment::DEFAULT_TYPES),
            'nextNumber' => $this->numbers->preview('hse_risk'),
        ];
    }
}
