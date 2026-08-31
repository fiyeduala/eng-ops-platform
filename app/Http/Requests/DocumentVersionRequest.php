<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Engines\Configuration\SettingsRepository;

/**
 * Write a revision: the editor's save, or an upload.
 *
 * TWO THINGS ARE NOT VALIDATED HERE, on purpose.
 *
 * THE HTML. `content_html` is accepted as a string of up to two megabytes and no more, because
 * validating markup with a rule is security theatre — a `regex` that rejects `<script` is defeated
 * by `<SCRIPT`, by an `onerror` attribute, by a `javascript:` href, and by half a dozen encodings.
 * The real defence is ContentSanitizer's whitelist, which runs inside DocumentVersionService on
 * every write and is the ONE place document HTML crosses into storage. A second, weaker check here
 * would mostly serve to make somebody believe the sanitiser is optional.
 *
 * THE FILE. `file` is checked for presence and size only. Extension, MIME agreement and the
 * executable blacklist all live in FileVault::validate(), which is called by the service — again,
 * one place, and it is the place that also writes the file. §67 asks for MIME, extension, size and
 * filename validation; it gets all four, from the class that has the stored bytes in hand rather
 * than from a rule that only has the client's claims about them.
 *
 * `revision` is absent, because RevisionSequencer decides it. A supplied revision label would let
 * somebody write "Rev C" twice, which is precisely the failure controlled documents exist to
 * prevent.
 */
class DocumentVersionRequest extends TenantRequest
{
    /**
     * Two megabytes of HTML is a very long procedure. The limit exists to stop a runaway paste or a
     * scripted POST filling a longText column, not to constrain honest authoring.
     */
    private const MAX_HTML_BYTES = 2_000_000;

    public function rules(): array
    {
        return [
            'content_html' => ['nullable', 'string', 'max:'.self::MAX_HTML_BYTES],

            // TipTap's own document model, kept alongside the HTML so the editor reopens on exactly
            // what it saved rather than on a re-parse. Never rendered, never trusted, never the
            // source for export — the HTML is.
            'content_json' => ['nullable', 'array'],

            'change_summary' => ['nullable', 'string', 'max:500'],

            // Presence and size only. See the class docblock.
            'file' => ['nullable', 'file', 'max:'.($this->maxUploadKb())],
        ];
    }

    /**
     * The upload ceiling, in kilobytes, from settings — with a floor and a hard cap.
     *
     * Read here as well as in FileVault because Laravel needs a number before the file is moved,
     * and a request that gets this far with a 900 MB body has already cost the server the upload.
     * The cap is not configurable upwards past what PHP itself will accept: raising it in settings
     * without raising `upload_max_filesize` produces a confusing empty-$_FILES failure rather than
     * a bigger limit.
     */
    private function maxUploadKb(): int
    {
        $configured = (int) app(SettingsRepository::class)->int('documents.max_upload_mb', 25);

        return max(1, min($configured, 512)) * 1024;
    }

    /** @return array<string,mixed> */
    public function versionAttributes(): array
    {
        $data = $this->validated();

        unset($data['file']);

        return $data;
    }
}
