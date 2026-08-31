<?php

declare(strict_types=1);

namespace App\Engines\Document;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Template;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Writer\Word2007;

/**
 * Sends a document back out as .docx (the other half of the Word round trip).
 *
 * The header and footer are built HERE, from the Template and the document's own metadata, rather
 * than carried through from whatever was imported. That is the deliberate trade recorded on
 * DocxImporter: page furniture is the company's configuration, so a controlled document exported
 * from this system always carries the right title block, the right revision, and — the part people
 * actually rely on — an accurate "approved by / on".
 *
 * THE WATERMARK ON UNAPPROVED REVISIONS is not decoration. A drawing printed from a draft and left
 * on a site desk is one of the classic ways the wrong thing gets built. Every exported revision that
 * has not completed its approval route says so on every page, and that behaviour is not
 * configurable off.
 */
class DocxExporter
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /**
     * Render a version to .docx bytes.
     *
     * Returns the raw string rather than writing to disk, so the caller decides whether it is a
     * download, an attachment or a stored artefact — and so nothing lands in the vault that nobody
     * asked to keep.
     */
    public function render(DocumentVersion $version, ?Template $template = null): string
    {
        $this->configure();

        $document = $version->document;
        $phpWord = new PhpWord;

        $this->applyDefaults($phpWord);

        $section = $phpWord->addSection($this->sectionStyle($template));

        $this->addHeader($section, $document, $version, $template);
        $this->addFooter($section, $document, $version);

        $html = $version->content_html;

        if ($html === null || trim($html) === '') {
            $section->addText('This revision has no content.', ['italic' => true, 'color' => '888888']);
        } else {
            // Re-sanitized on the way OUT as well as in. Belt and braces on purpose: content_html
            // was cleaned when stored, but this method also renders content that arrived before a
            // sanitizer rule was tightened, and PhpWord's HTML parser is not a security boundary.
            $clean = (string) $this->sanitizer->clean($html);

            try {
                Html::addHtml($section, $clean, false, false);
            } catch (\Throwable) {
                // PhpWord's parser is stricter than a browser's and throws on markup it cannot map.
                // Falling back to the plain text keeps the export working — a person with the words
                // but not the formatting is far better served than a person with an error page.
                $section->addText($this->sanitizer->toPlainText($html));
            }
        }

        return $this->toString($phpWord);
    }

    public function filename(Document $document, DocumentVersion $version): string
    {
        $stem = preg_replace('/[^A-Za-z0-9 \-_.]/', '', $document->number.'-Rev'.$version->revision.'-'.$document->title);

        return mb_substr(trim((string) $stem) ?: 'document', 0, 120).'.docx';
    }

    // ------------------------------------------------------------------ page furniture

    /** @return array<string,mixed> */
    private function sectionStyle(?Template $template): array
    {
        $margins = $template?->marginSet() ?? ['top' => 20, 'right' => 18, 'bottom' => 20, 'left' => 18];

        // Width and height in centimetres, keyed by the template's page_size.
        [$widthCm, $heightCm] = match ($template?->page_size) {
            'A3' => [29.7, 42.0],
            'A5' => [14.8, 21.0],
            'Letter' => [21.59, 27.94],
            'Legal' => [21.59, 35.56],
            default => [21.0, 29.7],   // A4
        };

        $style = [
            'marginTop' => Converter::cmToTwip($margins['top'] / 10),
            'marginRight' => Converter::cmToTwip($margins['right'] / 10),
            'marginBottom' => Converter::cmToTwip($margins['bottom'] / 10),
            'marginLeft' => Converter::cmToTwip($margins['left'] / 10),
            'pageSizeW' => Converter::cmToTwip($widthCm),
            'pageSizeH' => Converter::cmToTwip($heightCm),
        ];

        if ($template?->isLandscape() === true) {
            $style['orientation'] = 'landscape';
            [$style['pageSizeW'], $style['pageSizeH']] = [$style['pageSizeH'], $style['pageSizeW']];
        }

        return $style;
    }

    private function addHeader(
        Section $section,
        Document $document,
        DocumentVersion $version,
        ?Template $template,
    ): void {
        $header = $section->addHeader();

        $table = $header->addTable(['borderSize' => 6, 'borderColor' => 'CCCCCC', 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => 'pct']);
        $table->addRow();

        $left = $table->addCell(6500);
        $left->addText(htmlspecialchars($document->title), ['bold' => true, 'size' => 11]);
        $left->addText(htmlspecialchars($document->category?->name ?? 'Document'), ['size' => 8, 'color' => '666666']);

        $right = $table->addCell(3000);
        $right->addText('Doc no: '.htmlspecialchars((string) $document->number), ['size' => 8], ['alignment' => Jc::END]);
        $right->addText('Revision: '.htmlspecialchars((string) $version->revision), ['size' => 8], ['alignment' => Jc::END]);
        $right->addText(
            $version->isApproved()
                ? 'Approved '.$version->approved_at?->format('j M Y')
                : 'NOT APPROVED',
            ['size' => 8, 'bold' => ! $version->isApproved(), 'color' => $version->isApproved() ? '2E7D32' : 'C62828'],
            ['alignment' => Jc::END],
        );

        if (! $version->isApproved()) {
            // A watermark proper needs a VML shape PhpWord does not expose portably, so this is a
            // banner instead. Less pretty, equally unmissable, and it survives printing — which is
            // the only place it actually matters.
            $header->addText(
                'UNCONTROLLED COPY — THIS REVISION HAS NOT BEEN APPROVED',
                ['bold' => true, 'color' => 'C62828', 'size' => 9],
                ['alignment' => Jc::CENTER, 'spaceBefore' => 60],
            );
        }
    }

    private function addFooter(
        Section $section,
        Document $document,
        DocumentVersion $version,
    ): void {
        $footer = $section->addFooter();

        $footer->addPreserveText(
            htmlspecialchars((string) $document->number).' Rev '.htmlspecialchars((string) $version->revision)
                .'    Page {PAGE} of {NUMPAGES}',
            ['size' => 8, 'color' => '888888'],
            ['alignment' => Jc::CENTER],
        );

        $footer->addText(
            'Printed '.now()->format('j M Y, H:i').'. Check the register for the current revision before use.',
            ['size' => 7, 'color' => '999999'],
            ['alignment' => Jc::CENTER],
        );
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Body typeface.
     *
     * Fixed rather than configurable, because a font is only honoured if the reader's machine has
     * it — a template offering a free choice would produce documents that look right on the author's
     * PC and substitute unpredictably everywhere else. Calibri 11 is Word's own default and is
     * present on every Windows and Office install this will land on.
     */
    private function applyDefaults(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);
    }

    private function toString(PhpWord $phpWord): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'docx_out_');

        if ($temp === false) {
            throw new \RuntimeException('A temporary file could not be created for the export.');
        }

        try {
            (new Word2007($phpWord))->save($temp);

            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
        }
    }

    private function configure(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \DomainException(
                'Word export is unavailable: this server does not have the PHP zip extension enabled.'
            );
        }

        $temp = storage_path('framework/cache/phpword');

        if (! is_dir($temp)) {
            @mkdir($temp, 0o775, true);
        }

        if (is_writable($temp)) {
            PhpWordSettings::setTempDir($temp);
        }
    }
}
