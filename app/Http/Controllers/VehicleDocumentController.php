<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\SettingsRepository;
use App\Http\Requests\VehicleDocumentRequest;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\VehicleDocumentService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The papers that make a vehicle legal, and the dates they stop doing so.
 *
 * THE REGISTER OPENS ON `expiring`, which for once means expiring AND already expired. Every other
 * "expiring" tab in this platform — contracts, vendors, training, risk assessments — draws the same
 * line, and the reason is the same: a certificate that lapsed last Tuesday is the most urgent row on
 * the screen, and a tab that quietly drops it the moment it matters most is a tab that gets people
 * driving uninsured.
 *
 * THERE IS NO STATUS AND NO WORKFLOW HERE, so this is not a RegisterController. Expiry is a date
 * compared against today, computed at the moment it is asked; the nightly sweep sends warnings and
 * writes nothing. A company whose cron was never installed loses its reminders and every date on
 * every screen stays exactly as correct as it was.
 *
 * RENEWING IS ITS OWN ROUTE BEHIND ITS OWN PERMISSION, and it preserves the old certificate. "Were we
 * insured on the day of the accident" is a question asked about last year and answered by a record
 * that still exists — so a renewal is a NEW document that inherits the type and the issuer and
 * inherits neither the number nor the scan (see VehicleDocumentService::renew).
 *
 * THE SCAN IS PRIVATE AND EVERY BYTE IS AUTHORISED (§67). `download()` asks the policy with the
 * record in hand before it opens the disk, a row whose file is missing is a 404 rather than a 500 —
 * a storage path is not something a user should ever be shown — and the filename handed to the
 * browser is built here rather than echoed back from the upload.
 */
