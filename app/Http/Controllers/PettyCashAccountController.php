<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\PettyCashAccountRequest;
use App\Http\Requests\PettyCashTransactionRequest;
use App\Models\Expense;
use App\Models\OrgUnit;
use App\Models\PettyCashAccount;
use App\Models\PettyCashTransaction;
use App\Models\Project;
use App\Models\User;
use App\Services\PettyCashService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cash tins and their ledgers. Money only moves through `replenish`, `disburse` and `reverse`;
 * a voucher is never edited or deleted.
 */
class PettyCashAccountController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly PettyCashService $cash,
        private readonly OptionList $lists,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PettyCashAccount::class);
        $user = $request->user();
        $query = $this->compiler->apply(PettyCashAccount::query(), $user, 'petty_cash.view')
            ->with(['custodian:id,name', 'orgUnit:id,name', 'project:id,name'])
            ->search((string) $request->query('q'));

        return view('petty-cash.index', [
            'accounts' => $query->orderBy('code')->paginate(40)->withQueryString(),
            'canCreate' => $user->can('create', PettyCashAccount::class),
            'filters' => ['q' => $request->query('q')],
        ]);
    }

    public function show(Request $request, PettyCashAccount $pettyCashAccount): View
    {
        $this->authorize('view', $pettyCashAccount);
        $user = $request->user();
        $pettyCashAccount->load(['custodian', 'orgUnit', 'project', 'creator']);

        return view('petty-cash.show', [
            'account' => $pettyCashAccount,
            'transactions' => $pettyCashAccount->transactions()->with(['creator:id,name', 'project:id,name', 'reversalOf:id,number', 'reversedBy:id,number,reversal_of_id', 'projectCost:id,number,source_type,source_id'])
                ->paginate(50)->withQueryString(),
            'timeline' => $this->activity->timelineFor($pettyCashAccount)->limit(15)->get(),
            'categories' => $this->lists->for('expense.categories', Expense::DEFAULT_CATEGORIES),
            'projects' => $this->projects($user),
            'canEdit' => $user->can('update', $pettyCashAccount),
            'canDelete' => $user->can('delete', $pettyCashAccount),
            'canDisburse' => $pettyCashAccount->is_active && $user->can('disburse', $pettyCashAccount),
            'canReplenish' => $pettyCashAccount->is_active && $user->can('replenish', $pettyCashAccount),
            'canReverse' => $pettyCashAccount->is_active && $user->can('manage', $pettyCashAccount),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PettyCashAccount::class);

        return view('petty-cash.create', [
            'account' => new PettyCashAccount(['is_active' => true, 'confidentiality' => Confidentiality::Internal->value]),
            ...$this->options($request->user()),
        ]);
    }

    public function store(PettyCashAccountRequest $request): RedirectResponse
    {
        $this->authorize('create', PettyCashAccount::class);
        $this->assertPlacement($request->validated(), $request->user());
        $account = $this->cash->createAccount($request->validated(), $request->user());

        return redirect()->route('petty-cash.show', $account)->with('status', $account->displayName().' has been opened.');
    }

    public function edit(Request $request, PettyCashAccount $pettyCashAccount): View
    {
        $this->authorize('update', $pettyCashAccount);

        return view('petty-cash.edit', ['account' => $pettyCashAccount, ...$this->options($request->user())]);
    }

    public function update(PettyCashAccountRequest $request, PettyCashAccount $pettyCashAccount): RedirectResponse
    {
        $this->authorize('update', $pettyCashAccount);
        $this->assertPlacement($request->validated(), $request->user());
        $this->cash->updateAccount($pettyCashAccount, $request->validated(), $request->user());

        return redirect()->route('petty-cash.show', $pettyCashAccount)->with('status', $pettyCashAccount->displayName().' has been updated.');
    }

    public function destroy(Request $request, PettyCashAccount $pettyCashAccount): RedirectResponse
    {
        $this->authorize('delete', $pettyCashAccount);

        if ($pettyCashAccount->transactions()->exists()) {
            return back()->withErrors(['delete' => 'An account with vouchers cannot be deleted. Close it instead.']);
        }

        $this->activity->record($pettyCashAccount, 'deleted', $request->user()->name.' deleted the petty cash account '.$pettyCashAccount->displayName().'.', [], $request->user(), $pettyCashAccount->project_id);
        $pettyCashAccount->delete();

        return redirect()->route('petty-cash.index')->with('status', 'The petty cash account has been deleted.');
    }

    public function replenish(PettyCashTransactionRequest $request, PettyCashAccount $pettyCashAccount): RedirectResponse
    {
        $this->authorize('replenish', $pettyCashAccount);
        $this->assertPlacement($request->validated(), $request->user());

        try {
            $voucher = $this->cash->replenish($pettyCashAccount, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['replenish' => $e->getMessage()]);
        }

        return back()->with('status', $voucher->number.' — the tin has been topped up.');
    }

    public function disburse(PettyCashTransactionRequest $request, PettyCashAccount $pettyCashAccount): RedirectResponse
    {
        $this->authorize('disburse', $pettyCashAccount);
        $this->assertPlacement($request->validated(), $request->user());

        try {
            $voucher = $this->cash->disburse($pettyCashAccount, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['disburse' => $e->getMessage()]);
        }

        return back()->with('status', $voucher->number.' has been paid out.');
    }

    public function reverse(Request $request, PettyCashTransaction $pettyCashTransaction): RedirectResponse
    {
        $this->authorize('reverse', $pettyCashTransaction);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:300']])['reason'];

        try {
            $reversal = $this->cash->reverse($pettyCashTransaction, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reverse' => $e->getMessage()]);
        }

        return back()->with('status', $pettyCashTransaction->number.' has been reversed by '.$reversal->number.'.');
    }

    /** @return array<string,mixed> */
    private function options(User $user): array
    {
        return [
            'projects' => $this->projects($user),
            'units' => $this->compiler->apply(OrgUnit::query()->where('is_active', true), $user, 'org_unit.view')->orderBy('name')->pluck('name', 'id')->all(),
            'people' => $this->compiler->apply(User::query()->where('is_active', true), $user, 'employee.view')->orderBy('name')->pluck('name', 'id')->all(),
            'confidentialities' => Confidentiality::options(),
        ];
    }

    /** @return array<int|string,string> */
    private function projects(User $user): array
    {
        return Project::visibleTo($user, 'project.view')->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }

    /** @param array<string,mixed> $data */
    private function assertPlacement(array $data, User $user): void
    {
        if (($project = $data['project_id'] ?? null) !== null
            && ! Project::visibleTo($user, 'project.view')->whereKey($project)->exists()) {
            abort(403);
        }
        if (($unit = $data['org_unit_id'] ?? null) !== null
            && ! $this->compiler->apply(OrgUnit::query(), $user, 'org_unit.view')->whereKey($unit)->exists()) {
            abort(403);
        }
        if (($custodian = $data['custodian_id'] ?? null) !== null
            && ! $this->compiler->apply(User::query(), $user, 'employee.view')->whereKey($custodian)->exists()) {
            abort(403);
        }
    }
}
