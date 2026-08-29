<?php

declare(strict_types=1);

namespace App\Engines\Access;

use App\Models\Organization;
use RuntimeException;

/**
 * Holds the organization the current request/job is operating inside.
 *
 * Bound as a singleton and set by EnsureOrganizationContext middleware (web) or explicitly by
 * console commands and queued jobs. Gate 1 of the access model reads from here.
 *
 * Nothing in the application should read organization_id from user input. Ever.
 */
class OrganizationContext
{
    private ?Organization $organization = null;

    /**
     * When true, the tenant global scope is suspended. Used only by platform-level operations
     * (tenant provisioning, cross-tenant maintenance commands) and never reachable from HTTP.
     */
    private bool $suspended = false;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function idOrFail(): int
    {
        return $this->id() ?? throw new RuntimeException(
            'No organization context is bound. A tenant-scoped model was touched outside a '
            .'request or job that established context.'
        );
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Run a callback with the tenant scope suspended.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
    }

    /**
     * Run a callback inside a different organization, restoring the previous one afterwards.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function within(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }

    public function forget(): void
    {
        $this->organization = null;
        $this->suspended = false;
    }
}
