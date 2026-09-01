<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CorrectiveAction;
use App\Models\Deliverable;
use App\Models\Drawing;
use App\Models\HseInspection;
use App\Models\HseInspectionItem;
use App\Models\HseObservation;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\Investigation;
use App\Models\Ncr;
use App\Models\QualityInspection;
use App\Models\QualityInspectionItem;
use App\Models\RiskAssessment;
use App\Models\Submittal;
use App\Models\TrainingCourse;
use App\Models\TrainingRecord;

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
                'label' => 'Structure vocabulary',
                'description' => 'What this company calls each level of its organization. These labels appear throughout the system, so a company with Directorates never sees the word Division.',
                'settings' => [
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
                ],
            ],

            'workflow' => [
                'label' => 'Approvals and workflow',
                'description' => 'How approvals behave when nobody acts. These are company policy, not code.',
                'settings' => [
                    'workflow.reminder_after_days' => ['label' => 'Remind the approver after', 'type' => 'integer', 'help' => 'Working days of inaction before a reminder is sent. 0 disables reminders.', 'rules' => ['required', 'integer', 'between:0,30']],
                    'workflow.escalate_after_days' => ['label' => 'Escalate to their supervisor after', 'type' => 'integer', 'help' => 'Working days of inaction before the approval is escalated up the reporting line. 0 disables escalation.', 'rules' => ['required', 'integer', 'between:0,90']],
                    'workflow.allow_delegation' => ['label' => 'Approvers may delegate while away', 'type' => 'boolean'],
                    'workflow.require_rejection_reason' => ['label' => 'A rejection must state a reason', 'type' => 'boolean'],
                    'workflow.auto_approve_on_timeout' => [
                        'label' => 'Approve automatically if nobody acts in time',
                        'type' => 'boolean',
                        'help' => 'Strongly discouraged. An approval nobody made still carries the approver’s name in the record.',
                        'sensitive' => true,
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
