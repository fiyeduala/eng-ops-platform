<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\PaymentRequest;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Payments out against purchase orders and approved expenses (submit → approve → pay), and money
 * received against issued income (recorded as paid at once).
 *
 * You may only raise a payment against something you can see. The payable is resolved through the
 * tenant scope and then put through its own `view` policy, so an id typed into the form for an
 * order in another department is refused rather than quietly paid (§84, §94).
 */
class PaymentController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly PaymentService $payments,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'payment';
    }

    protected function modelClass(): string
    {
        return Payment::class;
    }

    protected function routePrefix(): string
    {
        return 'payments';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payment::class);
        $user = $request->user();
        $direction = in_array($request->query('direction'), array_keys(Payment::DIRECTIONS), true) ? $request->query('direction') : null;
        $query = $this->scopedQuery($user)->with(['status', 'project'])->direction($direction);
        $this->applyCommonFilters($query, $request);

        return view('payments.index', [
            'payments' => $query->orderByDesc('id')->paginate(40)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('payment'),
            'projects' => $this->projectOptions($user),
            'filters' => [...$this->commonFilters($request, 'all'), 'direction' => $direction],
            'canCreate' => $user->can('create', Payment::class),
        ]);
    }

    public function show(Request $request, Payment $payment): View
    {
        $this->authorize('view', $payment);
        $user = $request->user();
        $payment->load(['status', 'project', 'orgUnit', 'vendor', 'client', 'approver', 'payer', 'creator']);
        $payable = $payment->payable;

        return view('payments.show', [
            'payment' => $payment,
            'payable' => $payable,
            'payableVisible' => $payable !== null && $user->can('view', $payable),
            'timeline' => $this->activity->timelineFor($payment)->limit(25)->get(),
            'canEdit' => $user->can('update', $payment),
            'canDelete' => $user->can('delete', $payment),
            'canApprove' => $user->can('approve', $payment),
            'canPay' => $user->can('pay', $payment),
            'canManage' => $user->can('manage', $payment),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Payment::class);
        $user = $request->user();
        $options = $this->payableOptions($user);
        $chosen = (string) $request->query('payable', '');

        return view('payments.create', [
            'payment' => new Payment([
                'method' => array_key_first($this->lists->for('payment.methods', Payment::DEFAULT_METHODS)),
                'confidentiality' => Confidentiality::Internal->value,
            ]),
            'payables' => $options,
            'chosen' => $chosen,
            'methods' => $this->lists->for('payment.methods', Payment::DEFAULT_METHODS),
            'confidentialities' => Confidentiality::options(),
        ]);
    }

    public function store(PaymentRequest $request): RedirectResponse
    {
        $this->authorize('create', Payment::class);
        $data = $request->validated();
        $payable = $this->payments->resolvePayable($data['direction'], $data['payable_type'], $data['payable_id']);

        if ($payable === null) {
            return back()->withInput()->withErrors(['payable' => 'Choose what this payment is for.']);
        }

        $this->authorize('view', $payable);

        try {
            $payment = $this->payments->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $message = $payment->direction === 'incoming'
            ? $payment->number.' has been recorded as received.'
            : $payment->number.' has been saved as a draft.';

        return redirect()->route('payments.show', $payment)->with('status', $message);
    }

    public function edit(Request $request, Payment $payment): View
    {
        $this->authorize('update', $payment);

        return view('payments.edit', [
            'payment' => $payment,
            'methods' => $this->lists->for('payment.methods', Payment::DEFAULT_METHODS),
            'confidentialities' => Confidentiality::options(),
        ]);
    }

    public function update(PaymentRequest $request, Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        try {
            $this->payments->update($payment, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('payments.show', $payment)->with('status', $payment->number.' has been updated.');
    }

    public function destroy(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);
        $this->recordDeletion($payment, $request->user(), 'payment');
        $payment->delete();

        return redirect()->route('payments.index')->with('status', 'The draft payment has been deleted.');
    }

    public function submit(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);

        return $this->attempt(fn () => $this->payments->submit($payment, $request->user()), 'The payment has been submitted for approval.');
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('approve', $payment);

        return $this->attempt(fn () => $this->payments->approve($payment, $request->user()), 'The payment has been approved and is ready to be released.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('approve', $payment);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => $this->payments->reject($payment, $request->user(), $reason), 'The payment has been rejected.');
    }

    public function cancel(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('manage', $payment);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => $this->payments->cancel($payment, $request->user(), $reason), 'The payment has been cancelled.');
    }

    public function pay(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('pay', $payment);
        $data = $request->validate([
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->attempt(
            fn () => $this->payments->pay($payment, $request->user(), $data['paid_on'] ?? null, $data['reference'] ?? null),
            'The payment has been released.',
        );
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    /**
     * Everything this person could raise a payment against, grouped for the picker, as
     * "type:id" => label. Only what they can see, and only what is currently payable.
     *
     * @return array<string,array<string,string>>
     */
    private function payableOptions(User $user): array
    {
        $groups = [];
        $label = fn (Model $record, string $total) => $record->displayName().' — outstanding '.$this->payments->outstandingOn($record).' of '.$total;

        if ($user->hasPermission('purchase_order.view')) {
            $groups['Purchase orders'] = PurchaseOrder::visibleTo($user, 'purchase_order.view')
                ->statusKey(['approved', 'issued', 'part_received', 'received'])->orderByDesc('id')->limit(200)->get()
                ->filter(fn ($po) => (float) $this->payments->outstandingOn($po) > 0)
                ->mapWithKeys(fn ($po) => ['purchase_order:'.$po->id => $label($po, (string) $po->total)])->all();
        }

        if ($user->hasPermission('expense.view')) {
            $groups['Approved expenses'] = Expense::visibleTo($user, 'expense.view')
                ->statusKey('approved')->orderByDesc('id')->limit(200)->get()
                ->filter(fn ($expense) => (float) $this->payments->outstandingOn($expense) > 0)
                ->mapWithKeys(fn ($expense) => ['expense:'.$expense->id => $label($expense, (string) $expense->total_amount)])->all();
        }

        if ($user->hasPermission('income.view')) {
            $groups['Income awaiting receipt'] = Income::visibleTo($user, 'income.view')
                ->statusKey(['issued', 'part_received'])->orderByDesc('id')->limit(200)->get()
                ->filter(fn ($income) => (float) $this->payments->outstandingOn($income) > 0)
                ->mapWithKeys(fn ($income) => ['income:'.$income->id => $label($income, (string) $income->total_amount)])->all();
        }

        return array_filter($groups);
    }
}
