<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\NumberingSchemeRequest;
use App\Models\NumberingScheme;
use App\Services\NumberingSchemeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The reference-number editor (§64, §73).
 *
 * THERE IS NO create AND NO destroy, and both absences are deliberate.
 *
 * A scheme is not created here because NumberGenerator creates it: the first record of a kind
 * provisions the company's scheme from the platform default, so that a company which has never
 * opened this screen can still raise a purchase order. Opening a register here does the same thing
 * — the page shows the scheme ACTUALLY IN USE, counter and all, rather than a form promising one.
 *
 * A scheme is not deleted here because deleting it would not remove anything; the next record
 * created would provision a fresh one starting at 1, and hand out references that are already on
 * paper. "Delete" on this screen would therefore be a button whose only observable effect is
 * duplicate reference numbers, which is the one thing this module exists to prevent.
 *
 * What IS on offer is amending the pattern, which changes only what future records are called.
 * Numbers already issued are stored strings and are never recomputed (§64), so a company can adopt
 * a new convention in March without renaming what it sent a client in January.
 */
class NumberingController extends Controller
{
    public function __construct(
        private readonly NumberingSchemeService $service,
    ) {}

    /** Every numbered register, with the pattern in force and an example of its next reference. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', NumberingScheme::class);

        return view('configuration.numbering.index', [
            'registers' => $this->service->registers(),
            'canManage' => $request->user()->can('create', NumberingScheme::class),
        ]);
    }

    /**
     * One register's scheme.
     *
     * Visiting provisions the row if the company has never had one — which is a write on a GET, and
     * worth being explicit about. It is idempotent, it creates the same row the next created record
     * would have created anyway, and the alternative is an editor that shows an empty page while
     * the platform quietly issues numbers from a default nobody can see.
     */
    public function show(Request $request, string $entityType): View
    {
        $this->authorize('viewAny', NumberingScheme::class);

        $scheme = $this->schemeOr404($entityType);

        return view('configuration.numbering.show', [
            'entityType' => $entityType,
            'scheme' => $scheme,
            'counters' => $this->service->counters($scheme),
            'example' => $this->service->preview($scheme, []),
            'tokens' => NumberingSchemeService::TOKENS,
            'resetPeriods' => NumberingScheme::RESET_PERIODS,
            'canManage' => $request->user()->can('update', $scheme),
        ]);
    }

    public function update(NumberingSchemeRequest $request, string $entityType): RedirectResponse
    {
        $scheme = $this->schemeOr404($entityType);

        $this->authorize('update', $scheme);

        try {
            $this->service->update($scheme, $request->schemeAttributes(), $request->user());
        } catch (\DomainException $e) {
            // WITH the input this time, unlike the status editor: this page is a single form, so
            // giving the administrator back the pattern they were composing is a kindness rather
            // than a way of spraying one row's text across twenty others.
            return back()->withInput()->withErrors(['pattern' => $e->getMessage()]);
        }

        return redirect()
            ->route('configuration.numbering.show', $entityType)
            ->with('status', 'Numbering for '.$scheme->displayName().' has been updated.');
    }

    /**
     * What an unsaved pattern would produce — rendered by the engine, not by the browser.
     *
     * The temptation is to build this preview in Alpine, since it is "just string replacement".
     * It is not: token widths, unresolved tokens being dropped and the separators around them
     * collapsing are all decisions NumberGenerator makes, and a JavaScript copy would drift from
     * them silently, leaving a preview that lies. So the browser asks the engine.
     *
     * Read-only in every sense — nothing is saved and no counter moves.
     */
    public function preview(Request $request, string $entityType): JsonResponse
    {
        $this->authorize('viewAny', NumberingScheme::class);

        $scheme = $this->schemeOr404($entityType);

        $draft = $request->validate([
            'pattern' => ['nullable', 'string', 'max:120'],
            'prefix' => ['nullable', 'string', 'max:12'],
            'reset_period' => ['nullable', 'string', 'in:'.implode(',', NumberingScheme::RESET_PERIODS)],
            'padding' => ['nullable', 'integer', 'between:1,12'],
        ]);

        try {
            $this->service->assertUsable(
                (string) ($draft['pattern'] ?? $scheme->pattern),
                (string) ($draft['reset_period'] ?? $scheme->reset_period),
            );
        } catch (\DomainException $e) {
            return response()->json(['example' => null, 'problem' => $e->getMessage()]);
        }

        return response()->json([
            'example' => $this->service->preview($scheme, $draft),
            'problem' => null,
        ]);
    }

    /**
     * §84: the register in the path is checked against the platform's own list, never trusted.
     *
     * The service raises DomainException for an unknown one — correct for an importer, wrong for a
     * URL, where a register that does not exist is a 404 and not a validation message.
     */
    private function schemeOr404(string $entityType): NumberingScheme
    {
        try {
            return $this->service->schemeFor($entityType);
        } catch (\DomainException) {
            throw new NotFoundHttpException;
        }
    }
}
