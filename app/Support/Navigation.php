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
                    ['label' => 'Audit trail', 'route' => 'audit.index', 'icon' => 'scroll',
                        'permission' => 'audit_log.view', 'active' => 'audit.*'],
                ],
            ],
        ];
    }
}
