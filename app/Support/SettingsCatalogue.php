<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Client;
use App\Models\Contract;
use App\Models\CorrectiveAction;
use App\Models\Deliverable;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\HseInspection;
use App\Models\HseInspectionItem;
use App\Models\HseObservation;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\Income;
use App\Models\Investigation;
use App\Models\MaterialRequest;
use App\Models\Ncr;
use App\Models\Payment;
use App\Models\PurchaseRequest;
use App\Models\QualityInspection;
use App\Models\QualityInspectionItem;
use App\Models\Quotation;
use App\Models\RiskAssessment;
use App\Models\StockItem;
use App\Models\Submittal;
use App\Models\TrainingCourse;
use App\Models\TrainingRecord;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\Vendor;
use App\Models\Visitor;
use App\Models\Warehouse;

/**
 * How each configurable setting is presented and validated (§72, §86).
 *
 * The VALUES live in the database, per organization — that is what makes the platform
 * configurable. What lives here is only the shape of each one: its label, its help text, the
 * control that edits it and the rule that guards it. That distinction matters. A company can
 * change how it works without a deployment; it cannot invent a setting no code reads, because a
 * key nobody consumes is a switch that silently does nothing.
 *
 * Every default in OrganizationProvisioner::seedSettings() should appear here, or an administrator
 * will have a value they cannot see and cannot change.
 */
