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
                    ['label' => 'Audit trail', 'route' => 'audit.index', 'icon' => 'scroll',
                        'permission' => 'audit_log.view', 'active' => 'audit.*'],
                ],
            ],
        ];
    }
}
