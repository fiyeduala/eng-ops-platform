<x-layouts.app :title="$visitor->number">
    <x-page-header :title="$visitor->full_name" :description="$visitor->number.($visitor->company_name ? ' · '.$visitor->company_name : '')">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('visitors.edit', $visitor) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>

    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h2 class="card-title">The visit</h2>
                <dl class="mt-3 grid gap-4 sm:grid-cols-2">
                    <x-register.fact label="Here to see" :value="$visitor->host?->fullName()"/>
                    <x-register.fact label="Department" :value="$visitor->orgUnit?->name"/>
                    <x-register.fact label="Site" :value="$visitor->location?->name"/>
                    <x-register.fact label="Project" :value="$visitor->project?->name"/>
                    <x-register.fact label="Reason" :value="$visitor->purpose_type ? ucfirst(str_replace('_', ' ', $visitor->purpose_type)) : null"/>
                    <x-register.fact label="How many people" :value="(string) $visitor->headcount()"/>
                    <x-register.fact label="Expected from" :value="$visitor->expected_from?->format('j M Y H:i')"/>
                    <x-register.fact label="Until" :value="$visitor->expected_to?->format('j M Y H:i')"/>
                </dl>

                @if ($visitor->purpose)
                    <p class="mt-4 whitespace-pre-line text-sm">{{ $visitor->purpose }}</p>
                @endif
            </div>

            @if ($visitor->requires_escort)
                <div class="card p-5">
                    <h2 class="card-title">Escort required</h2>
                    <p class="mt-2 text-sm">
                        {{ $visitor->escort?->fullName() ?? 'Nobody has been named yet. One has to be before this visitor can be signed in.' }}
                    </p>
                </div>
            @endif

            {{--
                The identity document number is never shown, here or anywhere else. Whether one was
                taken is a fact somebody may need; what it says is not (§67).
            --}}
            @if ($visitor->induction_completed || $visitor->id_document_type)
                <div class="card p-5">
                    <h2 class="card-title">At the gate</h2>
                    <dl class="mt-3 grid gap-4 sm:grid-cols-2">
                        <x-register.fact label="Badge" :value="$visitor->badge_number"/>
                        <x-register.fact label="Vehicle" :value="$visitor->vehicle_registration"/>
                        <x-register.fact label="Induction completed" :value="$visitor->induction_completed_on?->format('j M Y')"/>
                        <x-register.fact label="Induction valid until"
                                         :value="$visitor->induction_completed
                                             ? ($visitor->induction_valid_until?->format('j M Y') ?? 'Does not lapse')
                                             : null"/>
                        <x-register.fact label="Identity document" :value="$visitor->id_document_type ? $visitor->id_document_type.' — recorded, not shown' : null"/>
                    </dl>
                </div>
            @endif

            @if ($visitor->refusal_reason)
                <div class="card p-5">
                    <h2 class="card-title">Entry refused</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $visitor->refusal_reason }}</p>
                </div>
            @endif

            @if ($visitor->notes)
                <div class="card p-5"><h2 class="card-title">Notes</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $visitor->notes }}</p></div>
            @endif

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this visit."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$visitor->status?->label"/>
                    <x-register.fact label="Signed in" :value="$visitor->checked_in_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Signed in by" :value="$visitor->checkedInBy?->name"/>
                    <x-register.fact label="Signed out" :value="$visitor->checked_out_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Signed out by"
                                     :value="$visitor->auto_closed
                                         ? 'Nobody — closed at the end of the day'
                                         : $visitor->checkedOutBy?->name"/>
                    <x-register.fact label="Phone" :value="$visitor->phone"/>
                    <x-register.fact label="Email" :value="$visitor->email"/>
                </dl>

                @if ($visitor->auto_closed)
                    <p class="mt-4 text-xs text-[var(--text-muted)]">
                        This visitor was still shown on site at the end of the day and was signed out automatically.
                        Nobody witnessed them leaving, and the time above is the cut-off rather than a departure.
                    </p>
                @endif
            </div>

            {{--
                THE GATE. No submit button anywhere on this page, and no approve button: this is the
                one register in the phase that is not routed. Whether these appear is a permission the
                person at the desk holds, not a stage a record has reached.
            --}}
            @if ($canCheckIn)
                <form method="POST" action="{{ route('visitors.check-in', $visitor) }}" class="card space-y-3 p-5">@csrf
                    <h2 class="card-title">Sign in</h2>

                    <x-field label="Badge" name="badge_number">
                        <x-input name="badge_number" :value="old('badge_number')"/>
                    </x-field>

                    <x-field label="Vehicle" name="vehicle_registration">
                        <x-input name="vehicle_registration" :value="old('vehicle_registration')"/>
                    </x-field>

                    <x-field label="How many arrived" name="party_size" help="Correct it if the party is a different size from the booking.">
                        <x-input type="number" name="party_size" min="1" max="200" :value="old('party_size', $visitor->party_size ?? 1)"/>
                    </x-field>

                    @if ($visitor->requires_escort)
                        <x-field label="Escorted by" name="escort_employee_id" help="Required — this visitor must not be left unaccompanied.">
                            <x-select name="escort_employee_id" :options="$escorts" :selected="old('escort_employee_id', $visitor->escort_employee_id)" placeholder="Choose somebody"/>
                        </x-field>
                    @endif

                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="induction_completed" value="0">
                        <input type="checkbox" name="induction_completed" value="1" class="rounded border-[var(--border)]"
                               @checked(old('induction_completed', $visitor->induction_completed))>
                        <span>Site induction completed</span>
                    </label>

                    <button class="btn btn-primary btn-sm w-full">Sign in</button>
                </form>
            @endif

            @if ($canCheckOut)
                <form method="POST" action="{{ route('visitors.check-out', $visitor) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Sign out</h2>
                    <p class="text-xs text-[var(--text-muted)]">
                        This closes the visit for good. Somebody coming back after lunch is booked in again as a new visit —
                        the roll call has to stay readable.
                    </p>
                    <button class="btn btn-primary btn-sm w-full">Sign out</button>
                </form>
            @endif

            @if ($canRefuse)
                <form method="POST" action="{{ route('visitors.refuse', $visitor) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Refuse entry</h2>
                    <x-textarea name="reason" rows="2" placeholder="Why are they being turned away?" required></x-textarea>
                    <button class="btn btn-ghost btn-sm w-full">Refuse entry</button>
                </form>
            @endif

            @if ($canCancel)
                <div class="card space-y-4 p-5">
                    <form method="POST" action="{{ route('visitors.cancel', $visitor) }}" class="space-y-2">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Why is the visit not going ahead?"></x-textarea>
                        <button class="btn btn-ghost btn-sm w-full">Cancel the visit</button>
                    </form>

                    <form method="POST" action="{{ route('visitors.no-show', $visitor) }}">@csrf
                        <button class="btn btn-ghost btn-sm w-full">They did not arrive</button>
                    </form>
                </div>
            @endif

            @if ($canDelete)
                <div class="card p-5">
                    <x-delete-form :action="route('visitors.destroy', $visitor)" label="Delete booking" confirm="Delete this booking?"/>
                </div>
            @endif
        </div>
    </div>

    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$visitor" class="card mt-5"/>

</x-layouts.app>
