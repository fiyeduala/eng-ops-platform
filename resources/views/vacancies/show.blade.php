<x-layouts.app :title="$vacancy->number">
    <x-page-header :title="$vacancy->title" :description="$vacancy->number">
        <x-slot:actions>
            {{-- The decision is made in My Approvals, on the same engine as everything else. There
                 is deliberately no approve button here and no route behind one. --}}
            @if ($myApproval)<a href="{{ route('approvals.show', $myApproval) }}" class="btn btn-primary btn-sm">Decide in My Approvals</a>@endif
            @if ($canAddApplicant)<a href="{{ route('vacancies.applicants.create', $vacancy) }}" class="btn btn-secondary btn-sm">Record an application</a>@endif
            @if ($canEdit)<a href="{{ route('vacancies.edit', $vacancy) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    @if ($instance)
        <div class="mb-6"><x-workflow-progress :instance="$instance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Wanted</p><p class="mt-1 text-lg font-semibold">{{ $vacancy->openings }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Hired</p><p class="mt-1 text-lg font-semibold">{{ $vacancy->hired_count }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Still to fill</p><p class="mt-1 text-lg font-semibold">{{ $vacancy->remainingOpenings() }}</p></div>
            </div>

            @if ($vacancy->summary)
                <div class="card p-5"><h2 class="card-title">What the job is</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $vacancy->summary }}</p></div>
            @endif
            @if ($vacancy->requirements)
                <div class="card p-5"><h2 class="card-title">What the person needs</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $vacancy->requirements }}</p></div>
            @endif
            @if ($vacancy->decision_reason)
                <div class="card p-5"><h2 class="card-title">Approver's comment</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $vacancy->decision_reason }}</p></div>
            @endif
            @if ($vacancy->closure_reason)
                <div class="card p-5"><h2 class="card-title">Why it was closed</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $vacancy->closure_reason }}</p></div>
            @endif

            <div class="card p-5">
                <h2 class="card-title">Applications</h2>
                @if ($applicants->isEmpty())
                    <p class="mt-2 text-sm text-[var(--text-muted)]">Nobody has applied yet, or none of the applications are within your scope.</p>
                @else
                    <table class="mt-3 w-full text-sm">
                        <thead><tr class="text-left text-xs text-[var(--text-muted)]"><th class="pb-2">Number</th><th class="pb-2">Name</th><th class="pb-2">Source</th><th class="pb-2">Status</th></tr></thead>
                        <tbody>
                            @foreach ($applicants as $applicant)
                                <tr class="border-t border-[var(--border)]">
                                    <td class="py-2"><a class="font-mono hover:text-brand-600" href="{{ route('applicants.show', $applicant) }}">{{ $applicant->number }}</a></td>
                                    <td class="py-2">{{ $applicant->fullName() }}</td>
                                    <td class="py-2">{{ $applicant->source ?? '—' }}</td>
                                    <td class="py-2">@if ($applicant->status)<x-badge :tone="$applicant->status->tone()">{{ $applicant->status->label }}</x-badge>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this vacancy."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$vacancy->status?->label"/>
                    <x-register.fact label="Department" :value="$vacancy->orgUnit?->name"/>
                    <x-register.fact label="Position" :value="$vacancy->position?->title"/>
                    <x-register.fact label="Location" :value="$vacancy->location?->name"/>
                    <x-register.fact label="Project" :value="$vacancy->project?->name"/>
                    <x-register.fact label="Employment type" :value="$vacancy->employment_type"/>
                    <x-register.fact label="Asked by" :value="$vacancy->requester?->name"/>
                    <x-register.fact label="Hiring manager" :value="$vacancy->hiringManager?->name"/>
                    <x-register.fact label="Target start" :value="$vacancy->target_start_date?->format('j M Y')"/>
                    <x-register.fact label="Applications close" :value="$vacancy->closes_on?->format('j M Y')"/>
                    <x-register.fact label="Submitted" :value="$vacancy->submitted_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Decided" :value="$vacancy->decided_at?->format('j M Y H:i')"/>
                </dl>

                @if ($canSubmit)
                    <form method="POST" action="{{ route('vacancies.submit', $vacancy) }}" class="mt-5">@csrf
                        <button class="btn btn-primary btn-sm w-full">Send for approval</button>
                    </form>
                @endif

                @if ($canClose)
                    <form method="POST" action="{{ route('vacancies.close', $vacancy) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Why is the company no longer recruiting for this?"></x-textarea>
                        <button class="btn btn-danger btn-sm w-full">Close vacancy</button>
                    </form>
                @endif
            </div>

            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('vacancies.destroy', $vacancy)" label="Delete draft" confirm="Delete this draft vacancy?"/></div>
            @endif
        </div>
    </div>

    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$vacancy" class="card mt-5"/>

</x-layouts.app>
