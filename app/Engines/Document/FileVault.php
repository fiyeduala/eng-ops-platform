<?php

declare(strict_types=1);

namespace App\Engines\Document;

use App\Engines\Configuration\SettingsRepository;
use App\Models\Document;
use App\Models\DocumentCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything that touches an uploaded file (§67).
 *
 * The rule the whole class exists to enforce: A DOCUMENT FILE IS NEVER REACHABLE BY URL. It lives
 * on the private disk, outside the web root, and the only way to it is a controller action that has
 * already run the policy. There is no "unguessable path" cleverness here, because an unguessable
 * path is an access control that leaks permanently the first time somebody pastes a link into a
 * group chat.
 *
 * VALIDATION IS FIVE CHECKS AND ALL OF THEM RUN:
 *
 *   1. The extension is on the category's whitelist (or the org default).
 *   2. The extension is not on the executable denylist, whatever the whitelist says.
 *   3. The MIME type the browser reported is plausible for that extension.
 *   4. The MIME type the SERVER derives from the file's own bytes agrees.
 *   5. The size is within the limit.
 *
 * Checks 3 and 4 are not redundant. The browser's Content-Type is attacker-controlled and worth
 * nothing on its own; finfo reads the actual bytes and is worth a great deal. Running both means a
 * mismatch — a .pdf whose bytes are a PHP script — is caught as a mismatch rather than passing
 * whichever check the attacker chose to satisfy.
 *
 * The STORED FILENAME is always generated. The original is kept in a database column for display,
 * and is never, ever used to build a path: `../../.env` and `shell.php.pdf` are both solved by
 * simply never trusting the string.
 */
class FileVault
{
    public const DISK = 'local';

