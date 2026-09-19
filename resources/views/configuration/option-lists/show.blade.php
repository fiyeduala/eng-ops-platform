{{--
    One list, and everything in it.

    EVERY INPUT ON THIS PAGE IS HAND-WRITTEN WITH AN EXPLICIT value=, and none of the refusals
    repopulate. <x-input> reads old($name), and this page carries one form per option plus the add
    form, all sharing field names — so a rejected edit on row four would repopulate rows one
    through twenty with row four's text. The message says what was refused; the rows keep showing
    what is actually stored, which is the truth.

    Expects: $list, $items, $canManage
--}}
<x-layouts.app :title="$list->name">

    <x-page-header
        :title="$list->name"
        :description="$list->description ?: 'The words this company offers wherever a field names this list.'">
        <x-slot:actions>
            <a href="{{ route('configuration.option-lists.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> All lists
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
                You can read this list but not change it. Editing needs the
                <span class="font-medium">custom_field.manage</span> permission.
            </p>
        </div>
    @endunless

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------------- the options --}}
        <div class="space-y-5 lg:col-span-2">
            <div class="card overflow-x-auto">
                <div class="card-header">
                    <h2 class="card-title">Options</h2>
                </div>

                @if ($items->isEmpty())
                    <p class="p-6 text-sm text-[var(--text-muted)]">
                        Nothing in this list yet. Until there is, any field pointing at it will offer
                        an empty dropdown.
                    </p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Shown as</th>
                                <th>Stored as</th>
                                <th></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $ids = $items->pluck('id')->all(); @endphp

                            @foreach ($items as $index => $item)
                                @php
                                    // The two neighbouring orders, worked out here so the arrows
                                    // stay ordinary forms. The endpoint re-scopes every id it is
                                    // given, so a hand-edited list reorders nothing it should
                                    // not (§84).
                                    $up = $ids;
                                    if ($index > 0) { [$up[$index - 1], $up[$index]] = [$up[$index], $up[$index - 1]]; }
                                    $down = $ids;
                                    if ($index < count($ids) - 1) { [$down[$index + 1], $down[$index]] = [$down[$index], $down[$index + 1]]; }
                                @endphp

                                <tr x-data="{ editing: false }">
                                    <td x-show="! editing">
                                        <span class="font-medium text-[var(--text-strong)]">{{ $item->label }}</span>
                                        @if ($item->color)
                                            <x-badge :tone="$item->color" class="ml-2">{{ $item->color }}</x-badge>
                                        @endif
                                    </td>

                                    <td x-show="! editing" class="font-mono text-xs text-[var(--text-muted)]">
                                        {{ $item->value }}
                                    </td>

                                    <td x-show="! editing" class="whitespace-nowrap">
                                        @unless ($item->is_active)
                                            <x-badge tone="warning">Retired</x-badge>
                                        @endunless
                                    </td>

                                    <td x-show="! editing" class="whitespace-nowrap text-right">
                                        @if ($canManage)
                                            <div class="flex items-center justify-end gap-1">
                                                @if (count($ids) > 1)
                                                    @foreach (['up' => $up, 'down' => $down] as $direction => $order)
                                                        @continue(($direction === 'up' && $index === 0) || ($direction === 'down' && $index === count($ids) - 1))
                                                        <form method="POST" action="{{ route('configuration.option-lists.options.reorder', $list) }}">
                                                            @csrf
                                                            @foreach ($order as $id)
                                                                <input type="hidden" name="order[]" value="{{ $id }}">
                                                            @endforeach
                                                            <button type="submit"
                                                                    class="text-[var(--text-muted)] hover:text-brand-600"
                                                                    aria-label="Move {{ $item->label }} {{ $direction }}">
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
                                                      action="{{ route('configuration.option-lists.options.destroy', [$list, $item]) }}"
                                                      onsubmit="return confirm('Remove &quot;{{ $item->label }}&quot; from this list?')">
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
                                              action="{{ route('configuration.option-lists.options.update', [$list, $item]) }}"
                                              class="grid gap-3 sm:grid-cols-4 sm:items-end">
                                            @csrf
                                            @method('PUT')

                                            <div>
                                                <label class="label">Shown as</label>
                                                <input type="text" name="label" class="input"
                                                       value="{{ $item->label }}" required maxlength="160">
                                            </div>

                                            <div>
                                                <label class="label">Stored as</label>
                                                <input type="text" name="value" class="input font-mono"
                                                       value="{{ $item->value }}" maxlength="64">
                                                <p class="help">Frozen once records use it.</p>
                                            </div>

                                            <div>
                                                <label class="label">Colour</label>
                                                <select name="color" class="select">
                                                    <option value="">None</option>
                                                    @foreach (['neutral', 'brand', 'success', 'warning', 'danger'] as $tone)
                                                        <option value="{{ $tone }}" @selected($item->color === $tone)>{{ ucfirst($tone) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div class="flex items-center gap-2">
                                                <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                                    <input type="hidden" name="is_active" value="0">
                                                    <input type="checkbox" name="is_active" value="1" @checked($item->is_active)>
                                                    Offered
                                                </label>
                                            </div>

                                            <div class="sm:col-span-4 flex justify-end gap-2">
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

            @if ($canManage)
                <form method="POST" action="{{ route('configuration.option-lists.options.store', $list) }}" class="card">
                    @csrf

                    <div class="card-header">
                        <h2 class="card-title">Add an option</h2>
                    </div>

                    <div class="grid gap-4 p-4 sm:grid-cols-3">
                        <div>
                            <label class="label" for="new-label">Shown as</label>
                            <input type="text" id="new-label" name="label" class="input" required maxlength="160">
                        </div>

                        <div>
                            <label class="label" for="new-value">Stored as</label>
                            <input type="text" id="new-value" name="value" class="input font-mono" maxlength="64">
                            <p class="help">Leave blank to derive it.</p>
                        </div>

                        <div>
                            <label class="label" for="new-color">Colour</label>
                            <select id="new-color" name="color" class="select">
                                <option value="">None</option>
                                @foreach (['neutral', 'brand', 'success', 'warning', 'danger'] as $tone)
                                    <option value="{{ $tone }}">{{ ucfirst($tone) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-[var(--surface-border)] p-4">
                        <button type="submit" class="btn btn-primary btn-sm">Add option</button>
                    </div>
                </form>
            @endif
        </div>

        {{-- ------------------------------------------------------------- the list itself --}}
        <div class="space-y-5">
            <form method="POST" action="{{ route('configuration.option-lists.update', $list) }}" class="card self-start">
                @csrf
                @method('PUT')

                <div class="card-header">
                    <h2 class="card-title">This list</h2>
                </div>

                <div class="space-y-4 p-4">
                    <div>
                        <label class="label" for="name">Name</label>
                        <input type="text" id="name" name="name" class="input"
                               value="{{ $list->name }}" @disabled(! $canManage) required maxlength="120">
                    </div>

                    <div>
                        <label class="label" for="key">Key</label>
                        <input type="text" id="key" name="key" class="input font-mono"
                               value="{{ $list->key }}"
                               @disabled(! $canManage || $list->isSystem()) maxlength="64">
                        <p class="help">
                            @if ($list->isSystem())
                                The platform refers to this list by this key, so it cannot change.
                            @else
                                Fields point at this list by its key.
                            @endif
                        </p>
                    </div>

                    <div>
                        <label class="label" for="description">Description</label>
                        <input type="text" id="description" name="description" class="input"
                               value="{{ $list->description }}" @disabled(! $canManage) maxlength="255">
                    </div>

                    @unless ($list->isSystem())
                        <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1"
                                   @checked($list->is_active) @disabled(! $canManage)>
                            In use
                        </label>
                    @endunless
                </div>

                @if ($canManage)
                    <div class="flex justify-end border-t border-[var(--surface-border)] p-4">
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                @endif
            </form>

            @if ($canManage && ! $list->isSystem())
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Delete this list</h2>
                    </div>

                    <div class="p-4">
                        <p class="mb-3 text-sm text-[var(--text-body)]">
                            Only possible while no field offers it. If one does, point that field at
                            another list first.
                        </p>

                        <form method="POST" action="{{ route('configuration.option-lists.destroy', $list) }}"
                              onsubmit="return confirm('Delete the &quot;{{ $list->name }}&quot; list and everything in it?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Delete list</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

    </div>

</x-layouts.app>
