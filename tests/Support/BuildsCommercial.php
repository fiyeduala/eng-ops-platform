<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ClientService;
use App\Services\ContractService;
use App\Services\VendorService;

/**
 * Fixtures for the three commercial registers.
 *
 * Everything goes through the module's own service for the same reason {@see BuildsEngineering}
 * does: the number, the opening status, the creator's follower row and — on a contract — the
 * resolved party morph all come out of the service. A vendor assembled with `Vendor::create()`
 * would have no number and no status, so a test asserting "an unapproved vendor cannot be ordered
 * from" would be asserting something about the fixture rather than about the rule.
 *
 * `makeVendor()` DELIBERATELY LEAVES THE VENDOR UNASSESSED. It comes back on the register at the
 * opening status with no prequalification dates, which is what a real vendor looks like ten seconds
 * after somebody adds it. That is NOT the same as unusable — see
 * CommercialLifecycleTest::a_vendor_with_no_assessment_recorded_is_still_orderable() for why the
 * platform's default is permissive here. Tests that want an assessed supplier say so by calling
 * `makeApprovedVendor()`, which keeps the distinction between "we have their details" and "we have
 * assessed them" visible in the test that depends on it.
 *
 * Companion to {@see BuildsTenants}, which this assumes is also in use.
 */
trait BuildsCommercial
{
    /** @param  array<string,mixed>  $attributes */
    protected function makeVendor(
        Organization $organization,
        User $actor,
        string $name,
        array $attributes = [],
    ): Vendor {
        $this->provisionConfiguration($organization);

        /** @var Vendor */
        return $this->withinOrganization($organization, fn () => app(VendorService::class)->create([
            'name' => $name,
            ...$attributes,
        ], $actor));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeClient(
        Organization $organization,
        User $actor,
        string $name,
        array $attributes = [],
    ): Client {
        $this->provisionConfiguration($organization);

        /** @var Client */
        return $this->withinOrganization($organization, fn () => app(ClientService::class)->create([
            'name' => $name,
            ...$attributes,
        ], $actor));
    }

    /**
     * A contract, in whichever direction the caller asks for.
     *
     * The party arrives as the two form fields rather than as a model, on purpose: that is the
     * shape the service has to defend against, so the fixtures exercise the same path the
     * controller does.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeContract(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Contract {
        $this->provisionConfiguration($organization);

        /** @var Contract */
        return $this->withinOrganization($organization, fn () => app(ContractService::class)->create([
            'title' => $title,
            'direction' => 'client',
            ...$attributes,
        ], $actor));
    }

    /**
     * A supplier who can actually be ordered from today.
     *
     * Approving the status is left to the caller where it matters; what this guarantees is the part
     * that expires on its own — an in-date prequalification, which is the condition
     * Vendor::canBeUsed() is most often failing on in a real register.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeApprovedVendor(
        Organization $organization,
        User $actor,
        string $name,
        array $attributes = [],
    ): Vendor {
        return $this->makeVendor($organization, $actor, $name, [
            'prequalified_on' => now()->subMonths(2)->toDateString(),
            'prequalification_expires_on' => now()->addYear()->toDateString(),
            ...$attributes,
        ]);
    }
}
