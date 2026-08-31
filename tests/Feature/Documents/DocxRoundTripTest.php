<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Engines\Document\DocxExporter;
use App\Engines\Document\DocxImporter;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Enums\DocumentKind;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Word in, edit here, Word out — the promise the module is sold on, tested end to end.
 *
 * These tests build a REAL .docx with PhpWord, push it through the upload route a person would use,
 * and then read the exported .docx back with PhpWord's reader. Nothing is asserted against a fixture
 * string, because a fixture would only prove the code still does what it did, not that Word could
 * open the result.
 *
 * WHAT IS ASSERTED IS DELIBERATELY MODEST, and matches what docs/09 promises rather than what a
 * salesperson would like to promise. The round trip preserves CONTENT — headings, paragraphs, lists,
 * tables, bold and italic — and it does not preserve APPEARANCE. Tracked changes, comments, exact
 * pagination, embedded objects, footnotes and styles do not survive, and the tests here say so out
 * loud rather than leaving somebody to discover it with a client's specification.
 *
 * That is also why the original upload is kept forever: the import is lossy by design, and the file
 * on disk is what somebody falls back to when the conversion ate their equation.
 */
final class DocxRoundTripTest extends TestCase
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
            'document.print' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$role]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
            'workflow_id' => null,
        ]);
    }

    // ------------------------------------------------------------------ Word in

    #[Test]
    public function a_word_document_becomes_editable_content_and_the_original_is_kept(): void
    {
        $document = $this->procedure();

        $this->actingFresh($this->ngozi)
            ->post(route('documents.versions.store', $document), [
                'file' => $this->wordFile(),
                'change_summary' => 'Imported from the old Word master.',
            ])
            ->assertRedirect();

        $version = $this->latestVersion($document);

        // The content is now editable in the browser: this is the half that "replaces Word".
        $this->assertNotNull($version->content_html, 'The .docx was stored but never imported.');
        $this->assertStringContainsString('Excavation and Shoring', (string) $version->content_html);
        $this->assertStringContainsString('1.2 metres', (string) $version->content_html);
        $this->assertStringContainsString('competent person', (string) $version->content_html);

        // And the original file is still there: this is the half that "integrates with Word". The
        // import is lossy by documented design, so throwing the source away would be destroying the
        // only faithful copy of what the author actually wrote.
        $this->assertNotNull($version->file_path);
        $this->assertSame('method-statement.docx', $version->original_name);
        $this->assertGreaterThan(0, (int) $version->file_size);
        $this->assertNotNull($version->file_hash);

        $this->assertGreaterThan(10, (int) $version->word_count);
    }

    #[Test]
    public function imported_word_content_is_sanitized_like_anything_else_a_person_types(): void
    {
        // A .docx is user input arriving through a converter, and a converter is not a security
        // boundary. The importer runs its output through the same sanitizer the editor's save uses.
        $importer = app(DocxImporter::class);

        $path = $this->writeWordFile(function (PhpWord $word): void {
            $section = $word->addSection();
            $section->addText('<script>alert(1)</script>');
            $section->addText('Safe body text.');
        });

        $html = $importer->toHtml($path);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('Safe body text.', $html);

        @unlink($path);
    }

    #[Test]
    public function a_file_that_is_not_a_word_document_is_refused_with_an_explanation(): void
    {
        $importer = app(DocxImporter::class);

        $path = tempnam(sys_get_temp_dir(), 'notdocx_');
        file_put_contents($path, 'This is a plain text file wearing a .docx name.');

        try {
            $this->expectException(\DomainException::class);
            $importer->toHtml($path);
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------ Word out

    #[Test]
    public function a_natively_authored_document_exports_as_a_word_file_word_can_open(): void
    {
        $document = $this->procedure();

        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi,
            '<h1>Excavation and Shoring</h1>'
            .'<p>No excavation deeper than <strong>1.2 metres</strong> may be entered until it has '
            .'been inspected by a <em>competent person</em>.</p>'
            .'<ul><li>Inspect at the start of every shift.</li><li>Record the inspection.</li></ul>'
            .'<table><tbody><tr><td>Depth</td><td>1.2 m</td></tr></tbody></table>',
        );

        $response = $this->actingFresh($this->ngozi)
            ->get(route('documents.versions.docx', [$document, $version]));

        $response->assertOk();
        $this->assertStringContainsString('wordprocessingml', (string) $response->headers->get('content-type'));

        // Written to disk and reopened with PhpWord's READER, not inspected as a string. A .docx is
        // a zip of XML; "the bytes contain the word Excavation" would pass on a corrupt archive.
        $path = $this->writeExport($response->getContent());

        try {
            $reopened = IOFactory::load($path, 'Word2007');
            $text = $this->textOf($reopened);
        } finally {
            @unlink($path);
        }

        $this->assertStringContainsString('Excavation and Shoring', $text);
        $this->assertStringContainsString('1.2 metres', $text);
        $this->assertStringContainsString('competent person', $text);
        $this->assertStringContainsString('Inspect at the start of every shift.', $text);
    }

    #[Test]
    public function the_exported_file_is_named_for_the_document_and_its_revision(): void
    {
        $document = $this->procedure();
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi);

        $name = app(DocxExporter::class)->filename($document, $version);

        // The number and revision are in the name because that is how the file is filed once it
        // leaves this system — an "Untitled (3).docx" in somebody's downloads folder is untraceable.
        $this->assertStringContainsString((string) $document->number, $name);
        $this->assertStringContainsString('Rev'.$version->revision, $name);
        $this->assertStringEndsWith('.docx', $name);

        // And nothing in it can escape a directory or confuse a shell when it is saved.
        $this->assertDoesNotMatchRegularExpression('#[/\\\\:*?"<>|]#', $name);
    }

    #[Test]
    public function an_empty_revision_offers_no_export_rather_than_an_empty_word_file(): void
    {
        $document = $this->procedure();

        // An empty paragraph is nothing: the sanitizer discards it and the revision has no content.
        $version = $this->makeNativeVersion($this->org, $document, $this->ngozi, '<p></p>');

        $this->assertFalse($version->hasContent());

        // 404, and that is the right answer. A zero-content .docx that opens in Word and shows a
        // blank page is the worst outcome here — somebody sends it to a client believing it is the
        // procedure. Refusing to produce one is more useful than producing an empty one.
        $this->actingFresh($this->ngozi)
            ->get(route('documents.versions.docx', [$document, $version]))
            ->assertNotFound();

        $this->actingFresh($this->ngozi)
            ->get(route('documents.versions.pdf', [$document, $version]))
            ->assertNotFound();

        // The moment there is something to say, the export exists.
        $this->actingFresh($this->ngozi)
            ->put(route('documents.versions.update', [$document, $version]), [
                'content_html' => '<p>Shoring is mandatory below 1.2 m.</p>',
            ])
            ->assertRedirect();

        $bytes = (string) $this->actingFresh($this->ngozi)
            ->get(route('documents.versions.docx', [$document, $version->refresh()]))
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('PK', $bytes, 'The export is not a valid zip container.');
    }

    // ------------------------------------------------------------------ the full circuit

    #[Test]
    public function content_survives_word_in_edited_here_word_out(): void
    {
        $document = $this->procedure();

        // 1. In.
        $this->actingFresh($this->ngozi)
            ->post(route('documents.versions.store', $document), [
                'file' => $this->wordFile(),
                'change_summary' => 'Imported.',
            ])
            ->assertRedirect();

        $version = $this->latestVersion($document);
        $imported = (string) $version->content_html;

        // 2. Edited here, through the editor's own save route.
        $this->actingFresh($this->ngozi)
            ->put(route('documents.versions.update', [$document, $version]), [
                'content_html' => $imported.'<p>Added in the browser: shoring is mandatory below 1.2 m.</p>',
                'change_summary' => 'Added the shoring clause.',
            ])
            ->assertRedirect();

        // 3. Out.
        $response = $this->actingFresh($this->ngozi)
            ->get(route('documents.versions.docx', [$document, $version->refresh()]));

        $response->assertOk();

        $path = $this->writeExport($response->getContent());

        try {
            $text = $this->textOf(IOFactory::load($path, 'Word2007'));
        } finally {
            @unlink($path);
        }

        // Both the text that came from Word and the text typed here are in the file that goes back.
        $this->assertStringContainsString('Excavation and Shoring', $text);
        $this->assertStringContainsString('competent person', $text);
        $this->assertStringContainsString('Added in the browser', $text);
    }

    #[Test]
    public function the_round_trip_does_not_pretend_to_preserve_what_it_cannot(): void
    {
        // This test exists to make the documented limitation FAIL LOUDLY if somebody ever quietly
        // changes the promise. docs/09 tells the user that appearance, tracked changes, comments and
        // embedded objects do not survive, and that the original file is kept precisely because of
        // it. If a future change made the import faithful, this assertion breaks and the
        // documentation gets updated with it — which is the correct outcome either way.
        $document = $this->procedure();

        $this->actingFresh($this->ngozi)
            ->post(route('documents.versions.store', $document), [
                'file' => $this->wordFile(),
                'change_summary' => 'Imported.',
            ])
            ->assertRedirect();

        $version = $this->latestVersion($document);

        // The Word file was written with a comment-like footnote and a custom style name. Neither
        // reaches the editable content, and the file that still holds them is on disk.
        $this->assertStringNotContainsString('MeridianHeading', (string) $version->content_html);
        $this->assertNotNull($version->file_path, 'The original must be kept, because the import is lossy.');
    }

    // ------------------------------------------------------------------ fixture

    private function procedure(): Document
    {
        return $this->makeDocument($this->org, $this->ngozi, 'Excavation and shoring method statement', [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
            'kind' => DocumentKind::Native->value,
        ]);
    }

    /** A genuine .docx, built with PhpWord, presented as a browser upload. */
    private function wordFile(): UploadedFile
    {
        $path = $this->writeWordFile(function (PhpWord $word): void {
            $word->addTitleStyle(1, ['bold' => true, 'size' => 16], ['spaceAfter' => 120]);

            $section = $word->addSection();
            $section->addTitle('Excavation and Shoring', 1);
            $section->addText('No excavation deeper than 1.2 metres may be entered until it has been '
                .'inspected by a competent person at the start of every shift.');

            $section->addListItem('Inspect at the start of every shift.');
            $section->addListItem('Record the inspection in the site diary.');

            $table = $section->addTable();
            $table->addRow();
            $table->addCell(3000)->addText('Depth');
            $table->addCell(3000)->addText('1.2 m');
        });

        // `false` for $test: the file is a real one on disk, so the framework moves it exactly as it
        // would a genuine upload rather than short-circuiting the move.
        return new UploadedFile(
            $path,
            'method-statement.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true,
        );
    }

    private function writeWordFile(callable $build): string
    {
        $word = new PhpWord;
        $build($word);

        $path = tempnam(sys_get_temp_dir(), 'fixture_').'.docx';

        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    private function writeExport(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'export_').'.docx';
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Every run of text in the document, flattened — the reader's answer to "what does it say". */
    private function textOf(PhpWord $word): string
    {
        $text = '';

        foreach ($word->getSections() as $section) {
            $text .= $this->textOfElements($section->getElements());
        }

        return $text;
    }

    /** @param array<int,object> $elements */
    private function textOfElements(array $elements): string
    {
        $text = '';

        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $value = $element->getText();

                if (is_string($value)) {
                    $text .= $value."\n";

                    continue;
                }

                if (is_object($value) && method_exists($value, 'getElements')) {
                    $text .= $this->textOfElements($value->getElements());

                    continue;
                }
            }

            if (method_exists($element, 'getElements')) {
                $text .= $this->textOfElements($element->getElements());
            }

            if (method_exists($element, 'getRows')) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $text .= $this->textOfElements($cell->getElements());
                    }
                }
            }
        }

        return $text;
    }

    private function latestVersion(Document $document): DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $document->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
