<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Document\PdfRenderer;
use App\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Reading one person's pay — usually one's own.
 *
 * THERE IS NO STORE, UPDATE OR DESTROY HERE, and that is the whole design rather than an omission.
 * A payslip is written by calculating a run and by nothing else. A payslip somebody could edit is a
 * payslip nobody can rely on, including the person it belongs to and the auditor asking what was
 * actually paid (§59).
 *
 * `mine()` DOES NOT ASK WHO YOU ARE. It runs the same scoped query as everything else, against
 * `payroll.view`, and a person holding that at scope `own` reaches exactly their own payslips
 * because the payslip carries its own `user_id` — copied from the employee when the run was
 * calculated, because `own` cannot reach through to the employee and never could. Writing
 * `where('employee_id', $me)` here would be a sixth gate nobody else runs, and the first time the
 * two disagreed it would be this page that was wrong rather than the access engine (§84).
 */
class PayslipController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly PdfRenderer $pdf,
    ) {}

    /** Everything this person is allowed to see of their own pay. */
    public function mine(Request $request): View
    {
        $this->authorize('viewAny', Payslip::class);

        $payslips = $this->compiler
            ->apply(Payslip::query(), $request->user(), 'payroll.view')
            // Only from runs that were actually approved. A draft run is a work in progress that
            // will be recalculated, possibly several times, and showing somebody a figure that then
            // changes is worse than showing them nothing.
            ->whereHas('run', fn ($q) => $q->whereNotNull('locked_at'))
            ->with(['run', 'employee'])
            ->orderByDesc('id')
            ->paginate(24);

        return view('payslips.mine', ['payslips' => $payslips]);
    }

    public function show(Payslip $payslip): View
    {
        $this->authorize('view', $payslip);

        $payslip->load(['run.status', 'employee.position', 'lines']);

        return view('payslips.show', [
            'payslip' => $payslip,
            // The lines in the order they were calculated, which is the order that makes the
            // arithmetic followable: each figure is arrived at from the ones above it.
            'lines' => $payslip->lines->sortBy('sequence'),
            'timeline' => $this->activity->timelineFor($payslip)->limit(10)->get(),
        ]);
    }

    /**
     * The payslip as a PDF, for the person to keep.
     *
     * Gated on `download`, which asks for `payroll.view` on THIS payslip rather than for the bulk
     * `payroll.export`. The two are deliberately different: export is everybody's pay in one file,
     * and a company should be able to let people keep their own payslips without also handing out
     * the whole payroll (§67).
     *
     * Rendered on demand rather than stored. A payslip PDF sitting in the filesystem is a copy of
     * somebody's pay outside the access engine, and every one of them would have to be found and
     * re-gated the day somebody's permissions changed.
     */
    public function download(Request $request, Payslip $payslip): Response
    {
        $this->authorize('download', $payslip);

        // `organization` is loaded because the PDF letterhead names the company and lazy loading is
        // off — a relation the template reaches for and nobody loaded is a 500 on a download.
        $payslip->load(['run', 'employee.position', 'lines', 'organization']);

        $html = view('payslips.pdf', [
            'payslip' => $payslip,
            'lines' => $payslip->lines->sortBy('sequence'),
        ])->render();

        // Recorded because a payslip leaving the platform is exactly the event somebody investigating
        // a leak needs to be able to find afterwards (§59).
        $this->activity->record(
            $payslip,
            'downloaded',
            $request->user()->name.' downloaded payslip '.$payslip->number.'.',
            [],
            $request->user(),
        );

        return response(
            $this->pdf->renderHtml($payslip->number, $html),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$payslip->number.'.pdf"',
            ],
        );
    }
}
