<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PermissionCatalogue;
use App\Support\RoleTemplates;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A permission no starter role grants is a feature that ships switched off (§73).
 *
 * THE FAILURE THIS CATCHES IS NOT A BUG IN ANY ONE FILE. Adding a permission to the catalogue and
 * adding it to a role template are two separate acts in two separate files, and only the first one
 * is required to make a route compile, a policy answer and a menu item exist. Miss the second and
 * every screen behind that permission is reachable in development — where the developer is the
 * organization owner and `is_super_admin` short-circuits the resolver before it ever looks at a
 * role — and reachable by nobody at all in a company that was provisioned from the templates. The
 * feature is finished, tested, documented and invisible.
 *
 * MEASURED, NOT HYPOTHETICAL. When this file was written the catalogue held 560 permissions and 14
 * of them were granted by no template: `document_category.manage`, so a provisioned company could
 * not create the categories its document register is built on; `approval.reassign`, which is the
 * audience EscalationService sends its third and final warning to, so the top rung of the
 * escalation ladder reached an empty room; `change_request.create` and `.edit`, so an entire
 * register had `submit`, `review` and `approve` and no way to raise one. Each of those is a
 * green suite and a broken company.
 *
 * WHY §73 IS THE SPECIFICATION HERE. "A non-developer configures a new company entirely through the
 * UI" is only true if the permissions that unlock those screens can be put on a role through the
 * role editor — and `RolePolicy::mayGrantAll()` forbids granting a permission you do not yourself
 * hold, so the owner cannot hand out what no template gave anybody without first editing a role as
 * the owner. That is the database access §73 says the walkthrough must not need.
 *
 * @see RoleTemplates for the grants themselves
 * @see PermissionCatalogue for the keys they expand against
 */
final class RoleTemplateCoverageTest extends TestCase
{
    /**
     * Permissions that are deliberately the organization owner's alone.
     *
     * An entry here is a decision, not a to-do, and it costs a sentence: say which act it guards
     * and why no role a company can assign should carry it. An empty allow-list is the healthy
     * state, and a growing one is the thing to argue about in review.
     *
     * @var array<string,string>
     */
    private const OWNER_ONLY = [];

    /**
     * The permissions the §73 walkthrough actually spends.
     *
     * An empty tenant becomes a routed, numbered, reportable register by doing these things in this
     * order, so each key here is a step somebody has to be able to take. Listed explicitly rather
     * than derived, because the point is to name the journey: a coverage test that only counts
     * cannot tell the difference between a permission nobody needs and the one that stops the walk.
     *
     * @var array<int,string>
     */
    private const WALKTHROUGH = [
        'org_unit.create',          // build the org chart
        'position.create',          // give it job titles
        'user.create',              // put people in it
        'role.create',              // decide what they may do
        'role.assign',              // and hand it to them
        'numbering.manage',         // a document number format (§64)
        'status.manage',            // a status vocabulary (§63)
        'document_category.manage', // the register's categories
        'workflow.create',          // the route a document travels
        'workflow.publish',         // and switching it on
        'document.create',          // the first record through it
        'report.create',            // proving it can be read back
    ];

    #[Test]
    public function every_permission_in_the_catalogue_can_be_granted_by_some_starter_role(): void
    {
        $orphans = array_values(array_diff(
            PermissionCatalogue::keys(),
            array_keys($this->granted()),
            array_keys(self::OWNER_ONLY),
        ));

        sort($orphans);

        $this->assertSame([], $orphans, sprintf(
            "%d permission(s) exist in the catalogue and are granted by no starter role.\n\n%s\n\n"
            ."In a company provisioned from these templates nobody but the organization owner can\n"
            ."reach the screens behind them, and the owner cannot delegate what no role holds —\n"
            ."RolePolicy::mayGrantAll() forbids granting a permission you do not hold yourself.\n\n"
            ."Put each one on the template whose job it obviously is, at that template's own scope.\n"
            .'If it genuinely belongs to the owner alone, add it to OWNER_ONLY with the reason.',
            count($orphans),
            implode("\n", array_map(static fn (string $k): string => '  '.$k, $orphans)),
        ));
    }

