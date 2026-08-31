<?php

declare(strict_types=1);

namespace App\Engines\Document;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Template;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a document, a transmittal or any template to PDF (§66).
 *
 * Dompdf rather than a headless browser, for one reason that outranks every quality argument: §1
 * says the primary deployment target is cPanel, where you cannot install Chromium and cannot run a
 * long-lived Node process. A renderer that produces beautiful PDFs on a VPS and nothing at all on
 * the target platform is not a renderer.
 *
 * The cost is real and worth stating: Dompdf supports a subset of CSS 2.1. Flexbox and grid do
 * nothing. Layout is tables and floats, which is why the stylesheet below looks like 2008.
 *
 * SECURITY: `isRemoteEnabled` stays FALSE. With it on, a `<img src="http://internal-host/...">` in
 * a document would be fetched BY THE SERVER — server-side request forgery, from inside the network,
 * triggered by anybody who can type in the editor. Local images are supported through `data:` URIs
 * instead, which need no network at all.
 *
 * `isPhpEnabled` also stays FALSE. Dompdf can execute PHP inside `<script type="text/php">`, which
 * would turn document content into remote code execution.
 */
class PdfRenderer
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /** Render one revision, with its title block, watermark and approval record. */
    public function renderVersion(DocumentVersion $version, ?Template $template = null): string
    {
        $version->loadMissing(['document.category', 'author', 'approver']);
        $document = $version->document;

        return $this->render(
            $this->page(
                title: $document->title,
                body: (string) $this->sanitizer->clean($version->content_html ?? '<p><em>This revision has no content.</em></p>'),
                header: $this->documentHeader($document, $version),
                footer: $this->documentFooter($document, $version),
                watermark: $version->isApproved() ? null : 'UNCONTROLLED COPY',
                template: $template,
            ),
            $template,
        );
    }

    /** Render arbitrary already-sanitized HTML — used by transmittals and template previews. */
    public function renderHtml(
        string $title,
        string $bodyHtml,
        ?string $headerHtml = null,
        ?string $footerHtml = null,
        ?Template $template = null,
        ?string $watermark = null,
    ): string {
        return $this->render(
            $this->page($title, $bodyHtml, $headerHtml, $footerHtml, $watermark, $template),
            $template,
        );
    }

    public function filename(Document $document, DocumentVersion $version): string
    {
        $stem = preg_replace('/[^A-Za-z0-9 \-_.]/', '', $document->number.'-Rev'.$version->revision.'-'.$document->title);

        return mb_substr(trim((string) $stem) ?: 'document', 0, 120).'.pdf';
    }

    // ------------------------------------------------------------------ dompdf

    private function render(string $html, ?Template $template): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);        // no SSRF from document content
        $options->set('isPhpEnabled', false);           // no code execution from document content
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');    // ships with Dompdf; has the accented glyphs
        $options->set('chroot', storage_path());
        $options->set('tempDir', $this->tempDir());
        $options->set('fontDir', $this->fontDir());
        $options->set('fontCache', $this->fontDir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper(
            $template?->page_size ?: 'A4',
            $template?->isLandscape() === true ? 'landscape' : 'portrait',
        );
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The whole page, assembled.
     *
     * `@page` margins plus `position: fixed` header and footer is how a repeating title block is
     * done in Dompdf — there is no running-element support, so the fixed blocks are painted onto
     * every page and the body is inset to clear them.
     */
    private function page(
        string $title,
        string $body,
        ?string $header,
        ?string $footer,
        ?string $watermark,
        ?Template $template,
    ): string {
        $margins = $template?->marginSet() ?? ['top' => 20, 'right' => 18, 'bottom' => 20, 'left' => 18];

        $css = $this->stylesheet($margins);
        $safeTitle = e($title);

        $watermarkHtml = $watermark === null
            ? ''
            : '<div class="watermark">'.e($watermark).'</div>';

        $headerHtml = $header === null ? '' : '<div class="page-header">'.$header.'</div>';
        $footerHtml = $footer === null ? '' : '<div class="page-footer">'.$footer.'</div>';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="UTF-8"><title>{$safeTitle}</title><style>{$css}</style></head>
        <body>{$watermarkHtml}{$headerHtml}{$footerHtml}<main>{$body}</main></body></html>
        HTML;
    }

    /**
     * @param  array{top:int,right:int,bottom:int,left:int}  $margins
     */
    private function stylesheet(array $margins): string
    {
        $top = $margins['top'] + 14;      // room for the fixed header
        $bottom = $margins['bottom'] + 12; // room for the fixed footer

        return <<<CSS
        @page { margin: {$top}mm {$margins['right']}mm {$bottom}mm {$margins['left']}mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2937; }
        .page-header { position: fixed; top: -12mm; left: 0; right: 0; height: 12mm;
                       border-bottom: 0.5pt solid #cbd5e1; padding-bottom: 2mm; font-size: 8pt; color: #475569; }
        .page-footer { position: fixed; bottom: -10mm; left: 0; right: 0; height: 10mm;
                       border-top: 0.5pt solid #cbd5e1; padding-top: 2mm; font-size: 7.5pt; color: #64748b;
                       text-align: center; }
        .page-header table, .page-footer table { width: 100%; border-collapse: collapse; }
        .page-header td { vertical-align: top; }
        .page-header .right { text-align: right; }
        .watermark { position: fixed; top: 42%; left: 0; right: 0; text-align: center;
                     font-size: 46pt; font-weight: bold; color: #f1d4d4; z-index: -1; letter-spacing: 4pt; }
        main { }
        h1 { font-size: 15pt; margin: 0 0 4mm; } h2 { font-size: 12.5pt; margin: 6mm 0 2mm; }
        h3 { font-size: 11pt; margin: 5mm 0 2mm; } h4, h5, h6 { font-size: 10pt; margin: 4mm 0 2mm; }
        p { margin: 0 0 3mm; } ul, ol { margin: 0 0 3mm 6mm; padding: 0; } li { margin-bottom: 1mm; }
        table { border-collapse: collapse; width: 100%; margin: 0 0 4mm; page-break-inside: auto; }
        th, td { border: 0.5pt solid #cbd5e1; padding: 1.6mm 2mm; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: bold; }
        tr { page-break-inside: avoid; }
        blockquote { margin: 0 0 3mm 4mm; padding-left: 3mm; border-left: 2pt solid #cbd5e1; color: #475569; }
        pre, code { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; }
        pre { background: #f8fafc; padding: 2mm; border: 0.5pt solid #e2e8f0; white-space: pre-wrap; }
        img { max-width: 100%; }
        a { color: #1d4ed8; text-decoration: none; }
        .muted { color: #64748b; } .danger { color: #b91c1c; } .ok { color: #15803d; }
        CSS;
    }

    // ------------------------------------------------------------------ title blocks

    private function documentHeader(Document $document, DocumentVersion $version): string
    {
        $status = $version->isApproved()
            ? '<span class="ok">Approved '.e((string) $version->approved_at?->format('j M Y')).'</span>'
            : '<span class="danger">Not approved</span>';

        return '<table><tr>'
            .'<td><strong>'.e($document->title).'</strong><br>'.e($document->category?->name ?? 'Document').'</td>'
            .'<td class="right">Doc no: '.e((string) $document->number).'<br>'
            .'Revision: '.e((string) $version->revision).'<br>'.$status.'</td>'
            .'</tr></table>';
    }

    private function documentFooter(Document $document, DocumentVersion $version): string
    {
        return e($document->number).' Rev '.e((string) $version->revision)
            .' &nbsp;|&nbsp; Printed '.now()->format('j M Y, H:i')
            .' &nbsp;|&nbsp; Check the register for the current revision before use.';
    }

    // ------------------------------------------------------------------ writable paths

    /**
     * Dompdf needs somewhere to write, and on cPanel the system temp directory frequently is not it.
     *
     * Pointing both the scratch directory and the font cache inside storage/ is what makes PDF
     * generation work on the deployment target rather than only on a developer's machine.
     */
    private function tempDir(): string
    {
        return $this->ensure(storage_path('framework/cache/dompdf'));
    }

    private function fontDir(): string
    {
        return $this->ensure(storage_path('framework/cache/dompdf/fonts'));
    }

    private function ensure(string $path): string
    {
        if (! is_dir($path)) {
            @mkdir($path, 0o775, true);
        }

        return is_writable($path) ? $path : sys_get_temp_dir();
    }
}
