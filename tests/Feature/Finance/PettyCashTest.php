<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Organization;
use App\Models\PettyCashAccount;
use App\Models\PettyCashTransaction;
use App\Models\ProjectCost;
use App\Models\User;
use App\Services\PettyCashService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsFinance;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class PettyCashTest extends TestCase
{
    use BuildsFinance, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Meridian Cash');
        $this->provisionConfiguration($this->org);
        $this->manager = $this->makePerson($this->org, 'Ngozi Finance', null, [
            $this->makeRole($this->org, 'Finance manager', $this->financeGrants()),
        ]);
    }

    #[Test]
    public function cash_moves_only_within_the_float_and_the_balance_and_every_payout_is_a_project_cost(): void
    {
        $project = $this->makeProject($this->org, $this->manager, 'Substation build');
        $tin = $this->makeTin($this->org, $this->manager, ['project_id' => $project->id, 'float_amount' => '500']);

        $this->actingFresh($this->manager)->post(route('petty-cash.replenish', $tin), [
            'amount' => '500.01', 'transacted_on' => now()->toDateString(), 'description' => 'Too much',
        ])->assertSessionHasErrors('replenish');
        $this->actingFresh($this->manager)->post(route('petty-cash.replenish', $tin), [
            'amount' => '400', 'transacted_on' => now()->toDateString(), 'description' => 'Cash from bank',
            'category' => 'travel',
        ])->assertSessionHasErrors('category');
        $this->actingFresh($this->manager)->post(route('petty-cash.replenish', $tin), [
            'amount' => '400', 'transacted_on' => now()->toDateString(), 'description' => 'Cash from bank',
        ])->assertSessionHasNoErrors();

        $payout = ['transacted_on' => now()->toDateString(), 'description' => 'Diesel for generator', 'category' => 'fuel', 'payee_name' => 'Roadside station'];
        $this->actingFresh($this->manager)->post(route('petty-cash.disburse', $tin), [...$payout, 'amount' => '400.01'])
            ->assertSessionHasErrors('disburse');
        $this->actingFresh($this->manager)->post(route('petty-cash.disburse', $tin), [...$payout, 'amount' => '150'])
            ->assertSessionHasNoErrors();

        $tin = $this->withinOrganization($this->org, fn () => $tin->fresh());
        $this->assertSame('250.00', $tin->balance);
        $this->assertTrue($this->withinOrganization($this->org, fn () => $tin->balanceMatchesLedger()));
        $this->assertSame('250.00', $tin->headroom());

        $voucher = $this->withinOrganization($this->org, fn () => PettyCashTransaction::query()->where('type', 'disbursement')->firstOrFail());
        $this->assertSame('-150.00', $voucher->amount);
        $this->assertSame('250.00', $voucher->balance_after);

        $cost = $this->withinOrganization($this->org, fn () => ProjectCost::query()
            ->where('source_type', $voucher->getMorphClass())->where('source_id', $voucher->id)->firstOrFail());
        $this->assertSame(150.0, (float) $cost->amount);
        $this->assertSame($project->id, $cost->project_id);
        $this->assertSame('fuel', $cost->cost_type);

        // A top-up is not a cost.
        $this->assertSame(1, $this->withinOrganization($this->org, fn () => ProjectCost::query()->count()));

        $this->actingFresh($this->manager)->get(route('petty-cash.show', $tin))
            ->assertOk()->assertSee($voucher->number)->assertSee('Diesel for generator');
    }

    #[Test]
    public function a_payout_above_the_company_limit_has_to_go_through_an_expense_claim(): void
    {
        $this->financeSetting($this->org, 'petty_cash.single_disbursement_limit', '100');
        $tin = $this->makeTin($this->org, $this->manager, ['float_amount' => '0']);
        $this->withinOrganization($this->org, fn () => app(PettyCashService::class)->replenish($tin, ['amount' => '1000', 'description' => 'Float'], $this->manager));

        $payout = ['transacted_on' => now()->toDateString(), 'description' => 'Tyre', 'category' => 'repairs'];
        $this->actingFresh($this->manager)->post(route('petty-cash.disburse', $tin), [...$payout, 'amount' => '100.01'])
            ->assertSessionHasErrors('disburse');
        $this->actingFresh($this->manager)->post(route('petty-cash.disburse', $tin), [...$payout, 'amount' => '100'])
            ->assertSessionHasNoErrors();

        // Zero float means no ceiling on top-ups.
        $this->assertNull($this->withinOrganization($this->org, fn () => $tin->fresh()->headroom()));
    }

    #[Test]
    public function a_mistake_is_reversed_once_by_its_own_voucher_and_the_cost_comes_back_off_the_project(): void
    {
        $project = $this->makeProject($this->org, $this->manager, 'Road works');
        $tin = $this->makeTin($this->org, $this->manager, ['project_id' => $project->id]);
        $service = app(PettyCashService::class);

        $payout = $this->withinOrganization($this->org, function () use ($service, $tin) {
            $service->replenish($tin, ['amount' => '300', 'description' => 'Float'], $this->manager);

            return $service->disburse($tin, ['amount' => '120', 'description' => 'Wrong tin', 'category' => 'meals'], $this->manager);
        });

        $this->actingFresh($this->manager)->post(route('petty-cash.reverse', $payout), ['reason' => ''])
            ->assertSessionHasErrors();
        $this->actingFresh($this->manager)->post(route('petty-cash.reverse', $payout), ['reason' => 'Paid from the wrong tin'])
            ->assertSessionHasNoErrors();
        $this->actingFresh($this->manager)->post(route('petty-cash.reverse', $payout), ['reason' => 'Again'])
            ->assertSessionHasErrors('reverse');

        $tin = $this->withinOrganization($this->org, fn () => $tin->fresh());
        $this->assertSame('300.00', $tin->balance);
        $this->assertTrue($this->withinOrganization($this->org, fn () => $tin->balanceMatchesLedger()));

        $net = $this->withinOrganization($this->org, fn () => (float) ProjectCost::query()->where('project_id', $project->id)->sum('amount'));
        $this->assertSame(0.0, $net);

        $reversal = $this->withinOrganization($this->org, fn () => PettyCashTransaction::query()->where('type', 'reversal')->firstOrFail());
        $this->assertSame($payout->id, $reversal->reversal_of_id);
        $this->assertSame('120.00', $reversal->amount);
    }

    #[Test]
    public function a_top_up_whose_cash_has_been_spent_cannot_be_reversed_below_zero(): void
    {
        $tin = $this->makeTin($this->org, $this->manager);
        $service = app(PettyCashService::class);

        $topUp = $this->withinOrganization($this->org, function () use ($service, $tin) {
            $topUp = $service->replenish($tin, ['amount' => '200', 'description' => 'Float'], $this->manager);
            $service->disburse($tin, ['amount' => '150', 'description' => 'Courier', 'category' => 'courier'], $this->manager);

            return $topUp;
        });

        $this->actingFresh($this->manager)->post(route('petty-cash.reverse', $topUp), ['reason' => 'Wrong amount'])
            ->assertSessionHasErrors('reverse');
        $this->assertSame('50.00', $this->withinOrganization($this->org, fn () => $tin->fresh()->balance));
    }

    #[Test]
    public function vouchers_are_append_only_and_a_closed_tin_moves_no_cash(): void
    {
        $tin = $this->makeTin($this->org, $this->manager);
        $voucher = $this->withinOrganization($this->org, fn () => app(PettyCashService::class)
            ->replenish($tin, ['amount' => '10', 'description' => 'Float'], $this->manager));

        $this->withinOrganization($this->org, function () use ($voucher) {
            try {
                $voucher->forceFill(['amount' => '9999'])->save();
                $this->fail('A voucher must not be editable.');
            } catch (\LogicException) {
            }

            try {
                $voucher->delete();
                $this->fail('A voucher must not be deletable.');
            } catch (\LogicException) {
            }
        });

        $this->withinOrganization($this->org, fn () => PettyCashAccount::query()->whereKey($tin->id)->update(['is_active' => false]));

        $this->actingFresh($this->manager)->post(route('petty-cash.replenish', $tin), [
            'amount' => '5', 'transacted_on' => now()->toDateString(), 'description' => 'Late top-up',
        ])->assertSessionHasErrors(['replenish' => 'This petty cash account is closed.']);
        $this->assertSame('10.00', $this->withinOrganization($this->org, fn () => $tin->fresh()->balance));
    }

    #[Test]
    public function the_custodian_reaches_their_tin_and_nobody_else_without_a_grant_does(): void
    {
        $custodian = $this->makePerson($this->org, 'Musa Site Clerk', null, [
            $this->makeRole($this->org, 'Custodian', [
                'petty_cash.view' => 'assigned', 'petty_cash.disburse' => 'assigned',
            ]),
        ]);
        $stranger = $this->makePerson($this->org, 'No Cash Access');
        $tin = $this->makeTin($this->org, $this->manager, ['custodian_id' => $custodian->id]);
        $other = $this->makeTin($this->org, $this->manager, ['code' => 'PC-HQ', 'name' => 'Head office tin']);

        $this->actingFresh($custodian)->get(route('petty-cash.show', $tin))->assertOk();
        $this->actingFresh($custodian)->get(route('petty-cash.show', $other))->assertForbidden();
        $this->actingFresh($custodian)->get(route('petty-cash.index'))->assertOk()
            ->assertSee('Site office tin')->assertDontSee('Head office tin');
        $this->actingFresh($stranger)->get(route('petty-cash.show', $tin))->assertForbidden();

        // Holding the tin is not authority to reverse its vouchers or top it up.
        $this->actingFresh($custodian)->post(route('petty-cash.replenish', $tin), [
            'amount' => '5', 'transacted_on' => now()->toDateString(), 'description' => 'Self top-up',
        ])->assertForbidden();
    }
}
