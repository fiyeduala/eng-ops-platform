{{--
    The questions one register asks, and the order it asks them in.

    EVERY INPUT IS HAND-WRITTEN WITH AN EXPLICIT value=, and no refusal repopulates — the same
    reason as the option-list editor. This page carries one form per field plus the add form, all
    sharing field names, so old() would push a rejected row's text into every other row.

    THE COUNTS ARE NOT DECORATION. "Answered on 214 records" is what makes the refusals on this
    screen comprehensible before they happen: it is the number that decides whether a key may
    change, whether a type may change, and what deleting the field destroys.

    Expects: $register, $registerName, $fields, $answers, $optionLists, $types, $canManage
--}}
<x-layouts.app :title="$registerName.' fields'">

    <x-page-header
        :title="$registerName"
        description="The extra questions this register asks. They appear on its forms, in the order below, for everybody in this company.">
        <x-slot:actions>
            <a href="{{ route('configuration.custom-fields.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> All registers
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That change was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @unless ($canManage)
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p>
                You can read these definitions but not change them. Editing needs the
                <span class="font-medium">custom_field.manage</span> permission.
            </p>
        </div>
    @endunless

    @if ($optionLists === [] && $canManage)
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p>
                There are no option lists yet, so "choose one" and "choose several" fields cannot be
                added. Build the list of words first on
                <a href="{{ route('configuration.option-lists.index') }}" class="text-brand-600 hover:underline">Option lists</a>.
            </p>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------------- the fields --}}
        <div class="space-y-5 lg:col-span-2">
            <div class="card overflow-x-auto">
                <div class="card-header">
                    <h2 class="card-title">Fields</h2>
                </div>

                @if ($fields->isEmpty())
                    <p class="p-6 text-sm text-[var(--text-muted)]">
                        {{ $registerName }} asks nothing extra yet. Anything you add here appears on
                        every {{ Str::lower(Str::singular($registerName)) }} form from the moment you save it.
                    </p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Kind</th>
                                <th class="text-right">Answered</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $ids = $fields->pluck('id')->all(); @endphp

                            @foreach ($fields as $index => $field)
                                @php
                                    // The two neighbouring orders, worked out here so the arrows stay
                                    // ordinary forms. The endpoint re-scopes every id it is given, so
                                    // an id from another register moves nothing (§84).
                                    $up = $ids;
                                    if ($index > 0) { [$up[$index - 1], $up[$index]] = [$up[$index], $up[$index - 1]]; }
                                    $down = $ids;
                                    if ($index < count($ids) - 1) { [$down[$index + 1], $down[$index]] = [$down[$index], $down[$index + 1]]; }

                                    $answered = $answers[$field->id] ?? 0;
                                @endphp

                                <tr x-data="{ editing: false }">
                                    <td x-show="! editing">
                                        <span class="font-medium text-[var(--text-strong)]">{{ $field->label }}</span>
                                        @if ($field->is_required)
                                            <x-badge tone="danger" class="ml-2">Required</x-badge>
                                        @endif
                                        @unless ($field->is_active)
                                            <x-badge tone="warning" class="ml-2">Switched off</x-badge>
                                        @endunless
                                        <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $field->key }}</span>
                                        @if ($field->help)
                                            <span class="mt-1 block text-xs text-[var(--text-muted)]">{{ $field->help }}</span>
                                        @endif
                                        @if ($field->applies_when_key)
                                            <span class="mt-1 block text-xs text-[var(--text-muted)]">
                                                Only asked when {{ $field->applies_when_key }} is {{ $field->applies_when_value }}
                                            </span>
                                        @endif
                                    </td>

                                    <td x-show="! editing" class="whitespace-nowrap text-[var(--text-body)]">
                                        {{ $field->typeLabel() }}
                                        @if ($field->option_list_key)
                                            <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                {{ $optionLists[$field->option_list_key] ?? $field->option_list_key }}
                                            </span>
                                        @endif
                                    </td>

                                    <td x-show="! editing" class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                                        {{ $answered }}
                                    </td>

                                    <td x-show="! editing" class="whitespace-nowrap text-right">
                                        @if ($canManage)
                                            <div class="flex items-center justify-end gap-1">
                                                @if (count($ids) > 1)
                                                    @foreach (['up' => $up, 'down' => $down] as $direction => $order)
                                                        @continue(($direction === 'up' && $index === 0) || ($direction === 'down' && $index === count($ids) - 1))
                                                        <form method="POST" action="{{ route('configuration.custom-fields.reorder', $register) }}">
                                                            @csrf
                                                            @foreach ($order as $id)
                                                                <input type="hidden" name="order[]" value="{{ $id }}">
                                                            @endforeach
                                                            <button type="submit"
                                                                    class="text-[var(--text-muted)] hover:text-brand-600"
                                                                    aria-label="Move {{ $field->label }} {{ $direction }}">
                                                                <x-icon name="chevron-down"
                                                                        class="h-3.5 w-3.5 {{ $direction === 'up' ? 'rotate-180' : '' }}"/>
                                                            </button>
                                                        </form>
                                                    @endforeach
                                                @endif

                                                <button type="button" class="btn btn-secondary btn-sm" @click="editing = true">
                                                    <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                                </button>

                                                <form method="POST"
                                                      action="{{ route('configuration.custom-fields.destroy', $field) }}"
                                                      onsubmit="return confirm('Delete &quot;{{ $field->label }}&quot; and the {{ $answered }} answer(s) stored in it? This cannot be undone. Switching the field off instead keeps the answers.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                                    </button>
                                                </form>
                                            </div>
                                        @endif
                                    </td>

                                    {{-- The edit form, in place. --}}
                                    <td colspan="4" x-show="editing" x-cloak>
                                        <form method="POST"
                                              action="{{ route('configuration.custom-fields.update', $field) }}"
                                              class="grid gap-3 sm:grid-cols-3 sm:items-start">
                                            @csrf
                                            @method('PUT')

                                            {{-- Posted because the request validates it; deliberately
                                                 never read on update — a field does not move register. --}}
                                            <input type="hidden" name="entity_type" value="{{ $register }}">

                                            <div>
                                                <label class="label">Label</label>
                                                <input type="text" name="label" class="input"
                                                       value="{{ $field->label }}" required maxlength="120">
                                                <p class="help">Yours to reword at any time.</p>
                                            </div>

                                            <div>
                                                <label class="label">Key</label>
                                                <input type="text" name="key" class="input font-mono"
                                                       value="{{ $field->key }}" maxlength="64"
                                                       @readonly($answered > 0)>
                                                <p class="help">
                                                    @if ($answered > 0)
                                                        Frozen — exports name this column.
                                                    @else
                                                        What exports call this column.
                                                    @endif
                                                </p>
                                            </div>

                                            <div>
                                                <label class="label">Kind</label>
                                                @if ($answered > 0)
                                                    {{-- Frozen: shown, but posted from a hidden input so the
                                                         payload still carries a type the request can validate.
                                                         The service refuses a change regardless — greying the
                                                         control is the courtesy, the guard is the control. --}}
                                                    <input type="hidden" name="type" value="{{ $field->type }}">
                                                    <p class="input bg-[var(--surface-muted)]">{{ $field->typeLabel() }}</p>
                                                    <p class="help">Frozen — {{ $answered }} {{ Str::plural('answer', $answered) }} stored.</p>
                                                @else
                                                    <select name="type" class="select">
                                                        @foreach ($types as $value => $name)
                                                            <option value="{{ $value }}" @selected($field->type === $value)>{{ $name }}</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                            </div>

                                            <div>
                                                <label class="label">List to choose from</label>
                                                <select name="option_list_key" class="select">
                                                    <option value="">Not a choice field</option>
                                                    @foreach ($optionLists as $key => $name)
                                                        <option value="{{ $key }}" @selected($field->option_list_key === $key)>{{ $name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div>
                                                <label class="label">Default answer</label>
                                                <input type="text" name="default_value" class="input"
                                                       value="{{ $field->default_value }}" maxlength="255">
                                                <p class="help">Used on new records only.</p>
                                            </div>

                                            <div>
                                                <label class="label">Placeholder</label>
                                                <input type="text" name="placeholder" class="input"
                                                       value="{{ $field->placeholder }}" maxlength="120">
                                            </div>

                                            <div class="sm:col-span-3">
                                                <label class="label">Help text</label>
                                                <input type="text" name="help" class="input"
                                                       value="{{ $field->help }}" maxlength="255">
                                                <p class="help">The sentence under the box, for whoever fills this in.</p>
                                            </div>

                                            <div>
                                                <label class="label">Only ask when</label>
                                                <input type="text" name="applies_when_key" class="input font-mono"
                                                       value="{{ $field->applies_when_key }}" maxlength="64"
                                                       placeholder="e.g. category">
                                            </div>

                                            <div>
                                                <label class="label">…is</label>
                                                <input type="text" name="applies_when_value" class="input"
                                                       value="{{ $field->applies_when_value }}" maxlength="120">
                                                <p class="help">Leave both blank to ask it every time.</p>
                                            </div>

                                            <div class="flex flex-col gap-2 pt-6 text-sm text-[var(--text-body)]">
                                                <label class="flex items-center gap-2">
                                                    <input type="hidden" name="is_required" value="0">
                                                    <input type="checkbox" name="is_required" value="1" @checked($field->is_required)>
                                                    Required
                                                </label>
                                                <label class="flex items-center gap-2">
                                                    <input type="hidden" name="is_filterable" value="0">
                                                    <input type="checkbox" name="is_filterable" value="1" @checked($field->is_filterable)>
                                                    Filterable
                                                </label>
                                                <label class="flex items-center gap-2">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input type="checkbox" name="is_active" value="1" @checked($field->is_active)>
                                                    Asked on forms
                                                </label>
                                            </div>

                                            <div class="sm:col-span-3 flex justify-end gap-2">
                                                <button type="button" class="btn btn-secondary btn-sm" @click="editing = false">Cancel</button>
                                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------------- adding one --}}
        @if ($canManage)
            <form method="POST" action="{{ route('configuration.custom-fields.store') }}" class="card self-start">
                @csrf

                {{-- The register comes from the page you are on, checked against the catalogue
                     server-side before anything is written (§84). --}}
                <input type="hidden" name="entity_type" value="{{ $register }}">

                <div class="card-header">
                    <h2 class="card-title">New field</h2>
                </div>

                <div class="space-y-4 p-4" x-data="{ type: 'text' }">
                    <div>
                        <label class="label" for="new-label">Label</label>
                        <input type="text" id="new-label" name="label" class="input"
                               value="" required maxlength="120">
                        <p class="help">The words above the box — "Client cost code".</p>
                    </div>

                    <div>
                        <label class="label" for="new-key">Key</label>
                        <input type="text" id="new-key" name="key" class="input font-mono" value="" maxlength="64">
                        <p class="help">Leave blank and one will be made from the label.</p>
                    </div>

                    <div>
                        <label class="label" for="new-type">Kind</label>
                        <select id="new-type" name="type" class="select" x-model="type">
                            @foreach ($types as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="type === 'select' || type === 'multiselect'" x-cloak>
                        <label class="label" for="new-list">List to choose from</label>
                        <select id="new-list" name="option_list_key" class="select">
                            <option value="">Choose a list</option>
                            @foreach ($optionLists as $key => $name)
                                <option value="{{ $key }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <p class="help">Defined on the Option lists screen.</p>
                    </div>

                    <div>
                        <label class="label" for="new-help">Help text</label>
                        <input type="text" id="new-help" name="help" class="input" value="" maxlength="255">
                    </div>

                    <div>
                        <label class="label" for="new-default">Default answer</label>
                        <input type="text" id="new-default" name="default_value" class="input" value="" maxlength="255">
                        <p class="help">What a brand-new record starts with.</p>
                    </div>

                    <div class="flex flex-col gap-2 text-sm text-[var(--text-body)]">
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="is_required" value="0">
                            <input type="checkbox" name="is_required" value="1">
                            Required
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="is_filterable" value="0">
                            <input type="checkbox" name="is_filterable" value="1">
                            Filterable
                        </label>
                    </div>
                </div>

                <div class="flex justify-end border-t border-[var(--surface-border)] p-4">
                    <button type="submit" class="btn btn-primary btn-sm">Add field</button>
                </div>
            </form>
        @endif
    </div>

</x-layouts.app>
