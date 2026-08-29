<?php

declare(strict_types=1);

namespace App\Engines\Audit;

use Illuminate\Http\Request;

/**
 * Request metadata captured once and reused by every audit entry in the request.
 *
 * Console commands and queued jobs get a synthetic context so scheduler-driven changes are
 * still attributable rather than appearing to come from nowhere.
 */
class AuditContext
{
    private ?string $ipAddress = null;

    private ?string $userAgent = null;

    private ?string $url = null;

    private ?string $method = null;

    private ?string $source = null;

    public function fromRequest(Request $request): void
    {
        $this->ipAddress = $request->ip();
        $this->userAgent = substr((string) $request->userAgent(), 0, 1000);
        $this->url = substr($request->fullUrl(), 0, 2000);
        $this->method = $request->method();
        $this->source = 'http';
    }

    public function fromConsole(string $command): void
    {
        $this->url = 'console:'.$command;
        $this->method = 'CLI';
        $this->source = 'console';
    }

    public function fromJob(string $job): void
    {
        $this->url = 'job:'.$job;
        $this->method = 'QUEUE';
        $this->source = 'queue';
    }

    /** @return array<string,?string> */
    public function toArray(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'url' => $this->url,
            'method' => $this->method,
        ];
    }

    public function source(): ?string
    {
        return $this->source;
    }
}
