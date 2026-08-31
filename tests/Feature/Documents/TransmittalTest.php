<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Transmittals — the record of what was formally sent to whom, and when.
 *
 * This is the module that gets read out in a dispute, years later, by somebody who was not there.
 * That single fact decides every test below, because it means the register's job is not to be
 * convenient — it is to be true about a moment that has already passed and cannot be revisited.
 *
 *   · AN ITEM POINTS AT A REVISION, NEVER AT A DOCUMENT. "We sent you the shoring procedure" is
 *     worthless; "we sent you revision B of the shoring procedure on the 4th" is the whole point.
 *     If items pointed at documents, every transmittal in the register would silently start
 *     claiming it enclosed whatever the current revision happens to be today.
 *
 *   · ISSUING IS A ONE-WAY DOOR. Before it, a transmittal is a draft somebody is assembling; after
 *     it, it is a statement about something that left the building. So the tests here do not check
 *     that editing an issued transmittal is discouraged — they check it is refused, item by item,
 *     including the additions and removals that would change what the register says was enclosed.
 *
 *   · THE DATABASE REFUSES TO ORPHAN THE CLAIM. A revision named on an issued transmittal cannot be
 *     deleted, and that is enforced by a foreign key rather than by a policy, because a policy
 *     protects a screen and a constraint protects the record.
 *
 *   · ISSUING IS ITS OWN AUTHORITY. `transmittal.edit` is not `transmittal.issue`. Assembling a
 *     package of drawings and sending it to a client are different acts by different people in
 *     every document-control department that has ever worked, and collapsing them is how a draft
 *     goes out early.
 */
