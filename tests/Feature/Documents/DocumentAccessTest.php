<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The controlled register: who reaches a document, and what reaching it entitles them to (§26, §67,
 * §75, §84, §94).
 *
 * Four claims are under test here, and none of them is CRUD.
 *
 *   DOWNLOAD IS NOT A CONSEQUENCE OF VIEW. §67 asks for the distinction and this file is where it
 *   is held. A reviewer with `document.view` who may read a procedure on screen gets 403 on the
 *   file, the .docx export and the PDF — three separate routes, all three checked, because the
 *   export routes are the ones a later refactor forgets.
 *
 *   THE URL IS NOT AUTHORITY (§84, §94). Changing the id in `/documents/1` reaches somebody else's
 *   document only if reach says so, and `/documents/1/versions/{id of another document's revision}`
 *   is a 404 rather than an authorization performed against the wrong record.
 *
 *   ORGANIZATION A NEVER TOUCHES ORGANIZATION B (§75). Asserted on TITLES, not numbers: numbering
 *   is per-tenant, so both companies' first document is DOC-2026-0001 and a number assertion would
 *   pass whichever record came back.
 *
 *   AN APPROVED DOCUMENT CANNOT BE DELETED BY ANYBODY. Not the owner, not the administrator. It was
 *   issued and relied on; `archive` is the answer to "we do not use this any more".
 *
 * The fixture is one company, two departments, and four people:
 *
 *   Civil (dept)       Ngozi   — document controller: `manage`, `download`, and department reach
 *   Civil (dept)       Emeka   — engineer: `document.view:department` and NOTHING else
 *   Piping (dept)      Sade    — another department, same breadth
 *   Executive          Yusuf   — organization-wide view, and deliberately no `download`
 */