class VehicleDocumentController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly VehicleDocumentService $documents,
        private readonly SettingsRepository $settings,
        private readonly OptionList $lists,
    ) {}

    /** @return array<string,string> */
    private function views(): array
    {
        return [
            'expiring' => 'Expiring & expired',
            'expired' => 'Already expired',
            'current' => 'In date',
            'no_expiry' => 'Never expires',
            'all' => 'Everything',
        ];
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', VehicleDocument::class);

        $user = $request->user();

        $query = $this->compiler
            ->apply(VehicleDocument::query(), $user, 'vehicle_document.view')
            ->with(['vehicle:id,registration_number,asset_id', 'creator:id,name']);

        $query->search((string) $request->query('q', ''));
        $query->forVehicle($request->query('vehicle'));
        $query->ofType($request->query('type'));

        $view = (string) $request->query('view', 'expiring');
        $days = $this->settings->int('vehicle_document.expiry_warning_days', 60, (int) $user->organization_id);

        $this->applyView($query, $view, $days);

        /** @var LengthAwarePaginator $documents */
        $documents = $query->orderByRaw('expires_on is null')
            ->orderBy('expires_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('vehicle-documents.index', [
            'documents' => $documents,
            'vehicles' => $this->vehicleOptions($request),
            'types' => $this->lists->for('vehicle_document.types', VehicleDocument::DEFAULT_TYPES),
            'views' => $this->views(),
            'warningDays' => $days,
            'filters' => [
                'q' => $request->query('q'),
                'vehicle' => $request->query('vehicle'),
                'type' => $request->query('type'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', VehicleDocument::class),
        ]);
    }

    /** @param  Builder<VehicleDocument>  $query */
    private function applyView(Builder $query, string $view, int $days): void
    {
        match ($view) {
            // Both halves, deliberately — see the class docblock. `expiringWithin()` excludes what
            // has already lapsed, which is right for a warning and wrong for a worklist.
            'expiring' => $query->where(fn (Builder $q) => $q->expiringWithin($days)->orWhere(
                fn (Builder $inner) => $inner->expired()
            )),
            'expired' => $query->expired(),
            'current' => $query->current()->whereNotNull('expires_on')
                ->whereDate('expires_on', '>=', now()->startOfDay()),
            'no_expiry' => $query->current()->whereNull('expires_on'),
            default => null,
        };
    }

    public function show(Request $request, VehicleDocument $vehicleDocument): View
    {
        $this->authorize('view', $vehicleDocument);

        $user = $request->user();

        $vehicleDocument->loadMissing(['vehicle.asset:id,code,name', 'creator:id,name']);

        return view('vehicle-documents.show', [
            'document' => $vehicleDocument,
            'timeline' => $this->activity->timelineFor($vehicleDocument)->limit(25)->get(),
            'canEdit' => $user->can('update', $vehicleDocument),
            'canDelete' => $user->can('delete', $vehicleDocument),
            'canRenew' => $user->can('renew', $vehicleDocument),
            // Reaching this page already means `view`, so the only remaining question is whether
            // there is a file to offer.
            'canDownload' => $vehicleDocument->hasFile(),
            'types' => $this->lists->for('vehicle_document.types', VehicleDocument::DEFAULT_TYPES),
            'confidentialities' => Confidentiality::options(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', VehicleDocument::class);

        return view('vehicle-documents.create', [
            'document' => new VehicleDocument([
                'confidentiality' => Confidentiality::Internal->value,
                // Pre-selected when arriving from a vehicle page. Re-validated and re-authorised on
                // store, because a URL is a suggestion and not a permission (§84).
                'vehicle_id' => $request->query('vehicle'),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(VehicleDocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', VehicleDocument::class);

        // Resolved through the scoped query rather than findOrFail, so a vehicle belonging to
        // another organization — or to a part of this one out of reach — is a 404 and never a hint
        // that the row exists (§75, §94).
        $vehicle = $this->compiler
            ->apply(Vehicle::query(), $request->user(), 'vehicle.view')
            ->whereKey((int) $request->input('vehicle_id'))
            ->firstOrFail();

        try {
            $document = $this->documents->create(
                $vehicle,
                $request->documentAttributes(),
                $request->user(),
                $request->file('file'),
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect(route('vehicle-documents.show', $document))
            ->with('status', $document->typeLabel().' filed against '.$vehicle->registration_number.'.');
    }

    public function edit(Request $request, VehicleDocument $vehicleDocument): View
    {
        $this->authorize('update', $vehicleDocument);

        $vehicleDocument->loadMissing('vehicle:id,registration_number');

        return view('vehicle-documents.edit', [
            'document' => $vehicleDocument,
            ...$this->formOptions($request),
        ]);
    }

    public function update(VehicleDocumentRequest $request, VehicleDocument $vehicleDocument): RedirectResponse
    {
        $this->authorize('update', $vehicleDocument);

        try {
            $this->documents->update(
                $vehicleDocument,
                $request->documentAttributes(),
                $request->user(),
                $request->file('file'),
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect(route('vehicle-documents.show', $vehicleDocument))
            ->with('status', 'The '.mb_strtolower($vehicleDocument->typeLabel()).' has been updated.');
    }

    public function destroy(Request $request, VehicleDocument $vehicleDocument): RedirectResponse
    {
        $this->authorize('delete', $vehicleDocument);

        $vehicleId = $vehicleDocument->vehicle_id;
        $label = $vehicleDocument->typeLabel();

        $this->activity->record(
            $vehicleDocument,
            'deleted',
            $request->user()->name.' deleted the '.mb_strtolower($label).' record.',
            ['document_number' => $vehicleDocument->document_number],
            $request->user(),
        );

        // Soft-deleted, and the SCAN IS LEFT ON DISK on purpose. A deleted certificate can be
        // restored, and a restored row pointing at a file that was destroyed the moment somebody
        // mis-clicked is worse than a few kilobytes in private storage.
        $vehicleDocument->delete();

        return redirect(route('vehicles.show', $vehicleId))
            ->with('status', 'The '.mb_strtolower($label).' record has been deleted.');
    }

    // ------------------------------------------------------------------ renewal

    /**
     * File next year's certificate against the same vehicle.
     *
     * The form is the ordinary document form with the type and issuer already filled in and the
     * number and the scan deliberately blank. The old details are retained with a replacement link.
     */
    public function renewForm(Request $request, VehicleDocument $vehicleDocument): View
    {
        $this->authorize('renew', $vehicleDocument);

        $vehicleDocument->loadMissing('vehicle:id,registration_number');

        return view('vehicle-documents.renew', [
            'document' => $vehicleDocument,
            // Pre-populated exactly as the service will inherit it, so the screen and the write
            // agree: same type, same issuer, cover starting the day the old one lapses.
            'renewal' => new VehicleDocument([
                'document_type' => $vehicleDocument->document_type,
                'issuer' => $vehicleDocument->issuer,
                'issued_on' => $vehicleDocument->expires_on?->toDateString(),
                'confidentiality' => $vehicleDocument->confidentiality,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function renew(VehicleDocumentRequest $request, VehicleDocument $vehicleDocument): RedirectResponse
    {
        $this->authorize('renew', $vehicleDocument);

        try {
            $renewal = $this->documents->renew(
                $vehicleDocument,
                $request->documentAttributes(),
                $request->user(),
                $request->file('file'),
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect(route('vehicle-documents.show', $renewal))
            ->with('status', 'The renewal has been filed. The previous '
                .mb_strtolower($vehicleDocument->typeLabel()).' has been kept.');
    }

    // ------------------------------------------------------------------ the scan

    /**
     * Stream the stored scan from the private disk.
     *
     * Three checks before a byte moves: the policy is asked with the record in hand, the row is
     * confirmed to have a file at all, and the file is confirmed to exist on the disk.
     *
     * GATED ON `view`, WHICH IS THE WHOLE POINT rather than a shortcut. A vehicle document IS its
     * scan — the row is a summary of the certificate, and there is nothing on the show page that the
     * file does not also carry. Inventing a separate `download` right would hand somebody the
     * expiry date, the policy number and the issuer on screen while withholding the picture of them,
     * which protects nothing and only teaches people to photograph the monitor. The five gates run
     * against the record in hand, so the URL is not evidence of anything (§67, §84).
     */
    public function download(Request $request, VehicleDocument $vehicleDocument): StreamedResponse
    {
        $this->authorize('view', $vehicleDocument);

        abort_unless($vehicleDocument->hasFile(), 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists((string) $vehicleDocument->file_path), 404);

        $this->activity->record(
            $vehicleDocument,
            'downloaded',
            $request->user()->name.' downloaded the '.mb_strtolower($vehicleDocument->typeLabel()).'.',
            ['file_name' => $vehicleDocument->file_name],
            $request->user(),
        );

        // The name handed to the browser is BUILT, never the stored one echoed back: the uploaded
        // filename is attacker-controlled text that would otherwise land in a Content-Disposition
        // header. Only the extension survives, and only from a fixed list.
        $extension = mb_strtolower(pathinfo((string) $vehicleDocument->file_path, PATHINFO_EXTENSION));

        $name = str($vehicleDocument->vehicle?->registration_number.' '.$vehicleDocument->typeLabel())
            ->trim()->replaceMatches('/[^A-Za-z0-9 \-]/', '')->squish()->replace(' ', '-')->lower();

        return $disk->download(
            (string) $vehicleDocument->file_path,
            ($name->isEmpty() ? 'vehicle-document' : (string) $name)
                .'.'.(in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'], true) ? $extension : 'bin'),
        );
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'vehicles' => $this->vehicleOptions($request),
            'types' => $this->lists->for('vehicle_document.types', VehicleDocument::DEFAULT_TYPES),
            'confidentialities' => Confidentiality::options(),
        ];
    }

    /** @return array<int|string,string> */
    private function vehicleOptions(Request $request): array
    {
        // displayName() reads the asset's name, so the asset has to come with the vehicle. Without
        // this the picker lazy-loads once per vehicle — which §89 forbids outright, and which the
        // lazy-loading guard turns into a 500 rather than a slow page.
        return $this->compiler
            ->apply(Vehicle::query(), $request->user(), 'vehicle.view')
            ->with('asset:id,name')
            ->orderBy('registration_number')->limit(500)->get()
            ->mapWithKeys(fn (Vehicle $v) => [$v->id => $v->displayName()])
            ->all();
    }
}