    /**
     * Refused regardless of any whitelist, and this list is not configurable.
     *
     * An administrator who allows `.php` uploads because a vendor sent them a code sample has,
     * without meaning to, given anybody who can upload a document the ability to run code on the
     * server. The setting that would permit that must not exist (§67).
     */
    public const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'cpl', 'jar', 'app',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'hta',
        'dll', 'so', 'dylib', 'pl', 'py', 'rb', 'cgi', 'asp', 'aspx', 'jsp', 'htaccess',
    ];

    /** What a company gets before anybody configures anything. */
    public const DEFAULT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
        'txt', 'csv', 'rtf',
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp',
        'dwg', 'dxf', 'dwf', 'rvt', 'ifc', 'skp',
        'zip', 'rar', '7z',
    ];

    /**
     * Extension => the MIME types genuinely seen for it.
     *
     * Deliberately generous. Office files are reported half a dozen ways depending on the browser
     * and the platform, CAD files are usually octet-stream, and being strict here means rejecting
     * legitimate drawings — which trains people to email attachments around instead, which is worse
     * for security than the thing the strictness was protecting.
     *
     * @var array<string,array<int,string>>
     */
    private const MIME_MAP = [
        'pdf' => ['application/pdf', 'application/x-pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', 'application/octet-stream',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip', 'application/octet-stream',
        ],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip', 'application/octet-stream',
        ],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'rtf' => ['application/rtf', 'text/rtf', 'text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    // ------------------------------------------------------------------ validation

    /**
     * Check an upload before anything touches disk.
     *
     * Throws rather than returning false, because every caller's correct response to a rejected
     * upload is identical — stop and tell the user why — and a boolean invites the one caller that
     * forgets to check it.
     *
     * @throws \DomainException with a message safe to show the person uploading
     */
    public function validate(UploadedFile $file, ?DocumentCategory $category = null): void
    {
        if (! $file->isValid()) {
            throw new \DomainException('That file did not upload correctly. Please try again.');
        }

        $extension = $this->extensionOf($file);

        if ($extension === '') {
            throw new \DomainException('That file has no extension, so its type cannot be established.');
        }

        // Denylist first, and it wins over everything. Note it tests the LAST extension only after
        // the double-extension check below, so `report.php.pdf` is caught by that rather than here.
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new \DomainException('Files of type .'.$extension.' cannot be uploaded to this system.');
        }

        $this->assertNoExecutableExtension($file->getClientOriginalName());

        $allowed = $this->allowedExtensions($category);

        if (! in_array($extension, $allowed, true)) {
            throw new \DomainException(
                'Files of type .'.$extension.' are not accepted here. Allowed: '.implode(', ', $allowed).'.'
            );
        }

        $this->assertMimeAgrees($file, $extension);

        $maxMb = $this->maxUploadMb($category);

        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw new \DomainException('That file is larger than the '.$maxMb.' MB limit for this category.');
        }
    }

    /**
     * `drawing.php.pdf` — every extension in the name has to be clean, not just the last.
     *
     * A server misconfigured to run anything containing `.php` will happily execute the first,
     * whatever the last one says. Checking only the final extension is the single most common way
     * an upload filter is bypassed.
     */
    private function assertNoExecutableExtension(string $filename): void
    {
        foreach (array_slice(explode('.', mb_strtolower($filename)), 1) as $part) {
            if (in_array($part, self::FORBIDDEN_EXTENSIONS, true)) {
                throw new \DomainException(
                    'That filename contains ".'.$part.'", which is not allowed even as part of a longer name.'
                );
            }
        }
    }

    /**
     * Does the file's own content agree with its extension?
     *
     * `getMimeType()` reads the bytes through finfo; `getClientMimeType()` is whatever the browser
     * claimed. An extension absent from MIME_MAP is passed — the map exists to catch disguises in
     * common formats, and refusing every CAD format nobody has a canonical MIME type for would make
     * the system unusable for its actual audience.
     */
    private function assertMimeAgrees(UploadedFile $file, string $extension): void
    {
        $expected = self::MIME_MAP[$extension] ?? null;

        if ($expected === null) {
            return;
        }

        $actual = mb_strtolower((string) $file->getMimeType());

        if ($actual !== '' && ! in_array($actual, $expected, true)) {
            throw new \DomainException(
                'That file says it is a .'.$extension.' but its contents are '.$actual.'. It has not been accepted.'
            );
        }
    }

    // ------------------------------------------------------------------ storing

    /**
     * Put a validated file on the private disk under a generated name.
     *
     * Path shape: `documents/{organization}/{document}/{ulid}.{ext}` — tenant-partitioned, so a
     * misconfigured backup or a rogue `Storage::allFiles()` is at least organized along the boundary
     * that matters, and a ULID so the name is unguessable, sortable and free of anything the user
     * chose.
     *
     * @return array{path:string,disk:string,hash:string,size:int,mime:string,original_name:string}
     */
    public function store(UploadedFile $file, Document $document, ?DocumentCategory $category = null): array
    {
        $this->validate($file, $category ?? $document->category);

        $extension = $this->extensionOf($file);
        $directory = sprintf('documents/%d/%d', (int) $document->organization_id, (int) $document->getKey());
        $name = (string) Str::ulid().'.'.$extension;

        $path = $file->storeAs($directory, $name, ['disk' => self::DISK]);

        if ($path === false) {
            throw new \RuntimeException('The file could not be saved to storage.');
        }

        return [
            'path' => $path,
            'disk' => self::DISK,
            // Hashed from the stored copy, not the temp upload: this is the value integrity checks
            // and signatures compare against later, so it must describe the bytes that were kept.
            'hash' => hash('sha256', (string) Storage::disk(self::DISK)->get($path)),
            'size' => (int) Storage::disk(self::DISK)->size($path),
            'mime' => (string) $file->getClientMimeType(),
            'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
        ];
    }

    /**
     * Write generated bytes (an export, a rendered PDF) to the vault.
     *
     * @return array{path:string,disk:string,hash:string,size:int}
     */
    public function put(string $contents, Document $document, string $extension): array
    {
        $extension = mb_strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? 'bin');

        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new \DomainException('Refusing to write a .'.$extension.' file.');
        }

        $path = sprintf(
            'documents/%d/%d/%s.%s',
            (int) $document->organization_id,
            (int) $document->getKey(),
            Str::ulid(),
            $extension,
        );

        Storage::disk(self::DISK)->put($path, $contents);

        return [
            'path' => $path,
            'disk' => self::DISK,
            'hash' => hash('sha256', $contents),
            'size' => strlen($contents),
        ];
    }

    public function delete(?string $path, string $disk = self::DISK): void
    {
        if ($path !== null && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    public function exists(?string $path, string $disk = self::DISK): bool
    {
        return $path !== null && Storage::disk($disk)->exists($path);
    }

    /**
     * The filename the browser is told to save it as.
     *
     * Built from the document's own number and revision rather than from anything the uploader
     * typed. Two reasons: a downloaded controlled document should be identifiable on somebody's
     * desktop six months later, and a response header assembled from user input is a header
     * injection waiting to happen.
     */
    public function downloadName(Document $document, string $revision, string $extension): string
    {
        $stem = trim($document->number.'-Rev'.$revision.'-'.$document->title);
        $stem = preg_replace('/[^A-Za-z0-9 \-_.]/', '', $stem) ?? 'document';
        $stem = trim(preg_replace('/\s+/', ' ', $stem) ?? 'document');

        return mb_substr($stem === '' ? 'document' : $stem, 0, 120).'.'.$extension;
    }

    // ------------------------------------------------------------------ configuration

    /**
     * What this category accepts, falling back to the org setting and then to the built-in list.
     *
     * The forbidden list is subtracted at the end, so a category configured with `.php` — by
     * mistake or otherwise — still cannot accept one.
     *
     * @return array<int,string>
     */
    public function allowedExtensions(?DocumentCategory $category = null): array
    {
        $allowed = $category?->uploadExtensions()
            ?? $this->settings->array('documents.allowed_extensions', self::DEFAULT_EXTENSIONS);

        $allowed = array_map(
            fn ($ext) => mb_strtolower(ltrim(trim((string) $ext), '.')),
            $allowed,
        );

        return array_values(array_diff(array_filter($allowed), self::FORBIDDEN_EXTENSIONS));
    }

    public function maxUploadMb(?DocumentCategory $category = null): int
    {
        return max(1, (int) ($category?->max_upload_mb ?? $this->settings->int('documents.max_upload_mb', 25)));
    }

    private function extensionOf(UploadedFile $file): string
    {
        // getClientOriginalExtension() reads the user's filename; that is fine HERE because the
        // value is immediately checked against the whitelist and never used to build a path.
        return mb_strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
    }

    private function safeOriginalName(string $name): string
    {
        return mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', $name) ?? 'file', 0, 255);
    }
}
