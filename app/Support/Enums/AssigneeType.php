<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * How a workflow stage finds its people (§19, docs/07 §3).
 *
 * THE RULE THIS ENUM EXISTS TO ENFORCE: a stage names a RELATIONSHIP, not a person. "The head of
 * the department that owns this record", "the manager of this record's project", "whoever holds the
 * QA Manager role" — never "Sarah". When Sarah leaves, nothing breaks and nobody has to remember to
 * edit fourteen workflows; the next document routes to whoever now holds the position.
 *
 * `User` is in the list because sometimes a company genuinely does mean one named person — a sole
 * signatory, a statutory role held by an individual. It is the exception, it is visible as such on
 * the builder screen, and it is the only case where a departure breaks a route.
 *
 * Each case documents what `workflow_stages.assignee_ref` holds for it. Types that derive everything
 * from the record itself need no reference at all, which is why needsReference() exists: the builder
 * hides the field rather than showing an input nobody can fill in meaningfully.
 */
enum AssigneeType: string
{
    /** ref = user id. One named person. */
    case User = 'user';

    /** ref = role id. Everyone holding that role, narrowed by the record's unit where sensible. */
    case Role = 'role';

    /** ref = org_unit id. Everyone in that unit. */
    case OrgUnit = 'org_unit';

    /** ref = null. The head of the unit that owns the record. */
    case OrgUnitHead = 'org_unit_head';

    /** ref = null. The head one level ABOVE the owning unit — the ordinary escalation step. */
    case ParentUnitHead = 'parent_unit_head';

    /** ref = null. The manager of the record's project. */
    case ProjectManager = 'project_manager';

    /** ref = project_role id. Whoever holds that role ON THIS RECORD'S PROJECT. */
    case ProjectRole = 'project_role';

    /** ref = null. The head of the department the project belongs to. */
    case DepartmentHeadOfProject = 'department_head_of_project';

    /** ref = null. The record owner's line manager, from the employee reporting line. */
    case Supervisor = 'supervisor';

    /** ref = depth (integer). N levels up the reporting chain from the record owner. */
    case ManagerChain = 'manager_chain';

    /** ref = null. Whoever owns the record — for acknowledgement and input stages. */
    case RecordOwner = 'record_owner';

    /** ref = column name. A user id carried on the record itself, e.g. `reviewed_by`. */
    case RecordField = 'record_field';

    /** ref = resolver key. Registered in WorkflowServiceProvider, never a free-text class name. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::User => 'A specific person',
            self::Role => 'Everyone with a role',
            self::OrgUnit => 'Everyone in a department',
            self::OrgUnitHead => 'Head of the owning department',
            self::ParentUnitHead => 'Head of the parent department',
            self::ProjectManager => "The project's manager",
            self::ProjectRole => 'A role on the project',
            self::DepartmentHeadOfProject => "Head of the project's department",
            self::Supervisor => "The owner's line manager",
            self::ManagerChain => 'N levels up the reporting line',
            self::RecordOwner => 'The record owner',
            self::RecordField => 'A person named on the record',
            self::Custom => 'A custom rule',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::User => 'Routes to one named account. The route must be edited when they leave.',
            self::Role => 'Resolves live, so a change of role holder reroutes future approvals.',
            self::OrgUnit => 'Everyone currently in the department, including sub-departments.',
            self::OrgUnitHead => 'Taken from the record, so one stage serves every department.',
            self::ParentUnitHead => 'One level up from the record — the usual second approval.',
            self::ProjectManager => 'Only meaningful on records attached to a project.',
            self::ProjectRole => 'For example the QA Lead on whichever project this record belongs to.',
            self::DepartmentHeadOfProject => 'Uses the department the project itself is filed under.',
            self::Supervisor => "From the employee record's reporting line.",
            self::ManagerChain => 'Enter how many levels up, e.g. 2 for the manager’s manager.',
            self::RecordOwner => 'Sends it back to the person answerable for the record.',
            self::RecordField => 'Enter the field name holding the person, e.g. reviewed_by.',
            self::Custom => 'Chosen from rules registered by an administrator.',
        };
    }

    /** Does the builder need to ask for an `assignee_ref`? */
    public function needsReference(): bool
    {
        return match ($this) {
            self::OrgUnitHead,
            self::ParentUnitHead,
            self::ProjectManager,
            self::DepartmentHeadOfProject,
            self::Supervisor,
            self::RecordOwner => false,
            default => true,
        };
    }

    /**
     * Types that cannot resolve without a project on the record.
     *
     * Used by the builder to warn, and by the resolver to explain a stall in words rather than
     * leaving somebody staring at "no assignees found".
     */
    public function requiresProject(): bool
    {
        return in_array($this, [self::ProjectManager, self::ProjectRole, self::DepartmentHeadOfProject], true);
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
