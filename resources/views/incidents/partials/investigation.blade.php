{{--
    Why it happened — or the offer to find out.

    THE INVESTIGATION IS OPENED FROM THE INCIDENT AND NOWHERE ELSE. There is no `incident_id` field on
    this form; the incident is the route parameter. Accepting it from the payload would let somebody
    staple an investigation onto an incident they cannot open and read its number, title and project
    straight back off the investigation page (§84, §94).

    THE FORM DISAPPEARS ONCE ONE EXISTS, and a second POST is refused by the controller anyway. One
    incident, one investigation — the workflow engine can fire the same configured action twice when
    a stage is re-entered, and the refusal is what makes that harmless.

    THE HIDDEN CLASSIFICATION IS NOT TRUSTED. It is here so the form posts something the request will
    accept; the service sets the real value from the incident and the controller clamps anything more
    open back down to it, so editing this field in the browser buys nothing (§84).

    Expects (from incidents/show): $incident, $canInvestigate, $people
--}}
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Investigation</h3>
    </div>

    @if ($incident->investigation)
        @php $investigation = $incident->investigation; @endphp
        <div class="space-y-4 p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <a href="{{ route('investigations.show', $investigation) }}"
                       class="font-mono text-sm font-medium text-[var(--brand-600)] hover:underline">
                        {{ $investigation->number }}
                    </a>
                    <p class="mt-0.5 text-sm text-[var(--text-body)]">{{ $investigation->title }}</p>
                </div>
                @if ($investigation->status)
                    <x-badge :tone="$investigation->status->tone()">{{ $investigation->status->label }}</x-badge>
                @endif
            </div>

            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4 sm:block">
                    <dt class="text-[var(--text-muted)]">Led by</dt>
                    <dd class="text-[var(--text-body)]">{{ $investigation->lead?->name ?? 'Nobody yet' }}</dd>
                </div>
                <div class="flex justify-between gap-4 sm:block">
                    <dt class="text-[var(--text-muted)]">Due</dt>
                    <dd class="text-[var(--text-body)]">
                        {{ $investigation->due_on?->format('j M Y') ?? 'No date set' }}
                    </dd>
                </div>
            </dl>

            @unless ($investigation->hasRootCause())
                <p class="text-sm text-amber-700 dark:text-amber-400">
                    No root cause has been recorded yet. It cannot be completed without one.
                </p>
            @endunless
        </div>
    @elseif ($canInvestigate)
        <form method="POST" action="{{ route('incidents.investigations.store', $incident) }}"
              class="space-y-5 p-5">
            @csrf

            <p class="text-sm text-[var(--text-muted)]">
                The incident's project, department and classification are carried across — you are
                only saying who is looking into it and by when.
            </p>

            <x-field label="Title" name="title" required>
                <x-input name="title" required
                         :value="old('title', 'Investigation into '.$incident->number)"/>
            </x-field>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Led by" name="lead_id"
                         help="They are notified as soon as this is opened.">
                    <x-select name="lead_id" :options="$people" :selected="old('lead_id')"
                              placeholder="Assign later"/>
                </x-field>

                <x-field label="Due by" name="due_on">
                    <x-input type="date" name="due_on" :value="old('due_on')"/>
                </x-field>
            </div>

            <input type="hidden" name="confidentiality"
                   value="{{ $incident->confidentiality?->value ?? 'restricted' }}">

            <button type="submit" class="btn btn-primary">Open an investigation</button>
        </form>
    @else
        <p class="p-5 text-sm text-[var(--text-muted)]">
            No investigation has been opened into this incident.
        </p>
    @endif
</div>
