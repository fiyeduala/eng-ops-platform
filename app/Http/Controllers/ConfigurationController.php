<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\AccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * The Admin Configuration Center — one door to everything a company may change about itself (§73).
 *
 * THIS PAGE EXISTS BECAUSE §73 IS A USABILITY REQUIREMENT, NOT A FEATURE LIST. Every screen it
 * links to could be reached from the sidebar, and most already can. But "configure a new company,
 * end to end, without a developer" is only true if somebody can find the end. Fourteen items
 * scattered down a long Administration menu, interleaved with user accounts and the audit trail,
 * is a list of features; a page that says *here is the vocabulary, here are the numbers, here are
 * the approvals, here are the forms* is a route through the job.
 *
 * THE TILES ARE BUILT FROM PERMISSIONS (§96) and from the router. A tile whose route does not
 * exist yet is skipped — the later parts of this phase land one at a time, and the hub should
 * describe the finished shape while telling the truth about today. A tile whose permissions the
 * viewer does not hold is skipped too, which is Gate 2 and Gate 2 only: every route behind these
 * tiles carries its own `permission:` middleware and its own policy, because hiding a link is a
 * courtesy and the route is the control (§6, §84).
 */
class ConfigurationController extends Controller
{
    public function __construct(
        private readonly AccessResolver $resolver,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $groups = [];

        foreach ($this->areas() as $group) {
            $tiles = [];

            foreach ($group['tiles'] as $tile) {
                if (! Route::has($tile['route'])) {
                    continue;
                }

                if (! $this->resolver->hasAny($user, ...$tile['permission'])) {
                    continue;
                }

                $tiles[] = $tile + ['url' => route($tile['route'])];
            }

            if ($tiles !== []) {
                $groups[] = ['label' => $group['label'], 'description' => $group['description'], 'tiles' => $tiles];
            }
        }

        return view('configuration.index', ['groups' => $groups]);
    }

