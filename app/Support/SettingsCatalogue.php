<?php

declare(strict_types=1);

namespace App\Support;

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