final class TransmittalTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private User $ngozi;    // document controller: assembles and issues

    private User $tunde;    // may assemble, may not issue

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.submit' => 'organization',
            'document.download' => 'organization',
            'document.manage' => 'organization',
            'org_unit.view' => 'organization',
            'document_category.view' => 'organization',
            'transmittal.view' => 'organization',
            'transmittal.create' => 'organization',
            'transmittal.edit' => 'organization',
            'transmittal.delete' => 'organization',
            'transmittal.issue' => 'organization',
            'transmittal.acknowledge' => 'organization',
            'transmittal.manage' => 'organization',
        ]);

        // Everything the controller has EXCEPT the authority to send. See the class docblock.
        $assembler = $this->makeRole($this->org, 'Project Engineer', [
            'document.view' => 'organization',
            'document.download' => 'organization',
            'org_unit.view' => 'organization',
            'transmittal.view' => 'organization',
            'transmittal.create' => 'organization',
            'transmittal.edit' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Bello', $this->civil, [$assembler]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
            'workflow_id' => null,
            'draft_revision_prefix' => null,
        ]);
    }

    // ================================================================== assembling

    #[Test]
    public function a_transmittal_is_assembled_as_a_draft_and_numbered_on_creation(): void
    {
        $transmittal = $this->draft();

        $this->assertTrue($transmittal->isDraft());
        $this->assertFalse($transmittal->isIssued());
        $this->assertNull($transmittal->issued_at);

        // Numbered when it is created, not when it is sent: a document controller refers to a
        // transmittal by number while still assembling it, and a number that appears later is a
        // number that was quoted in an email before it existed.
        $this->assertNotEmpty($transmittal->number);
    }

    #[Test]
    public function an_item_names_the_revision_that_was_sent_and_not_merely_the_document(): void
    {
        [$document, $revisionA] = $this->issuedProcedure();
        $transmittal = $this->draft();

        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.items.store', $transmittal), [
                'document_version_id' => $revisionA->id,
                'copies' => 2,
                'media' => 'electronic',
                'remarks' => 'Client copy.',
            ])->assertSessionHasNoErrors();

        $item = TransmittalItem::withoutGlobalScopes()
            ->where('transmittal_id', $transmittal->id)->firstOrFail();

        $this->assertSame((int) $revisionA->id, (int) $item->document_version_id);
        $this->assertSame(2, $item->copies);

        // The document then moves on. What was sent does not: the transmittal still names revision
        // A, because revision A is what the recipient has in their hands.
        $revisionB = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.0 m.</p>');
        $this->actingFresh($this->ngozi)->post(route('documents.submit', $document))->assertRedirect();

        $this->assertNotSame((int) $revisionB->id, (int) $item->refresh()->document_version_id);
        $this->assertSame((int) $revisionA->id, (int) $item->document_version_id);
        $this->assertSame('A', $revisionA->refresh()->revision);
        $this->assertSame('B', $revisionB->refresh()->revision);
    }

    #[Test]
    public function the_same_revision_cannot_be_listed_on_one_transmittal_twice(): void
    {
        [, $revision] = $this->issuedProcedure();
        $transmittal = $this->draft();

        $this->addItem($transmittal, $revision);

        // Enclosing the same revision twice is not a package of two things; it is a register entry
        // that says the recipient was sent something once and reads as if they were sent it twice.
        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.items.store', $transmittal), [
                'document_version_id' => $revision->id,
            ])->assertSessionHasErrors();

        $this->assertSame(1, TransmittalItem::withoutGlobalScopes()
            ->where('transmittal_id', $transmittal->id)->count());
    }

    #[Test]
    public function an_empty_transmittal_cannot_be_issued(): void
    {
        $transmittal = $this->draft();

        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.issue', $transmittal))
            ->assertSessionHasErrors('issue');

        $this->assertTrue($transmittal->refresh()->isDraft());
    }

    // ================================================================== the one-way door

    #[Test]
    public function issuing_is_its_own_authority_and_not_a_consequence_of_being_allowed_to_edit(): void
    {
        [, $revision] = $this->issuedProcedure();

        // TUNDE'S OWN DRAFT, and that detail is the module working as designed rather than a
        // convenience. An unissued transmittal is a draft, and drafts are reachable only by their
        // author, by an explicit share, or by the people who hold `transmittal.issue` — the same
        // rule that stops the managing director reading half-written memos. So Tunde cannot open
        // somebody else's draft at all, and the interesting question is what he may do with his.
        $transmittal = $this->draft(actor: $this->tunde);
        $this->addItem($transmittal, $revision, actor: $this->tunde);

        // He assembled it. He may add to it, remove from it, and rename it. He may not send it.
        $this->actingFresh($this->tunde)
            ->get(route('transmittals.edit', $transmittal))
            ->assertOk();

        $this->actingFresh($this->tunde)
            ->post(route('transmittals.issue', $transmittal))
            ->assertForbidden();

        $this->assertTrue($transmittal->refresh()->isDraft());

        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.issue', $transmittal))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transmittals.show', $transmittal));

        $transmittal->refresh();

        $this->assertTrue($transmittal->isIssued());
        $this->assertNotNull($transmittal->issued_at);
        $this->assertSame((int) $this->ngozi->id, (int) $transmittal->issued_by);
    }

    #[Test]
    public function an_issued_transmittal_can_no_longer_be_edited_or_have_its_contents_changed(): void
    {
        [, $revisionA] = $this->issuedProcedure();
        [, $revisionOther] = $this->issuedProcedure('Lifting operations procedure');

        $transmittal = $this->draft();
        $item = $this->addItem($transmittal, $revisionA);

        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.issue', $transmittal))
            ->assertSessionHasNoErrors();

        // The record now describes something that has left the building. Every route that would
        // change what it says was enclosed is refused — not hidden in the view, refused server-side.
        $this->actingFresh($this->ngozi)
            ->put(route('transmittals.update', $transmittal), $this->payload(['subject' => 'Rewritten after the fact']))
            ->assertForbidden();

        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.items.store', $transmittal), ['document_version_id' => $revisionOther->id])
            ->assertForbidden();

        $this->actingFresh($this->ngozi)
            ->delete(route('transmittals.items.destroy', [$transmittal, $item]))
            ->assertForbidden();

        $this->actingFresh($this->ngozi)
            ->delete(route('transmittals.destroy', $transmittal))
            ->assertForbidden();

        $this->assertSame(1, TransmittalItem::withoutGlobalScopes()
            ->where('transmittal_id', $transmittal->id)->count());
        $this->assertNotSame('Rewritten after the fact', $transmittal->refresh()->subject);
    }

    #[Test]
    public function a_revision_that_has_been_transmitted_cannot_be_deleted_out_from_under_the_record(): void
    {
        [, $revision] = $this->issuedProcedure();
        $transmittal = $this->draft();
        $this->addItem($transmittal, $revision);

        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.issue', $transmittal))
            ->assertSessionHasNoErrors();

        // Enforced by the foreign key, so it holds against a console command, a future service and
        // a hand-written query alike. A policy protects a screen; a constraint protects the record.
        $this->expectException(QueryException::class);

        DB::table('document_versions')->where('id', $revision->id)->delete();
    }

    // ================================================================== acknowledgement

    #[Test]
    public function receipt_is_recorded_by_somebody_inside_on_behalf_of_somebody_outside(): void
    {
        [, $revision] = $this->issuedProcedure();
        $transmittal = $this->draft(['requires_acknowledgement' => '1']);
        $this->addItem($transmittal, $revision);

        // Nothing to acknowledge before it was sent.
        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.acknowledge', $transmittal), ['acknowledged_by_name' => 'Too early'])
            ->assertForbidden();

        $this->actingFresh($this->ngozi)->post(route('transmittals.issue', $transmittal));

        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.acknowledge', $transmittal), [
                'acknowledged_by_name' => 'A. Client (Meridian Client Services)',
                'acknowledgement_reference' => 'Email 4 Aug 2026, 14:12',
            ])->assertSessionHasNoErrors();

        $transmittal->refresh();

        $this->assertTrue($transmittal->isAcknowledged());
        $this->assertNotNull($transmittal->acknowledged_at);

        // Free text with no account behind it, which is the honest record: somebody in document
        // control had a signed receipt in hand and wrote down who sent it. Pretending the external
        // party clicked something would be recording a fiction.
        $this->assertStringContainsString('A. Client', (string) $transmittal->acknowledged_by_name);

        // And it happens once. A second recording would overwrite the date receipt was actually
        // confirmed, which is the one date the register exists to hold.
        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.acknowledge', $transmittal), ['acknowledged_by_name' => 'Somebody else'])
            ->assertForbidden();
    }

    // ================================================================== the cover sheet

    #[Test]
    public function the_cover_sheet_is_watermarked_while_it_is_still_a_draft(): void
    {
        [, $revision] = $this->issuedProcedure();
        $transmittal = $this->draft();
        $this->addItem($transmittal, $revision);

        $draftSheet = $this->actingFresh($this->ngozi)
            ->get(route('transmittals.cover-sheet', $transmittal))
            ->assertOk();

        $this->assertSame('application/pdf', $draftSheet->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', substr($draftSheet->getContent(), 0, 4));

        $this->actingFresh($this->ngozi)->post(route('transmittals.issue', $transmittal));

        $issuedSheet = $this->actingFresh($this->ngozi)
            ->get(route('transmittals.cover-sheet', $transmittal))
            ->assertOk();

        // The two sheets differ, because the draft carries a DRAFT watermark and the issued one
        // does not. A cover sheet that looked identical either way is a cover sheet somebody
        // eventually sends before issuing.
        $this->assertNotSame($draftSheet->getContent(), $issuedSheet->getContent());
        $this->assertStringStartsWith('%PDF', substr($issuedSheet->getContent(), 0, 4));
    }

    // ================================================================== §75 / §84

    #[Test]
    public function a_transmittal_from_another_organization_is_not_found(): void
    {
        [, $revision] = $this->issuedProcedure();
        $transmittal = $this->draft();
        $this->addItem($transmittal, $revision);

        $other = $this->makeOrganization('Kestrel Infrastructure');
        $otherUnit = $this->makeUnit($other, 'Projects', OrgUnitType::Department);
        $intruder = $this->makePerson($other, 'Sade Ajayi', $otherUnit, [
            $this->makeRole($other, 'Controller', [
                'transmittal.view' => 'organization',
                'transmittal.edit' => 'organization',
                'transmittal.issue' => 'organization',
                'document.view' => 'organization',
            ]),
        ]);

        foreach (['transmittals.show', 'transmittals.cover-sheet'] as $name) {
            $this->actingFresh($intruder)->get(route($name, $transmittal))->assertNotFound();
        }

        $this->actingFresh($intruder)
            ->post(route('transmittals.issue', $transmittal))
            ->assertNotFound();

        $this->actingFresh($intruder)
            ->get(route('transmittals.index'))
            ->assertOk()
            ->assertDontSee('Excavation and shoring procedure');

        $this->assertTrue($transmittal->refresh()->isDraft());
    }

    #[Test]
    public function a_revision_from_another_organization_cannot_be_added_to_this_one(): void
    {
        $other = $this->makeOrganization('Kestrel Infrastructure');
        $otherUnit = $this->makeUnit($other, 'Projects', OrgUnitType::Department);
        $otherAuthor = $this->makePerson($other, 'Sade Ajayi', $otherUnit, [
            $this->makeRole($other, 'Author', [
                'document.view' => 'organization',
                'document.create' => 'organization',
                'document.edit' => 'organization',
                'document.submit' => 'organization',
                'org_unit.view' => 'organization',
                'document_category.view' => 'organization',
            ]),
        ]);
        $otherCategory = $this->makeDocumentCategory($other, 'Procedures', [
            'requires_approval' => false,
            'workflow_id' => null,
        ]);
        $foreign = $this->makeDocument($other, $otherAuthor, 'Kestrel lifting plan', [
            'document_category_id' => $otherCategory->id,
            'org_unit_id' => $otherUnit->id,
        ]);
        $foreignRevision = $this->makeNativeVersion($other, $foreign, $otherAuthor, '<p>Not ours.</p>');

        $transmittal = $this->draft();

        // A real id, of a real revision, posted by somebody entitled to add items — and it is
        // rejected at validation, because `existsInTenant` scopes the lookup rather than trusting
        // that the id came from a picker this person was shown (§84).
        $this->actingFresh($this->ngozi)
            ->post(route('transmittals.items.store', $transmittal), [
                'document_version_id' => $foreignRevision->id,
            ])->assertSessionHasErrors('document_version_id');

        $this->assertSame(0, TransmittalItem::withoutGlobalScopes()
            ->where('transmittal_id', $transmittal->id)->count());
    }

    // ================================================================== fixture

    /**
     * A draft transmittal created through the form, addressed to a party with no account.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function draft(array $overrides = [], ?User $actor = null): Transmittal
    {
        $this->actingFresh($actor ?? $this->ngozi)
            ->post(route('transmittals.store'), $this->payload($overrides))
            ->assertRedirect();

        return Transmittal::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'subject' => 'Excavation and shoring procedure — issue for construction',
            'org_unit_id' => $this->civil->id,
            'to_name' => 'Meridian Client Services',
            'to_attention' => 'A. Client',
            'to_email' => 'documents@client.test',
            'purpose' => 'for_construction',
            'transport' => 'email',
            'confidentiality' => 'internal',
            'cover_note' => 'Please confirm receipt.',
            'requires_acknowledgement' => '0',
            ...$overrides,
        ];
    }

    private function addItem(Transmittal $transmittal, DocumentVersion $version, ?User $actor = null): TransmittalItem
    {
        $this->actingFresh($actor ?? $this->ngozi)
            ->post(route('transmittals.items.store', $transmittal), [
                'document_version_id' => $version->id,
                'media' => 'electronic',
            ])->assertRedirect();

        return TransmittalItem::withoutGlobalScopes()
            ->where('transmittal_id', $transmittal->id)
            ->where('document_version_id', $version->id)
            ->firstOrFail();
    }

    /**
     * A document with one approved, current revision — the only kind that has any business being
     * enclosed in a formal issue.
     *
     * @return array{0: Document, 1: DocumentVersion}
     */
    private function issuedProcedure(string $title = 'Excavation and shoring procedure'): array
    {
        $document = $this->makeDocument($this->org, $this->ngozi, $title, [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
        ]);

        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        // The category requires no approval, so submitting issues it there and then. Asserted as a
        // REDIRECT rather than merely "no session errors": a 403 also has no session errors, and a
        // fixture that quietly failed authorization would hand every test below an unapproved
        // revision while still going green.
        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertRedirect();

        return [$document->refresh(), $version->refresh()];
    }
}
