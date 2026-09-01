<?php

declare(strict_types=1);

namespace App\Support;

use App\Engines\Access\AccessResolver;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The sidebar (§96), built from permissions rather than from roles.
 *
 * Two rules make this maintainable as the application grows to sixty modules:
 *
 *   1. An item is listed only if the viewer holds at least one of its permissions. This is Gate 2
 *      and Gate 2 only — a usability affordance. It is NEVER the authorization: the route still
 *      carries `permission:` middleware and the controller still consults a policy (§6). Hiding a
 *      link that a hand-typed URL would still open is the exact mistake §84 warns about.
 *   2. A section with no visible items does not render its heading. Otherwise a field engineer
 *      sees five empty headings and concludes the system is broken.
 *
 * Items pointing at routes that do not exist yet are skipped rather than fataling, so this table
 * can describe the finished navigation while the phases land one at a time.
 */
class Navigation
{
    public function __construct(
        private readonly AccessResolver $resolver,
    ) {}

    /**
     * @return array<int,array{label:string,items:array<int,array{label:string,route:string,url:string,icon:string,active:bool}>}>
     */
    public function for(User $user): array
    {
        $sections = [];

        foreach ($this->definition() as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                if (! Route::has($item['route'])) {
                    continue;   // the phase that adds it has not landed yet
                }

                $permissions = (array) ($item['permission'] ?? []);

                if ($permissions !== [] && ! $this->resolver->hasAny($user, ...$permissions)) {
                    continue;
                }

                $items[] = [
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'url' => route($item['route']),
                    'icon' => $item['icon'],
                    'active' => $this->isActive($item['active'] ?? $item['route']),
                ];
            }

            if ($items !== []) {
                $sections[] = ['label' => $section['label'], 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Route-name pattern(s) that light this item up, e.g. 'org-units.*'.
     */
    private function isActive(string|array $patterns): bool
    {
        return request()->routeIs(...(array) $patterns);
    }

    /**
     * The navigation table.
     *
     * Deliberately a plain array and not database-driven. Which modules exist is a property of
     * the deployed code; which of them a given person SEES is the configurable part, and that is
     * handled by the permission keys above. Making the menu itself editable would let an
     * administrator hide a module without removing anyone's access to it — the appearance of a
     * control rather than a control.
     *
     * @return array<int,array{label:string,items:array<int,array<string,mixed>>}>
     */
    private function definition(): array
    {
        return [
            [
                'label' => '',
                'items' => [
                    ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
                ],
            ],
            [
                'label' => 'Organization',
                'items' => [
                    ['label' => 'Org chart', 'route' => 'org-units.index', 'icon' => 'sitemap',
                        'permission' => 'org_unit.view', 'active' => 'org-units.*'],
                    ['label' => 'People', 'route' => 'employees.index', 'icon' => 'users',
                        'permission' => 'employee.view', 'active' => 'employees.*'],
                    ['label' => 'Positions', 'route' => 'positions.index', 'icon' => 'badge',
                        'permission' => 'position.view', 'active' => 'positions.*'],
                    ['label' => 'Locations', 'route' => 'locations.index', 'icon' => 'pin',
                        'permission' => 'location.view', 'active' => 'locations.*'],
                ],
            ],
            [
                'label' => 'Delivery',
                'items' => [
                    ['label' => 'Projects', 'route' => 'projects.index', 'icon' => 'briefcase',
                        'permission' => 'project.view', 'active' => 'projects.*'],
                    ['label' => 'Tasks', 'route' => 'tasks.index', 'icon' => 'check-square',
                        'permission' => 'task.view', 'active' => 'tasks.*'],
                ],
            ],
            [
                'label' => 'Communication',
                'items' => [
                    ['label' => 'Announcements', 'route' => 'announcements.index', 'icon' => 'megaphone',
                        'permission' => 'announcement.view', 'active' => 'announcements.*'],
                    ['label' => 'Memos', 'route' => 'memos.index', 'icon' => 'mail',
                        'permission' => 'memo.view', 'active' => 'memos.*'],
                    ['label' => 'Meetings', 'route' => 'meetings.index', 'icon' => 'users',
                        'permission' => 'meeting.view', 'active' => 'meetings.*'],

                    // The calendar has no permission of its own — it is a view over records that
                    // have theirs — so it is listed for anybody who deals in either source.
                    ['label' => 'Calendar', 'route' => 'calendar.index', 'icon' => 'calendar',
                        'permission' => ['meeting.view', 'task.view'], 'active' => 'calendar.*'],
                ],
            ],
            [
                'label' => 'Documents',
                'items' => [
                    ['label' => 'Documents', 'route' => 'documents.index', 'icon' => 'file-text',
                        'permission' => 'document.view', 'active' => 'documents.*'],
                    ['label' => 'Transmittals', 'route' => 'transmittals.index', 'icon' => 'download',
                        'permission' => 'transmittal.view', 'active' => 'transmittals.*'],

                    // The approvals inbox is listed for anyone who can act on OR merely watch an
                    // approval, because a reviewer with `approval.view` and no `approval.act` is a
                    // real role — the person who needs to see where a submission has got to.
                    ['label' => 'My approvals', 'route' => 'approvals.index', 'icon' => 'gavel',
                        'permission' => ['approval.view', 'approval.act'], 'active' => 'approvals.*'],
                ],
            ],
            [
                // The engineering registers. Ordered the way the work runs rather than
                // alphabetically: you plan deliverables, you draw them, you ask questions about
                // them, you send things for approval, and then somebody changes the scope.
                'label' => 'Engineering',
                'items' => [
                    ['label' => 'Deliverables', 'route' => 'deliverables.index', 'icon' => 'flag',
                        'permission' => 'deliverable.view', 'active' => 'deliverables.*'],
                    ['label' => 'Drawings', 'route' => 'drawings.index', 'icon' => 'pencil',
                        'permission' => 'drawing.view', 'active' => 'drawings.*'],
                    ['label' => 'RFIs & queries', 'route' => 'rfis.index', 'icon' => 'info',
                        'permission' => 'rfi.view', 'active' => 'rfis.*'],
                    ['label' => 'Submittals', 'route' => 'submittals.index', 'icon' => 'archive',
                        'permission' => 'submittal.view', 'active' => 'submittals.*'],
                    ['label' => 'Change requests', 'route' => 'change-requests.index', 'icon' => 'activity',
                        'permission' => 'change_request.view', 'active' => 'change-requests.*'],
                ],
            ],
            [
                // HSE, ordered by what a safety adviser opens most. Incidents first because that is
                // what the section is judged on; observations high up because the whole point of a
                // near-miss card is that reporting one takes thirty seconds; permits above training
                // because a control room refreshes "live now" all day.
                //
                // Investigations get their own entry even though every one of them is reached from
                // an incident, because "what is still under investigation" is a question asked
                // across the whole register rather than about one record. They are governed by
                // `hse_incident` throughout — an investigation is the incident's own paperwork.
                'label' => 'Health & safety',
                'items' => [
                    ['label' => 'Incidents', 'route' => 'incidents.index', 'icon' => 'alert',
                        'permission' => 'hse_incident.view', 'active' => 'incidents.*'],
                    ['label' => 'Investigations', 'route' => 'investigations.index', 'icon' => 'search',
                        'permission' => 'hse_incident.investigate', 'active' => 'investigations.*'],
                    ['label' => 'Corrective actions', 'route' => 'corrective-actions.index', 'icon' => 'target',
                        'permission' => 'corrective_action.view', 'active' => 'corrective-actions.*'],
                    ['label' => 'Observations', 'route' => 'hse-observations.index', 'icon' => 'info',
                        'permission' => 'hse_observation.view', 'active' => 'hse-observations.*'],
                    ['label' => 'Permits to work', 'route' => 'permits.index', 'icon' => 'lock',
                        'permission' => 'permit.view', 'active' => 'permits.*'],
                    ['label' => 'Risk assessments', 'route' => 'risk-assessments.index', 'icon' => 'shield',
                        'permission' => 'hse_risk.view', 'active' => 'risk-assessments.*'],
                    ['label' => 'Inspections', 'route' => 'hse-inspections.index', 'icon' => 'check-square',
                        'permission' => 'hse_inspection.view', 'active' => 'hse-inspections.*'],
                    ['label' => 'Toolbox talks', 'route' => 'toolbox-talks.index', 'icon' => 'users',
                        'permission' => 'toolbox_talk.view', 'active' => 'toolbox-talks.*'],
                    ['label' => 'Training records', 'route' => 'training-records.index', 'icon' => 'badge',
                        'permission' => 'hse_training.view', 'active' => 'training-records.*'],
                ],
            ],
            [
                'label' => 'Quality',
                'items' => [
                    ['label' => 'Inspection requests', 'route' => 'quality-inspections.index', 'icon' => 'check',
                        'permission' => 'quality_inspection.view', 'active' => 'quality-inspections.*'],
                    ['label' => 'Non-conformances', 'route' => 'ncrs.index', 'icon' => 'scroll',
                        'permission' => 'ncr.view', 'active' => 'ncrs.*'],
                ],
            ],
            [
                'label' => 'Administration',
                'items' => [
                    ['label' => 'User accounts', 'route' => 'users.index', 'icon' => 'key',
                        'permission' => ['user.view', 'user.manage'], 'active' => 'users.*'],
                    ['label' => 'Roles & permissions', 'route' => 'roles.index', 'icon' => 'shield',
                        'permission' => ['role.edit', 'role.manage', 'role.create'], 'active' => 'roles.*'],
                    ['label' => 'Company profile', 'route' => 'organization.edit', 'icon' => 'building',
                        'permission' => ['organization.view', 'organization.configure'], 'active' => 'organization.*'],
                    ['label' => 'Settings', 'route' => 'settings.index', 'icon' => 'sliders',
                        'permission' => ['setting.view', 'setting.manage'], 'active' => 'settings.*'],
                    ['label' => 'Workflows', 'route' => 'workflows.index', 'icon' => 'target',
                        'permission' => ['workflow.view', 'workflow.manage'], 'active' => 'workflows.*'],
                    ['label' => 'Document registers', 'route' => 'document-categories.index', 'icon' => 'archive',
                        'permission' => ['document_category.view', 'document_category.manage'],
                        'active' => 'document-categories.*'],
                    ['label' => 'Templates', 'route' => 'templates.index', 'icon' => 'file-text',
                        'permission' => ['template.view', 'template.manage'], 'active' => 'templates.*'],

                    // Two catalogues, listed here rather than beside the registers they feed,
                    // because both are administration: `permit_types.precaution_template` is the
                    // safety checklist copied onto every future permit, and a course's
                    // `validity_months` decides when every certificate of that kind lapses. Both
                    // are shown only to the `manage` holder, which is why their permission lists
                    // omit the matching `.view` — everybody with `.view` can already read a type's
                    // name on the records that use it, and does not need a settings screen for it.
                    ['label' => 'Permit types', 'route' => 'permit-types.index', 'icon' => 'lock',
                        'permission' => 'permit.manage', 'active' => 'permit-types.*'],
                    ['label' => 'Training courses', 'route' => 'training-courses.index', 'icon' => 'badge',
                        'permission' => 'hse_training.manage', 'active' => 'training-courses.*'],

                    ['label' => 'Audit trail', 'route' => 'audit.index', 'icon' => 'scroll',
                        'permission' => 'audit_log.view', 'active' => 'audit.*'],
                ],
            ],
        ];
    }
}
