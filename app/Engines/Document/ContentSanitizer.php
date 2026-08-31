<?php

declare(strict_types=1);

namespace App\Engines\Document;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * The one place authored markup is cleaned before it is stored (§67, §84).
 *
 * `document_versions.content_html` is the single field in this application where user input is
 * deliberately kept AS MARKUP and rendered back as markup. Everywhere else, Blade's escaping is the
 * whole defence. Here it is switched off by necessity, which makes this class the entire XSS
 * boundary for the module.
 *
 * FOUR RULES, and each exists because of a specific way this goes wrong:
 *
 *   1. SANITIZE ON THE WAY IN, not on the way out. Output-time filtering means every future
 *      renderer — the editor, the PDF, the .docx export, a report, an email — has to remember to
 *      call it, and the one that forgets is the vulnerability. Storing only clean markup means the
 *      database itself can be trusted.
 *
 *   2. A WHITELIST OF ELEMENTS, never a blacklist of dangerous ones. `<script>` is the obvious
 *      attack and the least interesting; `onerror`, `javascript:` hrefs, `<style>` with
 *      `expression()`, SVG event handlers and `<iframe>` are all bypasses of anybody's blacklist.
 *      HTMLPurifier works from a whitelist and a real HTML grammar, which is why it is a dependency
 *      rather than a regex in this file.
 *
 *   3. NO REMOTE RESOURCES. Images are allowed only as `data:` URIs or paths under this
 *      application. An `<img src="https://someone-else/beacon.png">` in an approved procedure is a
 *      read receipt that fires every time a controlled document is opened, and tells a third party
 *      who read what and when.
 *
 *   4. THE CONFIGURATION IS CACHED TO A WRITABLE PATH. HTMLPurifier compiles its definitions to
 *      disk; on cPanel the default location is often not writable and the failure is a 500 at save
 *      time. Pointing it inside storage/ is what makes the whole thing work on the deployment
 *      target §1 actually names.
 */
class ContentSanitizer
{
    private ?HTMLPurifier $purifier = null;

    /**
     * Everything a document author can legitimately produce, and nothing else.
     *
     * Tables are in because engineering documents are full of them. `<style>` and `<script>` are
     * out, and no amount of "but the template needs it" changes that — page styling comes from the
     * Template record, which is configuration written by an administrator, not content typed by
     * whoever can edit a document.
     *
     * EVERY ELEMENT HERE EXISTS IN THE DOCTYPE SET BELOW. HTMLPurifier does not silently ignore a
     * whitelisted element its grammar has never heard of — it raises E_USER_WARNING once per
     * purify, which Laravel turns into an exception outside production. So an HTML5-only tag listed
     * here does not quietly widen the whitelist; it breaks saving a document. `figure` and
     * `figcaption` were listed here and did exactly that. They are gone rather than accommodated
     * with a custom definition, because nothing in the editor produces them: a pasted `<figure>`
     * is unwrapped and its image and caption text survive, which is the right outcome anyway.
     *
     * `style` IS ALLOWED ON THE BLOCK ELEMENTS, and that is not a loosening. The editor's alignment
     * control sets `text-align` on the paragraph, heading or table cell it is applied to — that is
     * how the underlying extension works and there is no attribute to redirect it to. Listing style
     * on `span` and `div` alone therefore did not restrict the author to safe styling; it silently
     * deleted every centred heading and right-aligned figure the moment the document was saved,
     * which reads as data loss to the person who typed it. What actually keeps this safe is
     * ALLOWED_CSS below: the property whitelist is enforced identically wherever the attribute
     * appears, so widening the elements widens nothing an author can express.
     */
    private const ALLOWED_ELEMENTS =
        'p[style],br,hr,strong,b,em,i,u,s,sub,sup,'
        .'h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],'
        .'ul[style],ol[style],li[style],blockquote[style],pre,code,'
        .'table[style],thead,tbody,tfoot,tr,'
        .'th[colspan|rowspan|scope|style],td[colspan|rowspan|style],'
        .'a[href|title],img[src|alt|width|height|style],'
        .'span[style],div[style]';

    /** Inline CSS an author can reach through the editor toolbar. Nothing that can position or overlay. */
    private const ALLOWED_CSS =
        'text-align,font-weight,font-style,text-decoration,'
        .'color,background-color,width,height,vertical-align,'
        .'margin-left,padding-left,list-style-type';

    /**
     * Clean a block of authored HTML.
     *
     * Null in, null out — an empty document is a legitimate state (somebody created it and has not
     * typed anything yet) and must not become the string "".
     */
    public function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $trimmed = trim($html);

        if ($trimmed === '') {
            return null;
        }

        return $this->purifier()->purify($trimmed);
    }

    /**
     * Plain text of a document, for search indexes, previews and word counts.
     *
     * Runs on the CLEANED html, never the raw input. Extracting text from unsanitized markup and
     * treating the result as safe is how a "harmless" preview field ends up carrying an attribute
     * somebody smuggled through.
     */
    public function toPlainText(?string $html): string
    {
        $clean = $this->clean($html);

        if ($clean === null) {
            return '';
        }

        // Block-level tags become spaces first, so "</p><p>" does not weld two sentences together.
        $spaced = preg_replace('/<(\/?)(p|div|br|li|tr|h[1-6]|blockquote|pre)\b[^>]*>/i', ' ', $clean) ?? $clean;

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(
            strip_tags($spaced),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        )));
    }

    public function wordCount(?string $html): int
    {
        $text = $this->toPlainText($html);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    // ------------------------------------------------------------------ configuration

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= new HTMLPurifier($this->config());
    }

    private function config(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::ALLOWED_ELEMENTS);
        $config->set('CSS.AllowedProperties', self::ALLOWED_CSS);

        // Only these schemes survive. `javascript:`, `vbscript:` and `file:` are absent by
        // construction rather than by exclusion, which is the difference between a whitelist and a
        // hopeful filter. `data:` stays because pasted and imported images arrive that way.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.DisableResources', false);

        // An anchor to another site is fine — reading a linked standard is normal work — but it
        // opens in a new tab with the opener severed, so a linked page cannot navigate the tab the
        // document was read in.
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('HTML.TargetNoopener', true);

        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);
        $config->set('Core.Encoding', 'UTF-8');

        // storage/ is writable on cPanel; HTMLPurifier's default vendor-adjacent cache directory
        // frequently is not, and the symptom is a 500 the first time anybody saves a document.
        $cache = storage_path('framework/cache/htmlpurifier');

        if (! is_dir($cache)) {
            @mkdir($cache, 0o775, true);
        }

        if (is_writable($cache)) {
            $config->set('Cache.SerializerPath', $cache);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        return $config;
    }
}
