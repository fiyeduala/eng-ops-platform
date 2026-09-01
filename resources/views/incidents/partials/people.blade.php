{{--
    Who was involved.

    THE MEDICAL COLUMNS ARE INSIDE `@if ($canViewMedical)` — the header cells, the body cells and the
    add-person fields alike. There is no version of this table where the markup exists and the values
    are blanked, because blanking is a display trick and the person reading the page source is not
    fooled by it (§68).

    A PERSON IS EITHER AN ACCOUNT OR A NAME. Contractors, visitors and members of the public get hurt
    on sites too, and a register that can only name employees quietly under-reports exactly the group
    with the worst statistics. `user_id` is optional; `person_name` is not.

    Expects (from incidents/show): $incident, $canViewMedical, $canAddPeople, $involvements,
                                   $treatments, $people
--}}
<div class="card">
    <div class="card-header">
        <h3 class="card-title">People involved</h3>
        <span class="text-xs text-[var(--text-muted)]">{{ $incident->people->count() }} recorded</span>
    </div>

    @if ($incident->people->isEmpty())
        <p class="p-5 text-sm text-[var(--text-muted)]">
            Nobody has been recorded against this incident. An incident with no people is a normal
            thing — most near misses have none — but if somebody was hurt, they belong here.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Company</th>
                        <th>Involvement</th>
                        @if ($canViewMedical)
                            <th>Injury</th>
                            <th>Body part</th>
                            <th>Treatment</th>
                            <th class="text-right">Days lost</th>
                            <th>Back at work</th>
                        @endif
                        @if ($canAddPeople)
                            <th class="w-px"></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($incident->people as $person)
                        <tr>
                            <td>
                                <span class="font-medium text-[var(--text-strong)]">{{ $person->displayName() }}</span>
                                @if ($person->occupation)
                                    <span class="block text-xs text-[var(--text-muted)]">{{ $person->occupation }}</span>
                                @endif
                            </td>
                            <td class="text-[var(--text-muted)]">{{ $person->company ?: '—' }}</td>
                            <td>
                                @if ($person->involvement)
                                    <x-badge tone="neutral">{{ $person->involvement }}</x-badge>
                                @else
                                    <span class="text-[var(--text-muted)]">—</span>
                                @endif
                            </td>
                            @if ($canViewMedical)
                                <td class="text-[var(--text-muted)]">{{ $person->injury_type ?: '—' }}</td>
                                <td class="text-[var(--text-muted)]">{{ $person->body_part ?: '—' }}</td>
                                <td class="text-[var(--text-muted)]">{{ $person->treatment ?: '—' }}</td>
                                <td class="text-right tabular-nums">{{ $person->days_lost ?? '—' }}</td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $person->returned_to_work_on?->format('j M Y') ?: '—' }}
                                </td>
                            @endif
                            @if ($canAddPeople)
                                <td class="text-right">
                                    <x-delete-form :action="route('incidents.people.destroy', [$incident, $person])"
                                                   label="Remove"
                                                   :confirm="'Remove '.$person->displayName().'?'"/>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @unless ($canViewMedical)
            <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                Injury and treatment detail is held separately and is not shown to you.
            </p>
        @endunless
    @endif
</div>

@if ($canAddPeople)
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Add someone</h3>
        </div>
        <form method="POST" action="{{ route('incidents.people.store', $incident) }}" class="space-y-5 p-5">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <x-field label="Account" name="user_id"
                         help="Leave blank for a contractor, visitor or member of the public.">
                    <x-select name="user_id" :options="$people" :selected="old('user_id')"
                              placeholder="Not a system user"/>
                </x-field>

                <x-field label="Name" name="person_name" required>
                    <x-input name="person_name" :value="old('person_name')" required/>
                </x-field>

                <x-field label="Company" name="company">
                    <x-input name="company" :value="old('company')"/>
                </x-field>

                <x-field label="Occupation" name="occupation">
                    <x-input name="occupation" :value="old('occupation')"/>
                </x-field>

                <x-field label="How they were involved" name="involvement">
                    <x-select name="involvement" :options="$involvements" :selected="old('involvement')"
                              placeholder="Choose one"/>
                </x-field>
            </div>

            @if ($canViewMedical)
                <div class="space-y-4 border-t border-[var(--surface-border)] pt-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                        Injury detail
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field label="Injury" name="injury_type">
                            <x-input name="injury_type" :value="old('injury_type')"/>
                        </x-field>

                        <x-field label="Body part" name="body_part">
                            <x-input name="body_part" :value="old('body_part')"/>
                        </x-field>

                        <x-field label="Treatment" name="treatment">
                            <x-select name="treatment" :options="$treatments" :selected="old('treatment')"
                                      placeholder="Choose one"/>
                        </x-field>

                        <x-field label="Days lost" name="days_lost">
                            <x-input type="number" name="days_lost" min="0" :value="old('days_lost')"/>
                        </x-field>

                        <x-field label="Back at work" name="returned_to_work_on">
                            <x-input type="date" name="returned_to_work_on" :value="old('returned_to_work_on')"/>
                        </x-field>
                    </div>
                </div>
            @endif

            <x-field label="Notes" name="notes">
                <x-textarea name="notes" rows="2">{{ old('notes') }}</x-textarea>
            </x-field>

            <button type="submit" class="btn btn-primary">Add to the incident</button>
        </form>
    </div>
@endif
