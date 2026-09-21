<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Export;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Collecting a file that was prepared in the background (docs/11 §3, §67).
 *
 * THERE ARE FOUR GATES ON THE DOWNLOAD AND EVERY ONE OF THEM IS LOAD-BEARING:
 *
 *   1. `auth` — a signed link is not a session. A URL forwarded out of somebody's inbox lands on
 *      the sign-in page, which is the difference between a link that leaked and a file that did.
 *   2. `signed` — the link stops working on its own when its signature expires, so an old
 *      notification is not a permanent key even for the person it was sent to.
 *   3. ExportPolicy — only the requester, including against an administrator. The file is one
 *      person's visibility frozen, and the policy explains at length why that is not negotiable.
 *   4. `isCollectable()` — the retention date, read from the row rather than from the signature.
 *      Two different clocks: the signature is about the link, retention is about the file, and the
 *      shorter of the two must win.
 *
 * Gates 1 and 3 are what actually protect the file. Gates 2 and 4 are what stop it protecting it
 * forever, which is its own kind of failure (§67).
 *
 * THE BYTES ARE STREAMED FROM THE PRIVATE DISK AND THE PATH IS NEVER IN THE URL. The route binds an
 * id, the row carries a generated path, and the display filename is sanitised on its way into the
 * header. Nothing a company typed reaches the filesystem or a response header unescaped (§84).
 */
class ExportController extends Controller
{
    /**
     * One person's own files.
     *
     * Scoped by `ownedBy()` and not by a permission, because there is no permission that would make
     * this list correct for anybody else — see ExportPolicy.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Export::class);

        $exports = Export::query()
            ->ownedBy($request->user())
            ->with('report')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('exports.index', ['exports' => $exports]);
    }

    /**
     * Hand the file over.
     *
     * A 404 rather than a 403 for an expired or swept file: it is gone, and "you may not have this"
     * would be both untrue and confusing to the one person who was entitled to it.
     */
    public function download(Request $request, Export $export): StreamedResponse
    {
        $this->authorize('download', $export);

        abort_unless($export->isCollectable(), 404);

        $disk = Storage::disk(Export::DISK);

        // The row says ready and the disk disagrees: swept by hand, or lost in a restore. The row
        // is corrected so the page stops offering a file that is not there, rather than leaving
        // somebody to click a broken link repeatedly.
        if (! $disk->exists((string) $export->path)) {
            $export->forceFill(['status' => 'expired', 'path' => null])->save();

            abort(404);
        }

        $export->forceFill([
            'downloads' => $export->downloads + 1,
            'last_downloaded_at' => now(),
        ])->save();

        return $disk->download((string) $export->path, $this->safeFilename($export), [
            'Content-Type' => $export->mimeType(),

            // One person's view at one moment; a proxy that caches it serves it to whoever asks
            // next (§61).
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Throw one away early.
     *
     * The file goes immediately and the row stays, marked expired. What the export contained is
     * still an audit question after its owner has finished with it (§59).
     */
    public function destroy(Request $request, Export $export): RedirectResponse
    {
        $this->authorize('delete', $export);

        if ($export->path !== null) {
            Storage::disk(Export::DISK)->delete($export->path);
        }

        $export->forceFill([
            'status' => 'expired',
            'path' => null,
            'bytes' => null,
            'expires_at' => now(),
        ])->save();

        return redirect()
            ->route('exports.index')
            ->with('status', 'That file has been deleted.');
    }

    /**
     * A filename safe to put in a response header.
     *
     * The stored name was built from the report's title, which is company-entered text. A quote or
     * a newline in a `Content-Disposition` header is header injection, and a slash or `..` is a
     * path the browser interprets while saving — so it is stripped again here rather than trusted
     * because it was already stripped once (§84).
     */
    private function safeFilename(Export $export): string
    {
        $name = preg_replace('/[^A-Za-z0-9 \-_.]/', '', (string) $export->filename);
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));

        return $name === '' ? 'export.'.$export->format : mb_substr($name, 0, 120);
    }
}
