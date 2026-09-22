<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Support\Enums\OrgUnitType;
use App\Support\Navigation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The §73 walkthrough document, held to the screens it names.
 *
 * WHY A TEST FOR A MARKDOWN FILE. §73 asks that configuring a new company be completable through
 * the UI by a non-developer, and `NewCompanyWalkthroughTest` proves the system can do it. It cannot
 * prove that the INSTRUCTIONS can be followed, and those are what a non-developer actually has. A
 * menu item renamed from "Reference numbering" to something tidier breaks nothing, passes every
 * test in the suite, and leaves an administrator hunting a sidebar for a screen that is sitting in
 * front of them under a different name — at which point the documented answer is worse than no
 * answer, because it costs them the time they spend trusting it.
 *
 * So every `**Group › Screen**` reference in docs/17 is resolved against the navigation the
 * application actually builds, for somebody who can see all of it. Two things get checked at once
 * by doing it that way: the screen exists, and it is where the document says to look for it.
 *
 * @see NewCompanyWalkthroughTest for the walk itself, performed against the endpoints
 */
final class WalkthroughDocumentTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private const DOCUMENT = 'docs/17-new-company-setup.md';

    #[Test]
    public function every_screen_the_walkthrough_names_is_on_the_menu_it_says_it_is_on(): void
    {
        $menu = $this->menuForSomebodyWhoSeesEverything();

        $references = $this->screensNamedInTheDocument();

        // A guard on the guard: if the parse ever stops matching — a different arrow character, a
        // reformat — this file would pass by checking nothing at all.
        $this->assertGreaterThanOrEqual(10, count($references), 'The document parsed to almost no screen references.');

        foreach ($references as [$group, $screen]) {
            $this->assertArrayHasKey(
                $group,
                $menu,
                'The walkthrough sends people to a "'.$group.'" menu, and there is no such group.',
            );

            $this->assertContains(
                $screen,
                $menu[$group],
                'The walkthrough sends people to '.$group.' › '.$screen.', which is not on that menu. '
                .'It holds: '.implode(', ', $menu[$group]),
            );
        }
    }

    #[Test]
    public function the_command_the_document_gives_is_the_command_that_exists(): void
    {
        $text = $this->document();

        // The one instruction in the document that is typed rather than clicked, given to somebody
        // who cannot debug it. Every option in it has to be a real option, because the failure mode
        // is an operator on a cPanel shell reading "The --admin-email option does not exist" and
        // having no idea which part of the line to change.
        $this->assertStringContainsString('php artisan engops:install', $text);

        $definition = $this->app->make(Kernel::class)
            ->all()['engops:install']
            ->getDefinition();

        foreach (['name', 'admin-name', 'admin-email'] as $option) {
            $this->assertTrue(
                $definition->hasOption($option),
                'The document tells people to pass --'.$option.', which the command does not accept.',
            );

            $this->assertStringContainsString('--'.$option.'=', $text);
        }
    }

    #[Test]
    public function the_document_does_not_send_a_non_developer_to_the_database(): void
    {
        $text = strtolower($this->document());

        // §73 is the whole point: a company configures itself on screens. A walkthrough that
        // resolves a step with "run this query" has not documented the system, it has documented a
        // gap in it — and the row somebody writes by hand is the row nothing else understands.
        // The document may of course say NOT to, which is why the test looks for the instruction
        // shapes rather than the words.
        foreach (['phpmyadmin', 'insert into', 'update `', 'mysql -u'] as $instruction) {
            $this->assertStringNotContainsString(
                $instruction,
                $text,
                'The walkthrough resolves a step with "'.$instruction.'", which §73 exists to make unnecessary.',
            );
        }
    }

    /**
     * The navigation as the application builds it, for somebody refused nothing.
     *
     * Built rather than read out of the source: `Navigation::for()` drops items whose route does
     * not exist and items the viewer cannot reach, and both of those are ways a documented screen
     * can be absent from a real sidebar while its label is still sitting in the table.
     *
     * @return array<string,array<int,string>> group label => screen labels
     */
    private function menuForSomebodyWhoSeesEverything(): array
    {
        $organization = $this->makeOrganization('Harmattan Engineering');
        $unit = $this->makeUnit($organization, 'Civil', OrgUnitType::Department);

        // The owner, because the resolver grants that flag every permission in the catalogue
        // (AccessResolver.php:127). Assembling a role that holds everything instead would make this
        // test's fixture, rather than the application, decide which screens are reachable.
        $owner = $this->makePerson($organization, 'Ifeoma Adeyemi', $unit, []);
        $owner->is_super_admin = true;
        $owner->save();

        $menu = [];

        foreach ($this->app->make(Navigation::class)->for($owner) as $section) {
            $menu[$section['label']] = array_map(
                static fn (array $item): string => $item['label'],
                $section['items'],
            );
        }

        return $menu;
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    private function screensNamedInTheDocument(): array
    {
        preg_match_all('/\*\*([A-Z][A-Za-z ]+) › ([^*]+)\*\*/u', $this->document(), $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): array => [trim($match[1]), trim($match[2])],
            $matches,
        );
    }

    private function document(): string
    {
        $path = base_path(self::DOCUMENT);

        $this->assertFileExists($path, 'The §73 walkthrough is missing.');

        return (string) file_get_contents($path);
    }
}
