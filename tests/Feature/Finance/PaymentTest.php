<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsFinance;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class PaymentTest extends TestCase
{
    use BuildsFinance, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $manager;

    private User $treasurer;

    private User $claimant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Meridian Payments');
        $this->provisionConfiguration($this->org);
        $this->manager = $this->makePerson($this->org, 'Ngozi Finance', null, [
            $this->makeRole($this->org, 'Finance manager', $this->financeGrants()),
        ]);
        $this->treasurer = $this->makePerson($this->org, 'Tayo Treasury', null, [
            $this->makeRole($this->org, 'Treasurer', [
                'payment.view' => 'organization', 'payment.pay' => 'organization', 'expense.view' => 'organization',
            ]),
        ]);
        $this->claimant = $this->makePerson($this->org, 'Emeka Site', null, [
            $this->makeRole($this->org, 'Employee', $this->claimantGrants()),
        ]);
    }

    #[Test]
    public function an_expense_is_paid_through_submit_approve_and_release_by_a_different_person(): void
    {
        $expense = $this->makeApprovedExpense($this->org, $this->claimant, $this->manager, ['amount' => '300']);

        $this->actingFresh($this->manager)->get(route('payments.create', ['payable' => 'expense:'.$expense->id]))
            ->assertOk()->assertSee($expense->number);

        $this->actingFresh($this->manager)->post(route('payments.store'), [
            'payable' => 'expense:'.$expense->id,
            'amount' => '300',
            'method' => 'bank_transfer',
            'confidentiality' => 'internal',
            // §84: who is paid and in what currency come from the expense.
            'currency' => 'EUR',
            'payee_name' => 'Somebody Else Ltd',
            'direction' => 'incoming',
        ])->assertRedirect();

        $payment = $this->withinOrganization($this->org, fn () => Payment::query()->firstOrFail());
        $this->assertSame('outgoing', $payment->direction);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame('Emeka Site', $payment->payee_name);
        $this->assertSame('draft', $payment->statusKey());

        $this->actingFresh($this->manager)->post(route('payments.submit', $payment))->assertSessionHasNoErrors();
        $this->actingFresh($this->manager)->post(route('payments.approve', $payment))->assertSessionHasNoErrors();

        // The approver may not also release the money.
        $this->actingFresh($this->manager)->post(route('payments.pay', $payment), ['paid_on' => now()->toDateString()])
            ->assertSessionHasErrors('status_id');
        $this->assertSame('approved', $this->withinOrganization($this->org, fn () => $payment->fresh()->statusKey()));

        $this->actingFresh($this->treasurer)->post(route('payments.pay', $payment), [
            'paid_on' => now()->toDateString(), 'reference' => 'TRF-0091',
        ])->assertSessionHasNoErrors();

        $payment = $this->withinOrganization($this->org, fn () => $payment->fresh());
        $this->assertSame('paid', $payment->statusKey());
        $this->assertSame($this->treasurer->id, $payment->paid_by);
        $this->assertSame('TRF-0091', $payment->reference);

        $expense = $this->withinOrganization($this->org, fn () => $expense->fresh());
        $this->assertSame('paid', $expense->statusKey());
        $this->assertSame('300.00', $expense->paid_amount);
    }

    #[Test]
    public function nothing_can_be_paid_twice_even_while_the_first_payment_is_still_a_draft(): void
    {
        $expense = $this->makeApprovedExpense($this->org, $this->claimant, $this->manager, ['amount' => '100']);
        $base = ['payable' => 'expense:'.$expense->id, 'method' => 'cash', 'confidentiality' => 'internal'];

        $this->actingFresh($this->manager)->post(route('payments.store'), [...$base, 'amount' => '100.01'])
            ->assertSessionHasErrors();
        $this->actingFresh($this->manager)->post(route('payments.store'), [...$base, 'amount' => '60'])
            ->assertSessionHasNoErrors();
        $this->actingFresh($this->manager)->post(route('payments.store'), [...$base, 'amount' => '40.01'])
            ->assertSessionHasErrors();
        $this->actingFresh($this->manager)->post(route('payments.store'), [...$base, 'amount' => '40'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->withinOrganization($this->org, fn () => Payment::query()->count()));

        // Raising the draft past the balance is refused on edit too.
        $first = $this->withinOrganization($this->org, fn () => Payment::query()->orderBy('id')->firstOrFail());
        $this->actingFresh($this->manager)->put(route('payments.update', $first), [
            'amount' => '61', 'method' => 'cash', 'confidentiality' => 'internal',
        ])->assertSessionHasErrors();
        $this->assertSame('60.00', $this->withinOrganization($this->org, fn () => $first->fresh()->amount));

        // A cancelled payment frees its share of the balance.
        $this->withinOrganization($this->org, fn () => app(PaymentService::class)->cancel($first, $this->manager, 'Duplicate.'));
        $this->assertSame('60.00', $this->withinOrganization($this->org, fn () => app(PaymentService::class)->outstandingOn($expense->fresh())));
    }

    #[Test]
    public function the_waived_payer_rule_is_recorded_on_the_payment(): void
    {
        $this->financeSetting($this->org, 'finance.separate_payment_approver_and_payer', false);
        $expense = $this->makeApprovedExpense($this->org, $this->claimant, $this->manager, ['amount' => '10']);

        $payment = $this->withinOrganization($this->org, function () use ($expense) {
            $service = app(PaymentService::class);
            $payment = $service->create([
                'direction' => 'outgoing', 'payable_type' => 'expense', 'payable_id' => $expense->id,
                'amount' => '10', 'method' => 'cash', 'confidentiality' => 'internal',
            ], $this->manager);
            $service->submit($payment, $this->manager);
            $service->approve($payment, $this->manager);

            return $service->pay($payment, $this->manager);
        });

        $this->assertSame('paid', $payment->statusKey());
        $entry = $this->withinOrganization($this->org, fn () => ActivityLog::query()
            ->where('subject_type', 'payment')->where('subject_id', $payment->id)->where('verb', 'paid')->firstOrFail());
        $this->assertTrue((bool) ($entry->properties['self_released'] ?? false));
    }

    #[Test]
    public function money_received_moves_income_through_part_received_to_received(): void
    {
        $income = $this->makeIssuedIncome($this->org, $this->manager, ['amount' => '500']);
        $receipt = ['payable' => 'income:'.$income->id, 'method' => 'bank_transfer', 'confidentiality' => 'internal'];

        $this->actingFresh($this->manager)->post(route('payments.store'), [...$receipt, 'amount' => '200'])
            ->assertSessionHasNoErrors();
        $income = $this->withinOrganization($this->org, fn () => $income->fresh());
        $this->assertSame('part_received', $income->statusKey());
        $this->assertSame('200.00', $income->received_amount);

        $payment = $this->withinOrganization($this->org, fn () => Payment::query()->firstOrFail());
        $this->assertSame('incoming', $payment->direction);
        $this->assertSame('paid', $payment->statusKey());

        $this->actingFresh($this->manager)->post(route('payments.store'), [...$receipt, 'amount' => '300.01'])
            ->assertSessionHasErrors();
        $this->actingFresh($this->manager)->post(route('payments.store'), [...$receipt, 'amount' => '300'])
            ->assertSessionHasNoErrors();
        $this->assertSame('received', $this->withinOrganization($this->org, fn () => $income->fresh()->statusKey()));

        // An incoming payment has no approval steps to walk.
        $this->actingFresh($this->manager)->post(route('payments.submit', $payment))->assertForbidden();
        $this->assertSame('paid', $this->withinOrganization($this->org, fn () => $payment->fresh()->statusKey()));
    }

    #[Test]
    public function a_draft_expense_or_unissued_income_cannot_be_paid(): void
    {
        $draft = $this->makeExpense($this->org, $this->claimant);

        $this->actingFresh($this->manager)->post(route('payments.store'), [
            'payable' => 'expense:'.$draft->id, 'amount' => '10', 'method' => 'cash', 'confidentiality' => 'internal',
        ])->assertSessionHasErrors();

        // An unknown morph type never resolves.
        $this->actingFresh($this->manager)->post(route('payments.store'), [
            'payable' => 'user:'.$this->manager->id, 'amount' => '10', 'method' => 'cash', 'confidentiality' => 'internal',
        ])->assertSessionHasErrors();

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => Payment::query()->count()));
    }

    #[Test]
    public function a_payment_cannot_be_raised_against_something_the_user_cannot_see(): void
    {
        $expense = $this->makeApprovedExpense($this->org, $this->claimant, $this->manager, ['amount' => '75']);
        $clerk = $this->makePerson($this->org, 'Blind Clerk', null, [
            $this->makeRole($this->org, 'Payment clerk', ['payment.view' => 'organization', 'payment.create' => 'organization']),
        ]);

        $this->actingFresh($clerk)->post(route('payments.store'), [
            'payable' => 'expense:'.$expense->id, 'amount' => '75', 'method' => 'cash', 'confidentiality' => 'internal',
        ])->assertForbidden();

        // Nor is it offered in the picker.
        $this->actingFresh($clerk)->get(route('payments.create'))->assertOk()->assertDontSee($expense->number);
    }

    #[Test]
    public function another_companys_expense_is_never_payable(): void
    {
        $other = $this->makeOrganization('Kestrel Payments');
        $this->provisionConfiguration($other);
        $theirManager = $this->makePerson($other, 'Kestrel Finance', null, [
            $this->makeRole($other, 'Finance manager', $this->financeGrants()),
        ]);
        $theirClaimant = $this->makePerson($other, 'Kestrel Claimant', null, [
            $this->makeRole($other, 'Employee', $this->claimantGrants()),
        ]);
        $foreign = $this->makeApprovedExpense($other, $theirClaimant, $theirManager, ['amount' => '10']);

        $this->actingFresh($this->manager)->post(route('payments.store'), [
            'payable' => 'expense:'.$foreign->id, 'amount' => '10', 'method' => 'cash', 'confidentiality' => 'internal',
        ])->assertSessionHasErrors('payable');

        $this->assertSame(0, $this->withinOrganization($other, fn () => Payment::query()->count()));
        $this->actingFresh($this->manager)->get(route('expenses.show', $foreign))->assertNotFound();
    }
}