final class DocumentAccessTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $ngozi;    // document controller

    private User $emeka;    // engineer, view only

    private User $sade;     // another department

    private User $yusuf;    // organization-wide view, no download

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $engineering);
        $executive = $this->makeUnit($this->org, 'Executive', OrgUnitType::Department);

        $controllerRole = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'document.create' => 'department',
            'document.edit' => 'department',
            'document.delete' => 'department',
            'document.manage' => 'department',
            'document.submit' => 'department',
            'document.download' => 'department',
            'document.print' => 'department',
            'document.archive' => 'department',
            'document.restore' => 'department',
            'document.view_confidential' => 'department',
            'document_category.view' => 'organization',
            // Filing a document into a department means being able to see that department: the
            // controller authorizes the PLACEMENT as well as the document, so a posted unit id
            // cannot file a record somewhere the author cannot reach (§84).
            'org_unit.view' => 'organization',
            'user.view' => 'organization',
        ]);

        // Read on screen and nothing else. The download refusals below hang on this role having
        // no `document.download` at any scope whatsoever.
        $engineerRole = $this->makeRole($this->org, 'Engineer', [
            'document.view' => 'department',
        ]);

        $directorRole = $this->makeRole($this->org, 'Director', [
            'document.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controllerRole]);
        $this->emeka = $this->makePerson($this->org, 'Emeka Obi', $this->civil, [$engineerRole]);
        $this->sade = $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$engineerRole]);
        $this->yusuf = $this->makePerson($this->org, 'Yusuf Danladi', $executive, [$directorRole]);

        // A register with no workflow: submitting approves outright, which keeps the delete and
        // download tests from depending on a route that is the subject of another file.
        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
            'workflow_id' => null,
        ]);
    }

    // ------------------------------------------------------------------ reach

    #[Test]
    public function a_document_is_reachable_within_the_department_it_is_filed_against(): void
    {
        $document = $this->civilProcedure();

        $this->actingFresh($this->ngozi)->get(route('documents.show', $document))->assertOk();
        $this->actingFresh($this->emeka)->get(route('documents.show', $document))->assertOk();

        // Piping. Same permission, same scope, different department.
        $this->actingFresh($this->sade)->get(route('documents.show', $document))->assertForbidden();
    }

    #[Test]
    public function the_register_lists_only_what_the_reader_may_reach(): void
    {
        $civil = $this->civilProcedure();

        $piping = $this->makeDocument($this->org, $this->ngozi, 'Hydrotest procedure', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->piping->id,
        ]);

        $this->actingFresh($this->emeka)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Excavation and shoring procedure')
            ->assertDontSee('Hydrotest procedure');

        // The director reaches both, which is what makes the assertion above about scope rather
        // than about the listing being broken.
        $this->actingFresh($this->yusuf)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee($civil->title)
            ->assertSee($piping->title);
    }

    #[Test]
    public function a_confidential_document_needs_the_confidential_permission_as_well_as_reach(): void
    {
        $document = $this->civilProcedure(['confidentiality' => Confidentiality::Confidential->value]);

        // Same department, ordinary reach. Confidentiality is a second axis, not a stronger scope.
        $this->actingFresh($this->emeka)->get(route('documents.show', $document))->assertForbidden();

        $this->actingFresh($this->ngozi)->get(route('documents.show', $document))->assertOk();
    }

    // ------------------------------------------------------------------ §67 download

    #[Test]
    public function reading_a_document_on_screen_does_not_entitle_anybody_to_take_it_away(): void
    {
        $document = $this->civilProcedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi);

        // Emeka may read the page.
        $this->actingFresh($this->emeka)
            ->get(route('documents.show', $document))
            ->assertOk();

        // And may not have the file, the Word export, or the print.
        $this->actingFresh($this->emeka)
            ->get(route('documents.versions.docx', [$document, $version]))
            ->assertForbidden();

        $this->actingFresh($this->emeka)
            ->get(route('documents.versions.pdf', [$document, $version]))
            ->assertForbidden();

        $this->actingFresh($this->emeka)
            ->get(route('documents.versions.download', [$document, $version]))
            ->assertForbidden();
    }

    #[Test]
    public function organization_wide_reach_still_does_not_carry_download(): void
    {
        $document = $this->civilProcedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi);

        $this->actingFresh($this->yusuf)->get(route('documents.show', $document))->assertOk();

        $this->actingFresh($this->yusuf)
            ->get(route('documents.versions.docx', [$document, $version]))
            ->assertForbidden();
    }

    #[Test]
    public function somebody_who_holds_download_gets_the_word_export(): void
    {
        $document = $this->civilProcedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore every excavation deeper than 1.2 m.</p>');

        $response = $this->actingFresh($this->ngozi)
            ->get(route('documents.versions.docx', [$document, $version]));

        $response->assertOk();
        $this->assertStringContainsString(
            'wordprocessingml',
            (string) $response->headers->get('content-type'),
        );
    }

    // ------------------------------------------------------------------ §84, §94 IDOR

    #[Test]
    public function changing_the_id_in_the_url_reaches_nothing_reach_did_not_already_allow(): void
    {
        $mine = $this->civilProcedure();

        $theirs = $this->makeDocument($this->org, $this->ngozi, 'Pipe stress analysis method', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->piping->id,
        ]);

        // Emeka can see one of these two by walking the register. Typing the other id in changes
        // nothing.
        $this->actingFresh($this->emeka)->get(route('documents.show', $mine))->assertOk();
        $this->actingFresh($this->emeka)->get(route('documents.show', $theirs))->assertForbidden();
        $this->actingFresh($this->emeka)->get(route('documents.edit', $theirs))->assertForbidden();

        $this->actingFresh($this->emeka)
            ->put(route('documents.update', $theirs), [
                'title' => 'Renamed by somebody who should not be able to',
                'confidentiality' => Confidentiality::Internal->value,
            ])
            ->assertForbidden();

        $this->assertSame('Pipe stress analysis method', $theirs->refresh()->title);
    }

    #[Test]
    public function a_revision_belonging_to_another_document_is_a_404_not_an_authorization_on_the_wrong_record(): void
    {
        $mine = $this->civilProcedure();
        $this->makeNativeVersion($this->org, $mine, $this->ngozi);

        $other = $this->makeDocument($this->org, $this->ngozi, 'Backfill and compaction procedure', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
        ]);

        $otherVersion = $this->makeNativeVersion($this->org, $other, $this->ngozi);

        // Ngozi may read both documents, so this is not a permission failure — it is the
        // controller refusing to authorize a revision against a document it does not belong to.
        $this->actingFresh($this->ngozi)
            ->get(route('documents.versions.show', [$mine, $otherVersion]))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ §75 tenancy

    #[Test]
    public function a_document_in_another_organization_does_not_exist(): void
    {
        $other = $this->makeOrganization('Kestrel Facilities');
        $otherUnit = $this->makeUnit($other, 'Operations', OrgUnitType::Department);

        $otherRole = $this->makeRole($other, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.manage' => 'organization',
            'document.download' => 'organization',
        ]);

        $rita = $this->makePerson($other, 'Rita Calder', $otherUnit, [$otherRole]);

        $theirCategory = $this->makeDocumentCategory($other, 'Procedures', ['requires_approval' => false]);

        $theirs = $this->makeDocument($other, $rita, 'Lift maintenance procedure', [
            'document_category_id' => $theirCategory->id,
            'org_unit_id' => $otherUnit->id,
        ]);

        // 404, not 403: outside the tenant the record is not there to be refused.
        $this->actingFresh($this->ngozi)->get(route('documents.show', $theirs))->assertNotFound();
        $this->actingFresh($this->ngozi)->get(route('documents.edit', $theirs))->assertNotFound();

        // Titles, not numbers. Both companies' first document is numbered DOC-<year>-0001.
        $this->actingFresh($this->ngozi)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertDontSee('Lift maintenance procedure');

        $this->actingFresh($rita)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertSee('Lift maintenance procedure')
            ->assertDontSee('Excavation and shoring procedure');
    }

    // ------------------------------------------------------------------ check-out

    #[Test]
    public function a_document_checked_out_by_somebody_else_cannot_be_edited(): void
    {
        $document = $this->civilProcedure();

        $this->actingFresh($this->ngozi)
            ->post(route('documents.check-out', $document))
            ->assertRedirect();

        $this->assertTrue($document->refresh()->isLocked());

        // A second controller in the same department, with identical permissions.
        $controllerRole = $this->org->roles()->where('name', 'Document Controller')->firstOrFail();
        $tobi = $this->makePerson($this->org, 'Tobi Adeleke', $this->civil, [$controllerRole]);

        $this->actingFresh($tobi)->get(route('documents.edit', $document))->assertForbidden();

        $this->actingFresh($tobi)
            ->post(route('documents.versions.store', $document), [
                'content_html' => '<p>Written over somebody else’s open draft.</p>',
            ])
            ->assertForbidden();

        // Checking it back in releases it, and the second person can then work.
        $this->actingFresh($this->ngozi)
            ->post(route('documents.check-in', $document))
            ->assertRedirect();

        $this->actingFresh($tobi)->get(route('documents.edit', $document->refresh()))->assertOk();
    }

    // ------------------------------------------------------------------ deletion

    #[Test]
    public function a_document_with_an_approved_revision_cannot_be_deleted_by_anybody(): void
    {
        $document = $this->civilProcedure();
        $this->makeNativeVersion($this->org, $document, $this->ngozi);

        // The register has no route, so submitting approves the draft outright.
        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertRedirect();

        $this->assertTrue(
            $document->refresh()->versions()->whereNotNull('approved_at')->exists(),
            'The fixture was supposed to leave an approved revision behind.',
        );

        $this->actingFresh($this->ngozi)
            ->delete(route('documents.destroy', $document))
            ->assertForbidden();

        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);

        // Archiving is the way to take it out of circulation, and it stays readable.
        $this->actingFresh($this->ngozi)
            ->post(route('documents.archive', $document), ['reason' => 'Superseded by the group standard.'])
            ->assertRedirect();

        $this->actingFresh($this->emeka)->get(route('documents.show', $document))->assertOk();
    }

    #[Test]
    public function a_draft_raised_by_mistake_can_still_be_deleted(): void
    {
        $document = $this->civilProcedure();

        $this->actingFresh($this->ngozi)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    // ------------------------------------------------------------------ creation

    #[Test]
    public function a_created_document_takes_its_tenant_number_and_author_from_the_server(): void
    {
        $this->actingFresh($this->ngozi)
            ->post(route('documents.store'), [
                'title' => 'Concrete pour checklist',
                'kind' => 'native',
                'confidentiality' => Confidentiality::Internal->value,
                'document_category_id' => $this->procedures->id,
                'org_unit_id' => $this->civil->id,

                // Everything below is a lie the form should be incapable of telling (§84).
                'organization_id' => 99,
                'number' => 'MADE-UP-0001',
                'created_by' => $this->yusuf->id,
            ])
            ->assertRedirect();

        $document = Document::withoutGlobalScopes()
            ->where('title', 'Concrete pour checklist')
            ->firstOrFail();

        $this->assertSame($this->org->id, (int) $document->organization_id);
        $this->assertSame((int) $this->ngozi->id, (int) $document->created_by);
        $this->assertNotSame('MADE-UP-0001', $document->number);
        $this->assertStringStartsWith('DOC-', $document->number);
    }

    // ------------------------------------------------------------------ fixture

    /** @param array<string,mixed> $attributes */
    private function civilProcedure(array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->ngozi, 'Excavation and shoring procedure', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
            ...$attributes,
        ]);
    }
}
