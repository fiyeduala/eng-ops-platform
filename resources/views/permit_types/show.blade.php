{{--
    One permit type.

    THE TEMPLATE IS READ-ONLY HERE, as it is everywhere except the edit form. Reading a checklist and
    changing it are different acts by different people: `permit.view` gets this page so an issuer can
    see what a type will put on their permit, and `permit.manage` gets the edit form.

    THE PERMIT COUNT IS SHOWN NEXT TO THE DELETE CARD ON PURPOSE. The policy refuses to delete a type
    with permits against it, and a screen offering a button it knows will be refused is worse than
    one that explains why.

    Expects: $type, $precautions, $permitCount, $timeline, $canEdit, $canDelete
--}}
<x-layouts.app :title="$type->displayName()">

    <x-page-header :title="$type->name" :description="$type->code">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('permit-types.edit', $type) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
            <a href="{{ route('permit-types.index') }}" class="btn btn-ghost btn-sm">All types</a>
        </x-slot:actions>
    </x-page-header>

    @unless ($type->is_active)
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm font-semibold text-[var(--text-strong)]">Retired</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                This type no longer appears when somebody raises a permit. The
                {{ $permitCount }} already filed under it are unaffected.
            </p>
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            @if ($type->description)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">What it covers</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm leading-relaxed text-[var(--text-body)]">{{ $type->description }}</p>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Precautions</h3>
                    <p class="card-description">
                        Copied onto every new permit of this type. Never applied backwards.
                    </p>
                </div>

                @if ($precautions === [])
                    <x-empty-state icon="lock" title="No precautions on this template"
                                   description="Permits of this type will arrive with an empty checklist and can be issued without confirming anything. That is almost never what anybody wants.">
                        @if ($canEdit)
                            <x-slot:actions>
                                <a href="{{ route('permit-types.edit', $type) }}" class="btn btn-primary btn-sm">Add some</a>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                @else
                    <ol class="divide-y divide-[var(--surface-border)]">
                        @foreach ($precautions as $i => $precaution)
                            <li class="flex items-start gap-3 p-4">
                                <span class="mt-0.5 w-5 shrink-0 text-right font-mono text-xs text-[var(--text-muted)]">
                                    {{ $i + 1 }}
                                </span>
                                <p class="min-w-0 flex-1 text-sm text-[var(--text-body)]">
                                    {{ $precaution['description'] }}
                                </p>
                                @if ($precaution['is_required'])
                                    <x-badge tone="warning">Blocks issue</x-badge>
                                @else
                                    <x-badge tone="neutral">Advisory</x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            <x-timeline :entries="$timeline" title="History"
                        empty="This type has not been changed since it was added."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Code" :value="$type->code" mono/>
                    <x-register.fact label="State">
                        @if ($type->is_active)
                            <x-badge tone="success">In use</x-badge>
                        @else
                            <x-badge tone="neutral">Retired</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Default duration"
                                     :value="$type->default_duration_hours ? $type->default_duration_hours.' hours' : null"/>
                    <x-register.fact label="Gas test" :value="$type->requires_gas_test ? 'Required before issue' : 'Not required'"/>
                    <x-register.fact label="Isolation" :value="$type->requires_isolation ? 'Required' : 'Not required'"/>
                    <x-register.fact label="Precautions" :value="(string) count($precautions)"/>
                    <x-register.fact label="Permits raised" :value="(string) $permitCount"/>
                    <x-register.fact label="Order" :value="(string) $type->sort_order"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        @if ($permitCount > 0)
                            <p class="text-sm text-[var(--text-muted)]">
                                {{ $permitCount }} {{ Str::plural('permit', $permitCount) }} were raised
                                under this type, so it cannot be deleted — the permits would lose their
                                heading. Untick "in use" on the edit form to retire it instead.
                            </p>
                        @else
                            <p class="text-sm text-[var(--text-muted)]">
                                Nothing has been raised under this type, so removing it loses nothing.
                            </p>
                            <x-delete-form :action="route('permit-types.destroy', $type)"
                                           label="Delete this type"
                                           confirm="Delete this permit type?"/>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
