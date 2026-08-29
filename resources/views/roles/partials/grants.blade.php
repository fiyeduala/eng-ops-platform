{{--
    The grants editor. Expects: $catalogue, $scopes.

    Two controls per permission, because access has two independent axes and collapsing them into
    one ordered dropdown would make the ordinary case inexpressible: a discipline lead holds their
    whole department on the organizational ladder AND everything on the projects they belong to,
    and sees the union. `scope[<key>]` is the ladder; `project[<key>]` is the second axis.

    Permissions the actor cannot themselves hand out are shown, disabled, rather than hidden. A role
    that looks emptier than it is, is worse than one that admits the limit.

    Nothing here is a security control — RolePolicy::mayGrantAll and RoleController::update are. A
    disabled select is a courtesy to the honest administrator, not a defence against the other kind.
--}}
@once
    @push('styles')
        <style>
            [data-grants].only-granted [data-granted="0"] { display: none; }
        </style>
    @endpush
@endonce

@php
    // After a rejected submission the editor redraws from old() rather than from the database, so a
    // half-finished change survives the error. `old('scope.<key>')` cannot be used: permission keys
    // contain dots, and Arr::get would read `scope.employee.view` as three levels of nesting. The
    // whole array is fetched once instead and indexed by hand.
    $oldScope = old('scope');
    $oldProject = old('project');

    $granted = 0;
    foreach ($catalogue as $modules) {
        foreach ($modules as $module) {
            foreach ($module['permissions'] as $permission) {
                if ($permission['scope'] || $permission['project']) {
                    $granted++;
                }
            }
        }
    }
@endphp

<div x-data="{ onlyGranted: false, q: '' }" data-grants :class="{ 'only-granted': onlyGranted }">

    {{-- ---------------------------------------------------------------- toolbar --}}
    <div class="flex flex-col gap-3 border-b border-[var(--surface-border)] bg-[var(--surface-muted)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-2">
            <x-icon name="search" class="h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]"/>
            <input type="search"
                   x-model="q"
                   placeholder="Filter areas — “document”, “safety”, “purchase”…"
                   class="input h-8 w-full text-sm sm:w-72"
                   aria-label="Filter permission areas">
        </div>

        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                <input type="checkbox" x-model="onlyGranted" class="h-4 w-4 rounded border-[var(--surface-border)]">
                Only what this role carries
            </label>
            <span class="whitespace-nowrap text-xs text-[var(--text-muted)]">
                {{ $granted }} granted
            </span>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- groups --}}
    @foreach ($catalogue as $group => $modules)
        @php
            $groupGranted = 0;
            foreach ($modules as $module) {
                foreach ($module['permissions'] as $permission) {
                    if ($permission['scope'] || $permission['project']) {
                        $groupGranted++;
                    }
                }
            }
        @endphp

        {{--
            <details>, not an Alpine panel. A closed <details> keeps its inputs in the document and
            still submits them — a collapsed section that quietly dropped its grants would delete
            permissions nobody touched. It also needs no JavaScript and does not flash open on load.
        --}}
        <details class="group border-b border-[var(--surface-border)] last:border-b-0" {{ $groupGranted > 0 ? 'open' : '' }}>
            <summary class="flex cursor-pointer list-none items-center gap-2 px-5 py-3 hover:bg-[var(--surface-muted)]">
                <x-icon name="chevron-right" class="h-3.5 w-3.5 shrink-0 text-[var(--text-muted)] transition-transform group-open:rotate-90"/>
                <span class="text-sm font-semibold text-[var(--text-strong)]">{{ $group }}</span>
                @if ($groupGranted > 0)
                    <x-badge tone="brand">{{ $groupGranted }}</x-badge>
                @endif
            </summary>

            <div class="pb-2">
                @foreach ($modules as $moduleKey => $module)
                    <div x-show="q === '' || @js(mb_strtolower($module['label'].' '.$moduleKey.' '.$group)).includes(q.toLowerCase())">

                        <p class="bg-[var(--surface-muted)] px-5 py-1.5 text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                            {{ $module['label'] }}
                        </p>

                        @foreach ($module['permissions'] as $permission)
                            @php
                                $key = $permission['key'];
                                $currentScope = (string) ($permission['scope'] ?? '');
                                $currentProject = (bool) $permission['project'];
                                $held = $currentScope !== '' || $currentProject;
                                $grantable = (bool) $permission['grantable'];

                                $scope = $oldScope !== null ? (string) ($oldScope[$key] ?? '') : $currentScope;
                                $project = $oldProject !== null ? array_key_exists($key, $oldProject) : $currentProject;

                                // What this actor may choose. Someone who does not hold the
                                // permission themselves may still take it away — removing reach is
                                // always allowed — but may not re-aim it, because widening
                                // `own` to `organization` is an escalation wearing a smaller word.
                                $options = $grantable
                                    ? $scopes
                                    : ['' => $scopes[''] ?? 'Not granted']
                                        + ($currentScope !== '' ? [$currentScope => $scopes[$currentScope] ?? $currentScope] : []);
                            @endphp

                            <div data-granted="{{ $held ? 1 : 0 }}"
                                 class="grid grid-cols-1 items-start gap-x-4 gap-y-2 border-t border-[var(--surface-border)] px-5 py-3 sm:grid-cols-[1fr_14rem_10rem] sm:items-center">

                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm text-[var(--text-strong)]">
                                        {{ $permission['label'] }}
                                        @if ($permission['sensitive'])
                                            <x-badge tone="warning">Sensitive</x-badge>
                                        @endif
                                        @unless ($grantable)
                                            <x-badge tone="neutral">
                                                <x-icon name="lock" class="h-3 w-3"/> Not yours to grant
                                            </x-badge>
                                        @endunless
                                    </p>
                                    <p class="mt-0.5 font-mono text-xs text-[var(--text-muted)]">{{ $key }}</p>
                                </div>

                                <div>
                                    <label for="scope-{{ $key }}" class="sr-only">How far {{ $permission['label'] }} reaches</label>
                                    <select name="scope[{{ $key }}]"
                                            id="scope-{{ $key }}"
                                            @disabled(! $grantable && ! $held)
                                            class="select h-9 py-1 text-sm disabled:opacity-50">
                                        @foreach ($options as $value => $label)
                                            <option value="{{ $value }}" @selected($scope === (string) $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                    <input type="checkbox"
                                           name="project[{{ $key }}]"
                                           value="1"
                                           @checked($project)
                                           @disabled(! $grantable && ! $currentProject)
                                           class="h-4 w-4 rounded border-[var(--surface-border)] disabled:opacity-40">
                                    <span class="{{ $grantable || $currentProject ? '' : 'opacity-50' }}">Their projects</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </details>
    @endforeach
</div>