    /**
     * The configuration surface, in the order somebody setting up a company works through it.
     *
     * The order is the argument. You name the company and its people before you can say who
     * approves what; you settle the vocabulary before you draw the routes that move through it;
     * you define the documents last, because they hang off everything above them.
     *
     * @return array<int,array{label:string,description:string,tiles:array<int,array<string,mixed>>}>
     */
    private function areas(): array
    {
        return [
            [
                'label' => 'The company',
                'description' => 'Who you are, how you are structured, and the defaults everything else inherits.',
                'tiles' => [
                    [
                        'label' => 'Company profile',
                        'route' => 'organization.edit',
                        'icon' => 'building',
                        'permission' => ['organization.view', 'organization.configure'],
                        'blurb' => 'Name, registered details, logo and the addresses that appear on issued documents.',
                    ],
                    [
                        'label' => 'Settings',
                        'route' => 'settings.index',
                        'icon' => 'sliders',
                        'permission' => ['setting.view', 'setting.manage'],
                        'blurb' => 'Working week, currency, approval behaviour — the defaults every module reads.',
                    ],
                    [
                        'label' => 'Roles & permissions',
                        'route' => 'roles.index',
                        'icon' => 'shield',
                        'permission' => ['role.edit', 'role.manage', 'role.create'],
                        'blurb' => 'What each kind of person may see and do, and how far across the company they may see it.',
                    ],
                ],
            ],
            [
                'label' => 'How work moves',
                'description' => 'The states your records can be in, and the routes that carry them between those states.',
                'tiles' => [
                    [
                        'label' => 'Statuses & transitions',
                        'route' => 'configuration.statuses.index',
                        'icon' => 'target',
                        'permission' => ['status.view', 'status.manage'],
                        'blurb' => 'Your own words for every stage of every register, and exactly who may move a record between them.',
                    ],
                    [
                        'label' => 'Approval workflows',
                        'route' => 'workflows.index',
                        'icon' => 'check-square',
                        'permission' => ['workflow.view', 'workflow.manage'],
                        'blurb' => 'Who signs off what, in what order, with what deadline, and what happens when they do not.',
                    ],
                    [
                        'label' => 'Escalation rules',
                        'route' => 'escalations.index',
                        'icon' => 'bell',
                        'permission' => ['escalation.view', 'escalation.manage'],
                        'blurb' => 'What the platform does on its own when an approval sits too long.',
                    ],
                ],
            ],
            [
                'label' => 'What you record',
                'description' => 'The shape of the information itself: the fields, the numbers on them and the paper they become.',
                'tiles' => [
                    [
                        'label' => 'Custom fields',
                        'route' => 'configuration.custom-fields.index',
                        'icon' => 'clipboard',
                        'permission' => ['custom_field.view', 'custom_field.manage'],
                        'blurb' => 'The things your company records that nobody else does, added to any register without a developer.',
                    ],
                    [
                        'label' => 'Option lists',
                        'route' => 'configuration.option-lists.index',
                        'icon' => 'scroll',
                        'permission' => ['custom_field.view', 'custom_field.manage'],
                        'blurb' => 'Your own words for disciplines, causes and classes — whatever a dropdown on any form should offer.',
                    ],
                    [
                        'label' => 'Numbering schemes',
                        'route' => 'configuration.numbering.index',
                        'icon' => 'pin',
                        'permission' => ['numbering.view', 'numbering.manage'],
                        'blurb' => 'How every reference number is built, and where the counter resets.',
                    ],
                    [
                        'label' => 'Forms',
                        'route' => 'forms.index',
                        'icon' => 'file-text',
                        'permission' => ['form.view', 'form.manage'],
                        'blurb' => 'Build a form, version it, and send what people submit into an approval route.',
                    ],
                    [
                        'label' => 'Templates',
                        'route' => 'templates.index',
                        'icon' => 'file-text',
                        'permission' => ['template.view', 'template.manage'],
                        'blurb' => 'The wording of the letters, memos and notices the platform issues on your behalf.',
                    ],
                    [
                        'label' => 'Document registers',
                        'route' => 'document-categories.index',
                        'icon' => 'archive',
                        'permission' => ['document_category.view', 'document_category.manage'],
                        'blurb' => 'The filing structure your documents are kept in, and who may open each drawer.',
                    ],
                ],
            ],
            [
                'label' => 'Keeping honest',
                'description' => 'What the platform watches for you, and the record of everything anybody changed.',
                'tiles' => [
                    [
                        'label' => 'Renewals & expiry',
                        'route' => 'configuration.expiry-watches.index',
                        'icon' => 'clock',
                        'permission' => ['expiry_watch.view', 'expiry_watch.manage'],
                        'blurb' => 'Which dates get chased — certificates, insurances, permits, training — how far ahead, and who hears.',
                    ],
                    [
                        'label' => 'Measures',
                        'route' => 'configuration.kpis.index',
                        'icon' => 'activity',
                        'permission' => ['kpi.view', 'kpi.manage'],
                        'blurb' => 'The figures this company watches, each one counted over the records the person looking may see.',
                    ],
                    [
                        'label' => 'Boards',
                        'route' => 'configuration.widgets.index',
                        'icon' => 'grid',
                        'permission' => ['dashboard.view', 'dashboard.view_executive', 'dashboard.view_department', 'dashboard.manage'],
                        'blurb' => 'Which tiles sit on each board. One arrangement, and everybody sees the tiles they may see with figures counted for them.',
                    ],
                    [
                        'label' => 'Reports',
                        'route' => 'configuration.reports.index',
                        'icon' => 'clipboard',
                        'permission' => ['report.view', 'report.create'],
                        'blurb' => 'The questions this company asks of its own records. Each one answers with the rows the person running it may see.',
                    ],
                    [
                        'label' => 'Audit trail',
                        'route' => 'audit.index',
                        'icon' => 'scroll',
                        'permission' => ['audit_log.view'],
                        'blurb' => 'Every change to every record, including the ones made on this page.',
                    ],
                ],
            ],
        ];
    }
}
