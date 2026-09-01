{{--
    The permit catalogue.

    NOT A REGISTER. There is no number, no status, no workflow and no paginator — a permit type is a
    row in a settings table, and dressing it up as a record would suggest it moves through a
    lifecycle it does not have. Everything is on one page, ordered the way an administrator arranged
    it rather than by when it was added.

    THE PERMIT COUNT IS THE WARNING. A type with permits against it cannot be deleted and should not
    be quietly retyped, so the number is shown here rather than only on the type's own page.

    Expects: $types, $canManage
--}}
<x-layouts.app title="Permit types">

    <x-page-header
        title="Permit types"
        description="The kinds of permit this company issues, and the precautions each one starts with.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('permit-types.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a type
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($types->isEmpty())
        <div class="card overflow-hidden">
            <x-empty-state icon="lock" title="No permit types yet"
                           description="Until a type exists, permits can still be raised — they just arrive with an empty precaution list. Add the permits this company actually issues: hot work, confined space, excavation, working at height, whatever they are.">
                @if ($canManage)
                    <x-slot:actions>
                        <a href="{{ route('permit-types.create') }}" class="btn btn-primary btn-sm">Add the first type</a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Type</th>
                        <th class="text-right">Precautions</th>
                        <th>Requires</th>
                        <th class="text-right">Default hours</th>
                        <th class="text-right">Permits</th>
                        <th>State</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($types as $type)
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('permit-types.show', $type) }}"
                                   class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                    {{ $type->code }}
                                </a>
                            </td>

                            <td>
                                <a href="{{ route('permit-types.show', $type) }}"
                                   class="text-[var(--text-body)] hover:text-brand-600">
                                    {{ $type->name }}
                                </a>
                                @if ($type->description)
                                    <span class="block max-w-md truncate text-xs text-[var(--text-muted)]">
                                        {{ $type->description }}
                                    </span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                                {{ count($type->defaultPrecautions()) }}
                            </td>

                            <td class="whitespace-nowrap text-xs text-[var(--text-muted)]">
                                @if ($type->requires_gas_test) <span class="mr-2">Gas test</span> @endif
                                @if ($type->requires_isolation) <span>Isolation</span> @endif
                                @if (! $type->requires_gas_test && ! $type->requires_isolation) — @endif
                            </td>

                            <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                                {{ $type->default_duration_hours ?: '—' }}
                            </td>

                            <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                                {{ $type->permits_count }}
                            </td>

                            <td class="whitespace-nowrap">
                                @if ($type->is_active)
                                    <x-badge tone="success">In use</x-badge>
                                @else
                                    <x-badge tone="neutral">Retired</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</x-layouts.app>
