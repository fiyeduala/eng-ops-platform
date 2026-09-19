{{--
    The configuration centre.

    Everything here is a decision a company might make differently — which is why none of it is a
    constant in code. The form posts a fixed vocabulary: `settings[<key>]` for the values, plus a
    `present[]` list naming every key actually rendered. That list is what lets the controller tell
    an unticked checkbox ("off") from a section that was never on the screen ("leave alone"), which
    a checkbox alone cannot say.

    Locked rows are shown, disabled, with the reason. They are platform structure rather than a
    company preference, and hiding them would make the page look like it offered less than it does.
--}}
<x-layouts.app title="Settings">

    <x-page-header
        title="Settings"
        description="How this company works — its vocabulary, its working week, how approvals and documents behave. Changing anything here changes it for everybody, immediately.">
        @if ($canManage)
            <x-slot:actions>
                <button type="submit" form="settings-form" class="btn btn-primary btn-sm">Save changes</button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @unless ($canManage)
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p>
                You can read this configuration but not change it. Editing needs the
                <span class="font-medium">setting.manage</span> permission.
            </p>
        </div>
    @endunless

    @php
        // Permission keys contain dots, so old('settings.work.hours_per_day') would be read as five
        // levels of nesting and always miss. The whole array is fetched once and indexed by hand.
        $submitted = old('settings');
    @endphp

    <form method="POST" action="{{ route('settings.update') }}" id="settings-form" class="space-y-6">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $canManage) class="space-y-6">
            {{--
                Each group is anchored by its key so another screen can send somebody straight to
                it. The Configuration Center's "Escalation rules" tile lands on #group-workflow —
                there is no separate escalation editor, because what happens when an approval sits
                too long is a setting here plus the deadlines on each workflow stage, and a third
                place to configure it would be the start of a second approval path (§78).
            --}}
            @foreach ($groups as $groupKey => $group)
                <div class="card overflow-hidden" id="group-{{ $groupKey }}">
                    <div class="card-header flex-col items-start gap-1">
                        <h3 class="card-title">{{ $group['label'] }}</h3>
                        @if (! empty($group['description']))
                            <p class="max-w-3xl text-xs text-[var(--text-muted)]">{{ $group['description'] }}</p>
                        @endif
                    </div>

                    <div class="divide-y divide-[var(--surface-border)]">
                        @foreach ($group['settings'] as $key => $definition)
                            @php
                                $isLocked = in_array($key, $locked, true);
                                $current = $submitted !== null
                                    ? ($submitted[$key] ?? null)
                                    : ($values[$key] ?? null);
                                $error = $errors->first($key);
                                $id = 'setting-'.\Illuminate\Support\Str::slug($key);
                            @endphp

                            <div class="grid grid-cols-1 gap-x-6 gap-y-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_18rem] sm:items-start">

                                <div class="min-w-0">
                                    <label for="{{ $id }}" class="flex flex-wrap items-center gap-2 text-sm font-medium text-[var(--text-strong)]">
                                        {{ $definition['label'] }}
                                        @if ($isLocked)
                                            <x-badge tone="neutral"><x-icon name="lock" class="h-3 w-3"/> Fixed</x-badge>
                                        @endif
                                    </label>

                                    @if (! empty($definition['help']))
                                        <p class="mt-0.5 max-w-xl text-xs text-[var(--text-muted)]">{{ $definition['help'] }}</p>
                                    @endif

                                    @if ($isLocked)
                                        <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                            Set by the platform for every company. It cannot be changed from here.
                                        </p>
                                    @endif

                                    <p class="mt-0.5 font-mono text-xs text-[var(--text-muted)]">{{ $key }}</p>

                                    @if ($error)
                                        <p class="error" role="alert">{{ $error }}</p>
                                    @endif
                                </div>

                                <div>
                                    {{-- Named so the controller can tell "turned off" from "not shown". Only
                                         editable keys are declared: a locked one is refused on submit anyway,
                                         and claiming it was on the form would be a claim about nothing. --}}
                                    @unless ($isLocked)
                                        <input type="hidden" name="present[]" value="{{ $key }}">
                                    @endunless

                                    @switch ($definition['type'])

                                        @case ('boolean')
                                            <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                                <input type="checkbox"
                                                       name="settings[{{ $key }}]"
                                                       id="{{ $id }}"
                                                       value="1"
                                                       @checked(filter_var($current, FILTER_VALIDATE_BOOLEAN))
                                                       @disabled($isLocked)
                                                       class="h-4 w-4 rounded border-[var(--surface-border)] disabled:opacity-40">
                                                <span>Yes</span>
                                            </label>
                                            @break

                                        @case ('select')
                                            <select name="settings[{{ $key }}]" id="{{ $id }}" @disabled($isLocked)
                                                    class="select {{ $error ? 'input-error' : '' }} disabled:opacity-50">
                                                @foreach ($definition['options'] ?? [] as $value => $label)
                                                    <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @break

                                        @case ('multiselect')
                                            @php $chosen = array_map('strval', (array) ($current ?? [])); @endphp
                                            <div class="space-y-1.5">
                                                @foreach ($definition['options'] ?? [] as $value => $label)
                                                    <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                                        <input type="checkbox"
                                                               name="settings[{{ $key }}][]"
                                                               value="{{ $value }}"
                                                               @checked(in_array((string) $value, $chosen, true))
                                                               @disabled($isLocked)
                                                               class="h-4 w-4 rounded border-[var(--surface-border)] disabled:opacity-40">
                                                        {{ $label }}
                                                    </label>
                                                @endforeach
                                            </div>
                                            @break

                                        @case ('integer')
                                            <input type="number"
                                                   name="settings[{{ $key }}]"
                                                   id="{{ $id }}"
                                                   value="{{ $current }}"
                                                   @disabled($isLocked)
                                                   class="input {{ $error ? 'input-error' : '' }} disabled:opacity-50">
                                            @break

                                        @case ('time')
                                            <input type="time"
                                                   name="settings[{{ $key }}]"
                                                   id="{{ $id }}"
                                                   value="{{ $current }}"
                                                   @disabled($isLocked)
                                                   class="input {{ $error ? 'input-error' : '' }} disabled:opacity-50">
                                            @break

                                        @case ('hour')
                                            {{-- Stored as a plain hour of the day, so it stays meaningful when the
                                                 company timezone changes and when a scheduler reads it at midnight. --}}
                                            <select name="settings[{{ $key }}]" id="{{ $id }}" @disabled($isLocked)
                                                    class="select {{ $error ? 'input-error' : '' }} disabled:opacity-50">
                                                @for ($hour = 0; $hour < 24; $hour++)
                                                    <option value="{{ $hour }}" @selected((int) $current === $hour)>
                                                        {{ sprintf('%02d:00', $hour) }}
                                                    </option>
                                                @endfor
                                            </select>
                                            @break

                                        @default
                                            <input type="text"
                                                   name="settings[{{ $key }}]"
                                                   id="{{ $id }}"
                                                   value="{{ $current }}"
                                                   @disabled($isLocked)
                                                   class="input {{ $error ? 'input-error' : '' }} disabled:opacity-50">
                                    @endswitch
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </fieldset>

        @if ($canManage)
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('settings.index') }}" class="btn btn-secondary">Discard</a>
                <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
        @endif
    </form>

</x-layouts.app>