    #[Test]
    public function every_pattern_a_template_grants_matches_something_in_the_catalogue(): void
    {
        $dead = [];

        foreach (RoleTemplates::all() as $slug => $template) {
            foreach (array_keys($template['grants']) as $pattern) {
                if (RoleTemplates::expand([$pattern => 'own']) === []) {
                    $dead[] = $slug.' grants '.$pattern;
                }
            }
        }

        $this->assertSame([], $dead, sprintf(
            "A template grants a pattern that matches no permission.\n\n%s\n\n"
            ."expand() silently drops a pattern it cannot match, so a typo in a key or a module\n"
            ."renamed without updating its templates produces a role that looks right in the editor\n"
            .'and confers nothing. Nothing throws; the grant simply is not there.',
            implode("\n", array_map(static fn (string $d): string => '  '.$d, $dead)),
        ));
    }

    #[Test]
    public function the_escalation_ladders_top_rung_reaches_somebody(): void
    {
        $holders = $this->granted()['approval.reassign'] ?? [];

        $this->assertNotEmpty($holders,
            "No starter role grants `approval.reassign`.\n\n"
            ."EscalationService escalates an overdue approval three times: to the assignees, to their\n"
            ."supervisors, and finally to holders of `approval.reassign` — the people who can move a\n"
            ."stuck stage off somebody who has left. If no role grants it, the third escalation is\n"
            ."dispatched to an empty recipient list. Nothing errors, no notification is sent, and the\n"
            .'record stays where it is. §25 is the requirement this would quietly break.');
    }

    #[Test]
    public function a_new_company_can_be_configured_by_a_role_rather_than_by_its_owner(): void
    {
        $granted = $this->granted();
        $catalogue = PermissionCatalogue::keys();
        $unreachable = [];

        foreach (self::WALKTHROUGH as $permission) {
            // A typo here must fail loudly rather than pass by matching nothing.
            $this->assertContains($permission, $catalogue,
                "The walkthrough names `{$permission}`, which is not a permission. Fix the list or the key.");

            if (($granted[$permission] ?? []) === []) {
                $unreachable[] = $permission;
            }
        }

        $this->assertSame([], $unreachable, sprintf(
            "The §73 walkthrough cannot be completed by anybody except the organization owner.\n\n"
            ."Steps no assignable role can take:\n%s\n\n"
            .'§73 is "a non-developer configures a new company entirely through the UI". Every step '
            ."above\nis a screen that exists and that nobody can be given.",
            implode("\n", array_map(static fn (string $k): string => '  '.$k, $unreachable)),
        ));
    }

    #[Test]
    public function no_starter_role_holds_the_whole_catalogue(): void
    {
        $everything = count(PermissionCatalogue::keys());

        foreach (RoleTemplates::all() as $slug => $template) {
            $held = count(RoleTemplates::expand($template['grants']));

            $this->assertLessThan($everything, $held,
                "The `{$slug}` template grants every permission in the catalogue.\n\n"
                ."These are the roles a company is handed on day one, and a starter role that can do\n"
                ."everything is an owner account with a different name on it — separation of duties\n"
                .'is gone before anybody has made a single decision about it.');
        }
    }

    #[Test]
    public function the_administrator_template_configures_the_platform_without_approving_anything(): void
    {
        $held = array_keys(RoleTemplates::expand(RoleTemplates::all()['system-administrator']['grants']));

        $approvals = array_values(array_filter(
            $held,
            static fn (string $key): bool => str_ends_with($key, '.approve') || str_ends_with($key, '.reject'),
        ));

        $this->assertSame([], $approvals, sprintf(
            "The System Administrator template has been given approval authority: %s\n\n"
            ."Its description says it deliberately holds none, and that is the separation the role\n"
            ."exists to draw: the person who builds the approval route must not also be able to\n"
            .'decide what travels it, or the route is a formality they can walk around.',
            implode(', ', $approvals),
        ));

        // The other half of the same claim: it must still be able to build the thing.
        $this->assertContains('workflow.publish', $held);
        $this->assertContains('role.manage', $held);
    }

    /**
     * Every permission any template grants, and which templates grant it.
     *
     * @return array<string,array<int,string>>
     */
    private function granted(): array
    {
        $granted = [];

        foreach (RoleTemplates::all() as $slug => $template) {
            foreach (array_keys(RoleTemplates::expand($template['grants'])) as $key) {
                $granted[$key][] = $slug;
            }
        }

        return $granted;
    }
}
