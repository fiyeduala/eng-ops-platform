<?php

declare(strict_types=1);

namespace App\Engines\Document;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings as PhpWordSettings;

/**
 * Turns a .docx into editable content (the "integrate with Word" half of the module).
 *
 * WHAT THIS HONESTLY DOES: reads the document body — headings, paragraphs, runs and their basic
 * formatting, lists, tables, links — and produces clean HTML that the editor can open and a person
 * can then work on inside this system.
 *
 * WHAT IT HONESTLY DOES NOT, and every one of these should be said out loud rather than discovered:
 *
 *   - TRACKED CHANGES AND COMMENTS are dropped. Word stores them as revision marks around runs;
 *     HTML has no equivalent, so a round trip would silently accept the revisions. Dropping them
 *     visibly is better than accepting them invisibly.
 *   - HEADERS, FOOTERS AND PAGE NUMBERS are dropped, because in this system they come from the
 *     Template — which is the point. A controlled document should get its title block from the
 *     company's configuration, not from whatever the author's copy of Word had.
 *   - EMBEDDED OBJECTS (charts, OLE, SmartArt, equations) do not survive. They are not markup.
 *   - EXACT PAGINATION does not survive, and cannot: HTML has no pages.
 *
 * So the contract offered to the user is: bring your Word content in, keep working on it here, send
 * it back out as .docx. It is not, and is not sold as, a byte-faithful Word round trip.
 *
 * WHY THE FILE IS ALWAYS KEPT. Even after a successful import, DocumentVersionService stores the
 * original .docx alongside the extracted HTML. If the conversion lost something the author cared
 * about, the original is still there to download — which turns a lossy import from a data-loss
 * event into an inconvenience.
 */
class DocxImporter
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /**
     * Read a .docx and return sanitized body HTML.
     *
     * @throws \DomainException when the file is not a readable Word document
     */
    public function toHtml(string $absolutePath): string
    {
        if (! is_readable($absolutePath)) {
            throw new \DomainException('That Word file could not be read.');
        }

        $this->configurePhpWord();

        try {
            $phpWord = IOFactory::load($absolutePath, 'Word2007');
        } catch (\Throwable $e) {
            throw new \DomainException(
                'That file could not be opened as a Word document. It may be a .doc rather than a .docx, '
                .'or it may be password protected.',
                previous: $e,
            );
        }

        $temp = tempnam(sys_get_temp_dir(), 'docx_html_');

        if ($temp === false) {
            throw new \RuntimeException('A temporary file could not be created for the conversion.');
        }

        try {
            IOFactory::createWriter($phpWord, 'HTML')->save($temp);
            $html = (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
        }

        return (string) $this->sanitizer->clean($this->bodyOf($html));
    }

    public function fromUpload(UploadedFile $file): string
    {
        return $this->toHtml($file->getRealPath());
    }

    public function isDocx(UploadedFile $file): bool
    {
        return mb_strtolower($file->getClientOriginalExtension()) === 'docx';
    }

    /**
     * Pull the body out of PhpWord's full HTML document.
     *
     * PhpWord emits a complete page with a `<style>` block. The style block must go — it is exactly
     * the construct the sanitizer forbids, and the document's appearance is the Template's job. The
     * sanitizer would strip it anyway; removing it here means the sanitizer is a backstop rather
     * than load-bearing for the common path.
     */
    private function bodyOf(string $html): string
    {
        $html = (string) preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);

        if (preg_match('#<body\b[^>]*>(.*?)</body>#is', $html, $matches) === 1) {
            $html = $matches[1];
        }

        return trim($html);
    }

    /**
     * PhpWord defaults that matter on shared hosting.
     *
     * Zip archive handling is explicit because some cPanel builds ship without ZipArchive, and the
     * failure without this is an obscure fatal rather than a message anybody can act on. The temp
     * directory is pinned inside storage/ for the same reason FileVault does it: the system default
     * is not reliably writable there.
     */
    private function configurePhpWord(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \DomainException(
                'Word import is unavailable: this server does not have the PHP zip extension enabled.'
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
