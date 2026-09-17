<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\TaxCodeRequest;
use App\Models\Expense;
use App\Models\Income;
use App\Models\TaxCode;
use App\Services\TaxCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The company's own tax rates (§47). Nothing here knows any country's tax: a rate is a row a
 * finance manager typed in, and every expense or invoice copies it at the moment it is raised.
 */
class TaxCodeController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly TaxCodeService $codes,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TaxCode::class);
        $user = $request->user();
        $query = $this->compiler->apply(TaxCode::query(), $user, 'tax_code.view');

        if (filled($q = trim((string) $request->query('q')))) {
            $query->where(fn ($inner) => $inner->where('code', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%'));
        }

        return view('tax-codes.index', [
            'codes' => $query->orderBy('code')->paginate(40)->withQueryString(),
            'canCreate' => $user->can('create', TaxCode::class),
            'filters' => ['q' => $request->query('q')],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', TaxCode::class);

        return view('tax-codes.create', ['code' => new TaxCode(['applies_to' => 'both', 'is_active' => true, 'rate' => '0'])]);
    }

    public function store(TaxCodeRequest $request): RedirectResponse
    {
        $this->authorize('create', TaxCode::class);
        $code = $this->codes->create($request->validated(), $request->user());

        return redirect()->route('tax-codes.index')->with('status', $code->displayName().' has been created.');
    }

    public function edit(Request $request, TaxCode $taxCode): View
    {
        $this->authorize('update', $taxCode);

        return view('tax-codes.edit', [
            'code' => $taxCode,
            'timeline' => $this->activity->timelineFor($taxCode)->limit(15)->get(),
        ]);
    }

    public function update(TaxCodeRequest $request, TaxCode $taxCode): RedirectResponse
    {
        $this->authorize('update', $taxCode);
        $this->codes->update($taxCode, $request->validated(), $request->user());

        return redirect()->route('tax-codes.index')->with('status', $taxCode->displayName().' has been updated.');
    }

    public function destroy(Request $request, TaxCode $taxCode): RedirectResponse
    {
        $this->authorize('delete', $taxCode);

        if (Expense::query()->where('tax_code_id', $taxCode->id)->exists() || Income::query()->where('tax_code_id', $taxCode->id)->exists()) {
            return back()->withErrors(['delete' => 'A tax code that has been used cannot be deleted. Make it inactive instead.']);
        }

        $this->activity->record($taxCode, 'deleted', $request->user()->name.' deleted the tax code '.$taxCode->displayName().'.', [], $request->user());
        $taxCode->delete();

        return redirect()->route('tax-codes.index')->with('status', 'The tax code has been deleted.');
    }
}