final class SettingsCatalogue
{
    /**
     * group key => [label, description, settings]
     *
     * Each setting: key => [label, type, help, rules, options]
     *
     * @return array<string,array{label:string,description:string,settings:array<string,array<string,mixed>>}>
     */
    public static function groups(): array
    {
        $days = [
            'monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday',
            'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday',
            'sunday' => 'Sunday',
        ];

        return [
            'org' => [
                'label' => 'Company naming',
                'description' => 'What this company calls itself on a document, and what it calls each level of its organization. These labels appear throughout the system, so a company with Directorates never sees the word Division.',
                'settings' => [
                    // Seeded blank, and it has to be reachable from a screen or it stays blank
                    // forever. A numbering scheme whose pattern leads with {ORG} silently drops the
                    // token when this is empty, so "MER/ENG/26/007" comes out as "ENG/26/007" —
                    // correct, unique, and not the reference the company prints on its drawings.
                    // §73: the walkthrough has to be completable without a developer, and this is
                    // the one value in the numbering chain that nothing else can supply.
                    'org.short_code' => [
                        'label' => 'Short code',
                        'type' => 'string',
                        'help' => 'Two to six letters standing for the company on a document reference, e.g. MER for Meridian Engineering. Leave it blank if your references do not begin with the company.',
                        'rules' => ['nullable', 'string', 'max:6', 'regex:/^[A-Za-z0-9]*$/'],
                    ],
                    'org.label.division' => ['label' => 'Level 1 is called', 'type' => 'string', 'rules' => ['required', 'string', 'max:40']],
                    'org.label.department' => ['label' => 'Level 2 is called', 'type' => 'string', 'rules' => ['required', 'string', 'max:40']],
                    'org.label.section' => ['label' => 'Level 3 is called', 'type' => 'string', 'rules' => ['required', 'string', 'max:40']],
                    'org.label.team' => ['label' => 'Level 4 is called', 'type' => 'string', 'rules' => ['required', 'string', 'max:40']],
                ],
            ],

            'work' => [
                'label' => 'Working pattern',
                'description' => 'The company’s normal week. Attendance, timesheets, due dates and escalation timers all count working days from here rather than from a hard-coded calendar.',
                'settings' => [
                    'work.week_starts_on' => ['label' => 'The week starts on', 'type' => 'select', 'options' => $days],
                    'work.working_days' => ['label' => 'Working days', 'type' => 'multiselect', 'options' => $days, 'help' => 'Anything not ticked is a non-working day and is skipped when counting deadlines.'],
                    'work.day_start' => ['label' => 'Working day starts', 'type' => 'time'],
                    'work.day_end' => ['label' => 'Working day ends', 'type' => 'time'],
                    'work.hours_per_day' => ['label' => 'Standard hours per day', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,24']],
                    'attendance.capture_location' => ['label' => 'Record where people clock in from', 'type' => 'boolean', 'help' => 'Asks the phone for its position when somebody clocks in or out. Off by default: a location is personal data, and a refused request never stops the clock-in.'],
                    'timesheet.max_hours_per_day' => ['label' => 'Most hours one person may book in a day', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,24']],
                ],
            ],

            /*
             * Approvals.
             *
             * THESE ARE THE KEYS THE ESCALATION ENGINE ACTUALLY READS, and saying so is the whole
             * point of the group. It previously offered five settings — a reminder delay, an
             * escalation delay, a delegation switch, a "rejections must state a reason" switch and
             * an auto-approve-on-timeout switch — of which exactly none were read by any code. A
             * screen that offers a company a lever connected to nothing is worse than a screen that
             * offers nothing: the company sets it, believes it, and finds out otherwise when an
             * approval they thought was chased for a fortnight turns out never to have been.
             *
             * Three of the five are gone rather than wired, and each for its own reason:
             *
             *   · `reminder_after_days` and `escalate_after_days` were two names for one ladder.
             *     EscalationService already reminds the approvers (level 1), then their supervisors
             *     and the person being held up (level 2), then anybody who can reassign (level 3).
             *     What it needed was not two more delays but the two knobs below, which are the
             *     ones it reads.
             *
             *   · `require_rejection_reason` described something the platform does unconditionally
             *     and would not stop doing: ApprovalDecisionRequest requires a comment for every
             *     decision that is not an approval. Offering to switch that off would be offering
             *     something the validator refuses.
             *
             *   · `auto_approve_on_timeout` is deleted rather than implemented, and it is the one
             *     worth being explicit about. Its own help text called it strongly discouraged. To
             *     honour it, something would have to write an approval record naming a person who
             *     did not approve — a false entry in exactly the history §59 exists to protect, and
             *     one indistinguishable afterwards from a real decision. Level 3 of the ladder is
             *     the honest answer to "nobody acted": tell somebody who can unstick it.
             */
            'workflow' => [
                'label' => 'Approvals and workflow',
                'description' => 'How approvals behave when nobody acts. These are company policy, not code.',
                'settings' => [
                    'workflow.escalation_step_hours' => [
                        'label' => 'Hours between each chase',
                        'type' => 'integer',
                        'help' => 'The first chase goes to the approvers as soon as a stage passes its time limit. Each step after that waits this long: their supervisors next, then anybody who can reassign the approval.',
                        'rules' => ['required', 'integer', 'between:1,720'],
                    ],
                    'workflow.escalation_max_level' => [
                        'label' => 'Chase as far as',
                        'type' => 'select',
                        'options' => [
                            1 => 'The approvers themselves',
                            2 => 'Their supervisors, and whoever is being held up',
                            3 => 'Anybody who can reassign the approval',
                        ],
                        'help' => 'A stage with no time limit is never chased, whatever this says — the limit is set on the stage, in the workflow builder.',
                    ],
                    'workflow.allow_delegation' => [
                        'label' => 'Approvers may delegate while away',
                        'type' => 'boolean',
                        'help' => 'Turning this off stops delegation everywhere, even on routes whose stages allow it. Individual stages can still forbid it while this is on.',
                    ],
                ],
            ],

            'document' => [
                'label' => 'Documents',
                'description' => 'Numbering, revisions and upload limits for controlled documents.',
                'settings' => [
                    'document.numbering_format' => ['label' => 'Document number format', 'type' => 'string', 'help' => 'Tokens: {PROJECT} {DISCIPLINE} {TYPE} {YEAR} {SEQ:n}', 'rules' => ['required', 'string', 'max:120']],
                    'document.revision_scheme' => ['label' => 'Revision scheme', 'type' => 'select', 'options' => ['alpha' => 'Letters (A, B, C)', 'numeric' => 'Numbers (1, 2, 3)']],
                    'document.require_approval_before_issue' => ['label' => 'Documents must be approved before they can be issued', 'type' => 'boolean'],
                    'document.retain_superseded' => ['label' => 'Keep superseded revisions', 'type' => 'boolean', 'help' => 'Turning this off destroys history. Most engineering organizations are contractually required to leave it on.'],
                    'document.max_upload_mb' => ['label' => 'Largest upload allowed (MB)', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,512'], 'help' => 'The server’s own upload limit still applies and may be lower.'],
                ],
            ],

            /*
             * The vocabulary of the engineering registers.
             *
             * Every one of these is a list of short strings that differs between companies and
             * between contracts on the same company's books, and none of them is a business rule —
             * which is exactly the test for "configuration, not code". They are stored as
             * comma-separated text and read through OptionList, which slugs the labels so that
             * editing a label never orphans the records that used it.
             *
             * The response targets below are DEFAULTS for a new record's due date, never a cap: a
             * user may always type a different date, because the contract governs, not the setting.
             */
            'engineering' => [
                'label' => 'Engineering registers',
                'description' => 'The vocabulary your deliverables, drawings, RFIs and submittals use. Separate each entry with a comma.',
                'settings' => [
                    'engineering.deliverable_types' => ['label' => 'Deliverable types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Deliverable::DEFAULT_TYPES)],
                    'engineering.drawing_purposes' => ['label' => 'Drawing issue purposes', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'The title-block state of a sheet. Leave blank to use: '.implode(', ', Drawing::DEFAULT_PURPOSES)],
                    'engineering.submittal_types' => ['label' => 'Submittal types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Submittal::DEFAULT_TYPES)],
                    'engineering.submittal_review_codes' => ['label' => 'Submittal review codes', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'What a reviewer stamps on a package. Leave blank to use: '.implode(', ', Submittal::DEFAULT_REVIEW_CODES)],
                    'engineering.rfi_response_days' => ['label' => 'Default RFI response time', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,90'], 'help' => 'Working days, used to suggest a required-by date. Anyone raising an RFI can still choose their own.'],
                    'engineering.submittal_review_days' => ['label' => 'Default submittal review time', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,90'], 'help' => 'Days, used to suggest a required-by date.'],
                ],
            ],

            /*
             * The vocabulary of the HSE registers.
             *
             * Same principle as the engineering lists above, and more load-bearing here. An
             * incident category, a severity scale and an involvement type are the words a company
             * already uses on paper; a platform that imposed its own would be argued with on the
             * first day and worked around by the second. None of them is a business rule — the
             * rules live in the policies and the services — so all of them are configuration (§73).
             *
             * ONE CAVEAT, WRITTEN HERE BECAUSE THIS IS WHERE SOMEBODY WILL EDIT IT: the values a
             * workflow condition tests are the SLUGS of these labels. Renaming "Major" to "Serious"
             * changes `major` to `serious`, and a routing rule written against the old slug stops
             * matching. Adding and removing entries is safe; renaming one is a change to
             * configuration that other configuration depends on.
             */
            'hse' => [
                'label' => 'HSE registers',
                'description' => 'The vocabulary your incidents, observations, risk assessments, inspections and training records use. Separate each entry with a comma.',
                'settings' => [
                    'hse_incident.categories' => ['label' => 'Incident categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Incident::DEFAULT_CATEGORIES)],
                    'hse_incident.severities' => ['label' => 'Severity scale', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Used for BOTH what happened and what could have happened, so that the two can be compared. Leave blank to use: '.implode(', ', Incident::DEFAULT_SEVERITIES)],
                    'hse_incident.involvements' => ['label' => 'How a person was involved', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', IncidentPerson::DEFAULT_INVOLVEMENTS)],
                    'hse_incident.treatments' => ['label' => 'Treatment given', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'The classification a lost-time figure is argued from. Leave blank to use: '.implode(', ', IncidentPerson::DEFAULT_TREATMENTS)],
                    'investigation.methods' => ['label' => 'Investigation methods', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Investigation::DEFAULT_METHODS)],
                    'corrective_action.types' => ['label' => 'Corrective action types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', CorrectiveAction::DEFAULT_TYPES)],
                    'corrective_action.priorities' => ['label' => 'Corrective action priorities', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', CorrectiveAction::DEFAULT_PRIORITIES)],
                    'corrective_action.effectiveness' => ['label' => 'Effectiveness ratings', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Recorded when an action is verified — the answer to "did it work". Leave blank to use: '.implode(', ', CorrectiveAction::DEFAULT_EFFECTIVENESS)],
                    'hse_observation.types' => ['label' => 'Observation types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Keep the positive entries. A register that only records failure teaches people that reporting is punishment. Leave blank to use: '.implode(', ', HseObservation::DEFAULT_TYPES)],
                    'hse_observation.categories' => ['label' => 'Observation categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', HseObservation::DEFAULT_CATEGORIES)],
                    'hse_observation.risk_levels' => ['label' => 'Observation risk levels', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', HseObservation::DEFAULT_RISK_LEVELS)],
                    'risk_assessment.types' => ['label' => 'Risk assessment types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', RiskAssessment::DEFAULT_TYPES)],
                    'risk_assessment.default_validity_months' => ['label' => 'Risk assessments stay valid for', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,60'], 'help' => 'Months. Used to suggest a review date; whoever writes the assessment can still choose their own.'],
                    'hse_inspection.types' => ['label' => 'HSE inspection types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', HseInspection::DEFAULT_TYPES)],
                    'hse_inspection.results' => ['label' => 'HSE inspection item results', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Keep an entry meaning "not applicable", or items that do not apply will count against the score. Leave blank to use: '.implode(', ', HseInspectionItem::DEFAULT_RESULTS)],
                    'training_course.categories' => ['label' => 'Training categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', TrainingCourse::DEFAULT_CATEGORIES)],
                    'hse_training.results' => ['label' => 'Training outcomes', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', TrainingRecord::DEFAULT_RESULTS)],
                    'hse_training.expiry_warning_days' => ['label' => 'Warn about expiring certificates', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:7,365'], 'help' => 'Days ahead. This is the window the training register opens on.'],
                    'permit.expiry_warning_hours' => ['label' => 'Warn about expiring permits', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,168'], 'help' => 'Hours ahead. Permits are measured in hours because they are worked to in hours.'],
                ],
            ],

            /*
             * The vocabulary of the quality registers.
             *
             * Kept separate from HSE rather than merged into one "operations" group, because the
             * people who edit them are different people. A quality manager who has to scroll past
             * eighteen safety settings to rename a disposition will eventually ask a developer to
             * do it instead, which is the failure mode this whole layer exists to prevent.
             */
            'quality' => [
                'label' => 'Quality registers',
                'description' => 'The vocabulary your inspections and non-conformance reports use. Separate each entry with a comma.',
                'settings' => [
                    'quality_inspection.types' => ['label' => 'Inspection types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Hold points and witness points are not the same thing — work may continue past a witness point and may not past a hold point. Leave blank to use: '.implode(', ', QualityInspection::DEFAULT_TYPES)],
                    'quality_inspection.results' => ['label' => 'Inspection results', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'A result that no longer matches a configured status leaves the record where it is rather than moving it somewhere nobody asked for. Leave blank to use: '.implode(', ', QualityInspection::DEFAULT_RESULTS)],
                    'quality_inspection.item_results' => ['label' => 'Inspection item results', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', QualityInspectionItem::DEFAULT_RESULTS)],
                    'quality_inspection.notice_days' => ['label' => 'Notice required for an inspection', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:0,30'], 'help' => 'Days, used to suggest a required-by date when one is requested.'],
                    'ncr.severities' => ['label' => 'NCR severities', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Ncr::DEFAULT_SEVERITIES)],
                    'ncr.categories' => ['label' => 'NCR categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Ncr::DEFAULT_CATEGORIES)],
                    'ncr.sources' => ['label' => 'How the non-conformance was found', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'What a supplier scorecard is built from. Leave blank to use: '.implode(', ', Ncr::DEFAULT_SOURCES)],
                    'ncr.dispositions' => ['label' => 'NCR dispositions', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Repair, use-as-is and regrade always demand a written justification, whatever they are called here. Leave blank to use: '.implode(', ', Ncr::DEFAULT_DISPOSITIONS)],
                    'ncr.response_days' => ['label' => 'Default NCR response time', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,90'], 'help' => 'Days, used to suggest a required-by date.'],
                ],
            ],

            /*
             * The commercial vocabulary.
             *
             * Its own group for the reason the quality group is its own group: the person who
             * renames a vendor category is in procurement and will not go looking for it behind
             * eighteen safety settings.
             *
             * `vendor.prequalification_warning_days` is the one number here that DOES something
             * rather than merely naming something — it is the window the vendor register and the
             * expiry watch open on, and the reason it is configurable is that a company reassessing
             * annually and a company reassessing every three years want different warnings.
             */
            'commercial' => [
                'label' => 'Commercial registers',
                'description' => 'The vocabulary your vendor, client and contract registers use. Separate each entry with a comma.',
                'settings' => [
                    'vendor.types' => ['label' => 'Vendor types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'The kind of relationship, not what they sell. Leave blank to use: '.implode(', ', Vendor::DEFAULT_TYPES)],
                    'vendor.categories' => ['label' => 'Vendor categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:900'], 'help' => 'What they supply, in your words. Leave blank to use: '.implode(', ', Vendor::DEFAULT_CATEGORIES)],
                    'vendor.prequalification_warning_days' => ['label' => 'Warn about expiring prequalifications', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:7,365'], 'help' => 'Days ahead. This is the window the vendor register opens on — a prequalification that lapses unnoticed stops a purchase order on the morning it is needed.'],
                    'client.types' => ['label' => 'Client types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Client::DEFAULT_TYPES)],
                    'client.industries' => ['label' => 'Client industries', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:900'], 'help' => 'Leave blank to use: '.implode(', ', Client::DEFAULT_INDUSTRIES)],
                    'contract.types' => ['label' => 'Contract types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'How the money is measured, which is the only classification that matters commercially. Leave blank to use: '.implode(', ', Contract::DEFAULT_TYPES)],
                    'contract.default_retention_percent' => ['label' => 'Usual retention', 'type' => 'string', 'rules' => ['nullable', 'numeric', 'between:0,50'], 'help' => 'Percent, used only to suggest a figure on a new contract. The contract governs, not this setting.'],
                    'contract.expiry_warning_days' => ['label' => 'Warn about expiring contracts', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:7,365'], 'help' => 'Days ahead. Renewal negotiations start long before the end date, so this is usually set wider than it feels.'],
                ],
            ],
            /*
             * Procurement.
             *
             * Three of these settings are POLICY, not vocabulary, which makes this group different
             * in kind from the ones above it. `rfq_required_above` decides whether a purchase order
             * may be raised without competition; the two `separate_*` flags decide whether one
             * person may authorise their own request or sign for their own order. They are settings
             * rather than hard rules because a four-person facilities company physically cannot
             * separate the duties a hundred-person contractor must — and refusing to work for the
             * small company is not an improvement in its controls.
             *
             * WHAT IS DELIBERATELY NOT HERE IS A WAY TO TURN THE SEPARATION OFF QUIETLY. Both flags
             * default to on, the override is recorded in the activity trail wherever it is used,
             * and only somebody with `setting.manage` can move them.
             */
            'procurement' => [
                'label' => 'Procurement',
                'description' => 'How buying works here: when competition is required, who may approve what, and the words your requests and receipts use. Separate each list entry with a comma.',
                'settings' => [
                    'procurement.rfq_required_above' => ['label' => 'Require competitive quotations above', 'type' => 'string', 'rules' => ['nullable', 'numeric', 'min:0'], 'help' => 'In your base currency. A purchase order above this figure warns unless it came from an awarded enquiry. Leave blank to never require competition.'],
                    'procurement.minimum_quotations' => ['label' => 'Quotations expected on an enquiry', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,10'], 'help' => 'How many responses an enquiry should have before it is awarded. Awarding with fewer is allowed and asks for a reason.'],
                    'procurement.separate_requester_and_approver' => ['label' => 'A request may not be approved by the person who raised it', 'type' => 'boolean'],
                    'procurement.separate_buyer_and_receiver' => ['label' => 'Goods may not be received by the person who raised the order', 'type' => 'boolean'],
                    // The criteria are a LIST OF WORDS, not columns, so a company can score on three
                    // things or seven without a migration. QuotationScore stores the label it was
                    // shown alongside the key, so renaming one next year does not retitle a decision
                    // taken this year.
                    'procurement.evaluation_criteria' => ['label' => 'Quotations are scored on', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'One criterion per comma. Leave blank to use: '.implode(', ', Quotation::DEFAULT_CRITERIA)],
                    'procurement.score_out_of' => ['label' => 'Each criterion is scored out of', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,100'], 'help' => 'Ten is the usual scale. Marks already given keep the scale they were given on, so changing this does not restate a completed evaluation.'],
                    'purchase_request.priorities' => ['label' => 'Purchase request priorities', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:400'], 'help' => 'Leave blank to use: '.implode(', ', PurchaseRequest::DEFAULT_PRIORITIES)],
                    'purchase_order.payment_terms' => ['label' => 'Usual payment terms', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:255'], 'help' => 'Suggested on a new order. The order governs, not this setting.'],
                    'goods_receipt.conditions' => ['label' => 'Condition on arrival', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:400'], 'help' => 'What a storekeeper records about how the goods looked. Leave blank to use: '.implode(', ', GoodsReceipt::DEFAULT_CONDITIONS)],
                ],
            ],

            /*
             * How the store works here.
             *
             * THE FIRST TWO SETTINGS ARE THE SAME ARGUMENT THE PROCUREMENT GROUP MAKES, applied to
             * a different pair of hands. A storekeeper who can both approve a material request and
             * issue against it is a storekeeper who can walk out with the stock and leave a tidy
             * paper trail saying it was authorised. In a company of eight that is unavoidable and
             * the switch goes off knowingly; in a company of eight hundred it is the control.
             *
             * `allow_negative_stock` IS PER WAREHOUSE, NOT HERE, and that is worth saying in the
             * place an administrator will look for it. A transit store legitimately shows goods
             * leaving before the paperwork that put them there has been entered; a main store never
             * does. One switch for both would have to be set to the looser of the two.
             */
            'inventory' => [
                'label' => 'Inventory',
                'description' => 'How your stores work: who may approve and issue, what happens when a count runs low, and the words your catalogue uses. Separate each list entry with a comma.',
                'settings' => [
                    'inventory.separate_approver_and_issuer' => ['label' => 'Stock may not be issued by the person who approved the request', 'type' => 'boolean'],
                    'inventory.separate_counter_and_adjuster' => ['label' => 'A stocktake variance may not be posted by the person who counted', 'type' => 'boolean'],
                    'inventory.reserve_on_approval' => ['label' => 'Approving a material request reserves the stock', 'type' => 'boolean', 'help' => 'Reserved stock stays on the shelf but stops being offered to anybody else. Turn this off if your store approves far ahead of issuing and the reservations would never clear.'],
                    'inventory.notify_below_reorder_level' => ['label' => 'Tell the storekeeper when an item falls to its reorder level', 'type' => 'boolean'],
                    'stock_item.categories' => ['label' => 'Stock categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', StockItem::DEFAULT_CATEGORIES)],
                    'warehouse.types' => ['label' => 'Kinds of store', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:400'], 'help' => 'Leave blank to use: '.implode(', ', Warehouse::DEFAULT_TYPES)],
                    'material_request.priorities' => ['label' => 'Material request priorities', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:400'], 'help' => 'Leave blank to use: '.implode(', ', MaterialRequest::DEFAULT_PRIORITIES)],
                ],
            ],

            /*
             * The register of things, and the vehicles among them.
             *
             * `require_authoriser_on_transfer` IS THE SEGREGATION SETTING OF THIS MODULE, and it is
             * the same argument procurement and inventory each make with a different pair of hands.
             * A custodian who can hand a generator to themselves and record their own authorisation
             * has produced a complete, tidy paper trail for a machine that is now in their yard. In
             * a company of eight that is unavoidable and the switch goes off knowingly; in a company
             * of eight hundred it is the control.
             *
             * `odometer_required_on_fuel` LOOKS LIKE FUSSINESS AND IS THE OPPOSITE. A fuel log with
             * no reading cannot contribute to any consumption figure ever, so a fleet that skips it
             * is a fleet that has receipts and no idea what it is burning. The setting exists
             * because plant metered in hours genuinely has fills where nobody reads the meter, and
             * a hard requirement would teach those drivers to type a zero.
             */
            'assets' => [
                'label' => 'Assets & fleet',
                'description' => 'How equipment is registered, handed over and maintained, and what your fleet records. Separate each list entry with a comma.',
                'settings' => [
                    'asset.require_authoriser_on_transfer' => ['label' => 'A custody handover must name somebody who authorised it', 'type' => 'boolean', 'help' => 'The person who types the handover cannot also be the person who permitted it. Turn this off only where the same one or two people do everything.'],
                    'asset.maintenance_warning_days' => ['label' => 'Warn about maintenance coming due', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,365'], 'help' => 'Days ahead. This is the window the maintenance register opens on, and how far in advance the planner is told.'],
                    'asset.categories' => ['label' => 'Asset categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:900'], 'help' => 'Leave blank to use: '.implode(', ', Asset::DEFAULT_CATEGORIES)],
                    'asset_maintenance.types' => ['label' => 'Kinds of maintenance work', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'What was done — not whether it was planned, which is recorded separately on every job. Leave blank to use: '.implode(', ', AssetMaintenance::DEFAULT_TYPES)],
                    'vehicle.types' => ['label' => 'Kinds of vehicle', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Vehicle::DEFAULT_TYPES)],
                    'vehicle.fuel_types' => ['label' => 'Fuel types', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:400'], 'help' => 'Leave blank to use: '.implode(', ', Vehicle::DEFAULT_FUEL_TYPES)],
                    'vehicle_document.types' => ['label' => 'Vehicle documents you track', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:900'], 'help' => 'The papers a vehicle must carry where you operate. Nothing in the system knows what any of these mean — they are your words. Leave blank to use: '.implode(', ', VehicleDocument::DEFAULT_TYPES)],
                    'vehicle_document.expiry_warning_days' => ['label' => 'Warn about expiring vehicle documents', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:7,365'], 'help' => 'Days ahead. Renewals take weeks in most places, so this is usually set wider than it feels.'],
                    'fuel_log.odometer_required' => ['label' => 'A fuel log must carry an odometer reading', 'type' => 'boolean', 'help' => 'Without a reading the fill can never contribute to a consumption figure. Turn this off only for plant where nobody reads the meter at the pump.'],
                ],
            ],
            'finance' => [
                'label' => 'Finance',
                'description' => 'Who may approve and release money, and the words your expenses, income and payments use. Tax rates are kept under Finance → Tax codes. Separate each list entry with a comma.',
                'settings' => [
                    'finance.separate_claimant_and_approver' => ['label' => 'An expense may not be approved by the person who claimed it', 'type' => 'boolean'],
                    'finance.separate_payment_approver_and_payer' => ['label' => 'A payment may not be released by the person who approved it', 'type' => 'boolean', 'help' => 'Turn this off only where one person genuinely does both.'],
                    // Category keys double as budget-line categories: an approved "travel" expense
                    // counts against a budget line called "travel".
                    'expense.categories' => ['label' => 'Expense categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:900'], 'help' => 'These are also the categories budget lines are compared against. Leave blank to use: '.implode(', ', Expense::DEFAULT_CATEGORIES)],
                    'income.categories' => ['label' => 'Income categories', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:600'], 'help' => 'Leave blank to use: '.implode(', ', Income::DEFAULT_CATEGORIES)],
                    'payment.methods' => ['label' => 'Payment methods', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:400'], 'help' => 'Leave blank to use: '.implode(', ', Payment::DEFAULT_METHODS)],
                    'petty_cash.single_disbursement_limit' => ['label' => 'Largest single petty-cash payout', 'type' => 'string', 'rules' => ['nullable', 'numeric', 'min:0'], 'help' => 'In your base currency. Anything bigger has to go through an expense claim. Leave blank for no limit.'],
                ],
            ],
            'visitors' => [
                'label' => 'Visitors',
                'description' => 'How reception and the gate work. These are your rules — nothing in the system assumes a contractor needs an escort or that an induction lasts a year, and what you set here is what the visitor book enforces. Separate each list entry with a comma.',
                'settings' => [
                    'visitor.require_host' => ['label' => 'Every visit must name somebody it is here to see', 'type' => 'boolean', 'help' => 'The host is who the visit belongs to, and who is told when the visitor arrives. Turn this off only for a site where deliveries genuinely arrive for nobody in particular.'],
                    'visitor.contractor_requires_escort' => ['label' => 'Contractors must be escorted', 'type' => 'boolean', 'help' => 'Applies to visits whose purpose is Contractor. An escort has to be named before the check-in will go through.'],
                    'visitor.capture_id_document' => ['label' => 'Ask for an identity document at check-in', 'type' => 'boolean', 'help' => 'Off by default. A document number is personal data you then have to look after, so only turn this on where your site genuinely requires it. It is stored encrypted and is never searchable.'],
                    'visitor.induction_validity_days' => ['label' => 'A site induction stays valid for', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:0,3650'], 'help' => 'Days. 0 means an induction never lapses. Changing this does not move any induction already recorded — each visit keeps the expiry it was given.'],
                    'visitor.auto_close_enabled' => ['label' => 'Close anybody still shown on site at the end of the day', 'type' => 'boolean', 'help' => 'Badges do not get handed back. Without this the roll call still shows last Tuesday’s visitors and stops being believed. Anything closed this way is marked as such, never as a witnessed departure.'],
                    'visitor.auto_close_at' => ['label' => 'Close them at', 'type' => 'time', 'help' => 'Set this after your latest shift ends, not after office hours.'],
                    'visitor.purpose_types' => ['label' => 'Reasons for a visit', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:400'], 'help' => 'Leave blank to use: '.implode(', ', Visitor::DEFAULT_PURPOSE_TYPES)],
                ],
            ],

            'notify' => [
                'label' => 'Notifications',
                'description' => 'How and when people are told that something needs them.',
                'settings' => [
                    'notify.channels' => ['label' => 'Deliver notifications by', 'type' => 'multiselect', 'options' => ['database' => 'In the system', 'mail' => 'Email']],
                    'notify.digest_hour' => ['label' => 'Send the daily digest at', 'type' => 'hour'],
                    'notify.escalation_to_supervisor' => ['label' => 'Copy the supervisor on escalations', 'type' => 'boolean'],
                ],
            ],

            'report' => [
                'label' => 'Executive reporting',
                'description' => 'The daily summary sent to leadership.',
                'settings' => [
                    'report.daily_executive_enabled' => ['label' => 'Send the daily executive report', 'type' => 'boolean'],
                    'report.daily_executive_hour' => ['label' => 'Send it at', 'type' => 'hour'],
                ],
            ],

            'security' => [
                'label' => 'Security',
                'description' => 'Password and session policy for this company. Changing these affects everyone who signs in.',
                'settings' => [
                    'security.password_min_length' => ['label' => 'Minimum password length', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:8,128'], 'help' => 'Cannot be set below 8.', 'sensitive' => true],
                    'security.password_expiry_days' => ['label' => 'Force a password change every', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:0,3650'], 'help' => 'Days. 0 means never — forced rotation is widely counter-productive and drives people to weaker passwords.', 'sensitive' => true],
                    'security.session_idle_minutes' => ['label' => 'Sign out after inactivity', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:5,1440'], 'help' => 'Minutes.', 'sensitive' => true],
                    'security.max_login_attempts' => ['label' => 'Lock the account after', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:3,20'], 'help' => 'Failed sign-in attempts.', 'sensitive' => true],
                    'security.lockout_minutes' => ['label' => 'Keep it locked for', 'type' => 'integer', 'rules' => ['required', 'integer', 'between:1,1440'], 'help' => 'Minutes.', 'sensitive' => true],
                    'security.require_two_factor_for_sensitive' => ['label' => 'Require a second factor for sensitive actions', 'type' => 'boolean', 'sensitive' => true],
                ],
            ],
        ];
    }

    /**
     * Every editable key, flattened to its definition.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array
    {
        $out = [];

        foreach (self::groups() as $group => $meta) {
            foreach ($meta['settings'] as $key => $definition) {
                $out[$key] = $definition + ['group' => $group, 'key' => $key];
            }
        }

        return $out;
    }

    public static function has(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    /**
     * Validation rules for one submitted setting.
     *
     * A definition may state its own `rules`; otherwise the control type implies them. Either way
     * a value that is not in this list is never written — the settings form is a fixed vocabulary,
     * not an arbitrary key-value writer (§84).
     *
     * @return array<int,mixed>
     */
    public static function rulesFor(string $key): array
    {
        $definition = self::definitions()[$key] ?? null;

        if ($definition === null) {
            return ['prohibited'];
        }

        if (isset($definition['rules'])) {
            return $definition['rules'];
        }

        return match ($definition['type']) {
            'boolean' => ['boolean'],
            'integer' => ['required', 'integer'],
            'hour' => ['required', 'integer', 'between:0,23'],
            'time' => ['required', 'date_format:H:i'],
            'select' => ['required', 'string', 'in:'.implode(',', array_keys($definition['options'] ?? []))],
            'multiselect' => ['array'],
            default => ['nullable', 'string', 'max:255'],
        };
    }

    /**
     * Cast a submitted value to the type the setting stores, so `"1"` from a checkbox does not
     * become the string "1" in the database and quietly stop matching a `=== true` somewhere.
     */
    public static function cast(string $key, mixed $value): mixed
    {
        $definition = self::definitions()[$key] ?? null;

        return match ($definition['type'] ?? 'string') {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer', 'hour' => (int) $value,
            'multiselect' => array_values(array_filter((array) $value, fn ($v) => $v !== null && $v !== '')),
            default => is_string($value) ? trim($value) : $value,
        };
    }
}
