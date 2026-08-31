<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ChangeRequest;
use App\Models\Deliverable;
use App\Models\Drawing;
use App\Models\Organization;
use App\Models\Rfi;
use App\Models\Submittal;
use App\Models\User;
use App\Services\ChangeRequestService;
use App\Services\DeliverableService;
use App\Services\DrawingService;
use App\Services\RfiService;
use App\Services\SubmittalService;

/**
 * Fixtures for the five engineering registers.
 *
 * Every one of these goes through the module's own service rather than `Model::create()`, for the
 * same reason `makeProject()` does: the number, the opening status and the creator's follower row
 * all come out of the service, and a record assembled by hand would have none of them. A test that
 * then asserted "it cannot be edited once reviewed" would be asserting something about a fixture.
 *
 * Companion to {@see BuildsTenants}, which this assumes is also in use — everything here needs the
 * organization context and the provisioned configuration that trait supplies.
 */
trait BuildsEngineering
{
    /** @param  array<string,mixed>  $attributes */
    protected function makeDeliverable(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Deliverable {
        $this->provisionConfiguration($organization);

        /** @var Deliverable */
        return $this->withinOrganization($organization, fn () => app(DeliverableService::class)->create([
            'title' => $title,
            ...$attributes,
        ], $actor));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeDrawing(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Drawing {
        $this->provisionConfiguration($organization);

        /** @var Drawing */
        return $this->withinOrganization($organization, fn () => app(DrawingService::class)->create([
            'title' => $title,
            ...$attributes,
        ], $actor));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeRfi(
        Organization $organization,
        User $actor,
        string $subject,
        array $attributes = [],
    ): Rfi {
        $this->provisionConfiguration($organization);

        /** @var Rfi */
        return $this->withinOrganization($organization, fn () => app(RfiService::class)->create([
            'subject' => $subject,
            'question' => 'Please confirm the detail referred to in '.$subject.'.',
            ...$attributes,
        ], $actor));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeSubmittal(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Submittal {
        $this->provisionConfiguration($organization);

        /** @var Submittal */
        return $this->withinOrganization($organization, fn () => app(SubmittalService::class)->create([
            'title' => $title,
            'type' => 'material',
            ...$attributes,
        ], $actor));
    }

    /** @param  array<string,mixed>  $attributes */
    protected function makeChangeRequest(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): ChangeRequest {
        $this->provisionConfiguration($organization);

        /** @var ChangeRequest */
        return $this->withinOrganization($organization, fn () => app(ChangeRequestService::class)->create([
            'title' => $title,
            'description' => 'The scope covered by '.$title.' changes as follows.',
            ...$attributes,
        ], $actor));
    }

    /**
     * A package with one line on it, which is the minimum that can be sent.
     *
     * @param  array<string,mixed>  $attributes
     */
    protected function makeSubmittalWithItem(
        Organization $organization,
        User $actor,
        string $title,
        array $attributes = [],
    ): Submittal {
        $submittal = $this->makeSubmittal($organization, $actor, $title, $attributes);

        $this->withinOrganization($organization, fn () => app(SubmittalService::class)->addItem(
            $submittal,
            null,
            ['description' => 'Rebar mill certificate', 'reference' => 'MC-01'],
            $actor,
        ));

        return $submittal->refresh();
    }
}
