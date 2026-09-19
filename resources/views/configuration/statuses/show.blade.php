{{--
    One register's state machine, in full.

    WHY THE FORMS ARE HAND-WRITTEN RATHER THAN <x-input>. This page carries one form per status row,
    one per transition row, and two "add" forms, all using the same field names. <x-input> reads
    old($name), so a rejected edit in row four would repopulate every other row — and the two add
    forms — with row four's text, silently showing an administrator values that are not stored.
    Each control therefore states its own value, and the single banner at the top says what was
    refused. The row keeps showing what is actually in the database, which is the only thing a
    configuration screen must never lie about.

    WHY SYSTEM STATUSES STILL SHOW THEIR CONTROLS, disabled, with the reason written beside them.
    Hiding them would make the page look like it offered less than it does, and an administrator who
    cannot see why the key is locked will assume the platform is broken rather than that it is
    holding a promise about payroll.

    Expects: $entityType, $registerName, $statuses, $transitions, $workflowOutcomes, $permissions, $canManage
--}}
<x-layouts.app :title="$registerName.' statuses'">

    @php
        $tones = ['neutral' => 'Grey', 'brand' => 'Blue', 'success' => 'Green', 'warning' => 'Amber', 'danger' => 'Red'];
        $permissionOptions = array_combine($permissions, $permissions);
        $ordered = $statuses->values();
        $ids = $ordered->pluck('id')->all();
        $statusOptions = $ordered->pluck('label', 'id')->all();
    @endphp

    <x-page-header
        :title="$registerName"
        description="The states a record of this kind can be in, and every move permitted between them. A move with no row here is refused — that is what makes a status field mean something.">
        <x-slot:actions>
            <a href="{{ route('configuration.statuses.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> All registers
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4" role="alert">
            <div class="flex items-start gap-2">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-red-500"/>
                <div class="space-y-1 text-sm text-[var(--text-body)]">
                    @foreach ($errors->all() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @unless ($canManage)
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p>
                You can read this configuration but not change it. Editing needs the
                <span class="font-medium">status.manage</span> permission.
            </p>
        </div>
    @endunless

    {{-- ============================================================ statuses --}}

    <section class="mb-8">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-[var(--text-strong)]">
            The words
        </h2>

        <div class="card overflow-hidden">
            @if ($ordered->isEmpty())
                <x-empty-state icon="flag" title="This register has no states at all"
                               description="Records of this kind currently have nowhere to be. Add the first state below — usually the one a new record starts in."/>
            @else
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-8"></th>
                            <th>Label</th>
                            <th>Key</th>
                            <th>Role</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ordered as $i => $status)
                            @php
                                // The two neighbouring orders, worked out here so the arrows are
                                // ordinary forms. The endpoint re-scopes every id it is given, so a
                                // hand-edited list reorders nothing it should not (§84).
                                $up = $ids;
                                if ($i > 0) { [$up[$i - 1], $up[$i]] = [$up[$i], $up[$i - 1]]; }
                                $down = $ids;
                                if ($i < count($ids) - 1) { [$down[$i + 1], $down[$i]] = [$down[$i], $down[$i + 1]]; }
                            @endphp

                            <tr x-data="{ editing: false }">
                                <td class="align-top">
                                    @if ($canManage && count($ids) > 1)
                                        <div class="flex flex-col gap-0.5">
                                            @foreach (['up' => $up, 'down' => $down] as $direction => $order)
                                                @continue(($direction === 'up' && $i === 0) || ($direction === 'down' && $i === count($ids) - 1))
                                                <form method="POST"
                                                      action="{{ route('configuration.statuses.reorder', $entityType) }}">
                                                    @csrf
                                                    @foreach ($order as $id)
                                                        <input type="hidden" name="order[]" value="{{ $id }}">
                                                    @endforeach
                                                    <button type="submit"
                                                            class="text-[var(--text-muted)] hover:text-brand-600"
                                                            aria-label="Move {{ $status->label }} {{ $direction }}">
                                                        <x-icon :name="$direction === 'up' ? 'chevron-down' : 'chevron-down'"
                                                                class="h-3.5 w-3.5 {{ $direction === 'up' ? 'rotate-180' : '' }}"/>
                                                    </button>
                                                </form>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>

                                <td class="align-top">
                                    <x-badge :tone="$status->tone()">{{ $status->label }}</x-badge>
                                    @unless ($status->is_active)
                                        <span class="ml-1 text-xs text-[var(--text-muted)]">retired</span>
                                    @endunless
                                </td>

                                <td class="align-top font-mono text-xs text-[var(--text-muted)]">{{ $status->key }}</td>

                                <td class="align-top text-xs text-[var(--text-muted)]">
                                    @if ($status->is_initial) <span class="mr-2">Starts here</span> @endif
                                    @if ($status->is_terminal) <span class="mr-2">Ends here</span> @endif
                                    @if ($status->isSystem())
                                        <span class="mr-2" title="This register's own code moves records into this state by key.">Built in</span>
                                    @endif
                                    @if (in_array((int) $status->id, $workflowOutcomes, true))
                                        <span class="mr-2 text-amber-600">Conferred by an approval</span>
                                    @endif
                                </td>

                                <td class="align-top text-right">
                                    @if ($canManage)
                                        <button type="button" class="btn btn-secondary btn-sm" @click="editing = ! editing">
                                            <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                                        </button>
                                    @endif
                                </td>
                            </tr>

                            @if ($canManage)
                                <tr x-show="editing" x-cloak>
                                    <td colspan="5" class="bg-[var(--surface-muted)]">
                                        <form method="POST"
                                              action="{{ route('configuration.statuses.update', [$entityType, $status]) }}"
                                              class="space-y-3 p-2">
                                            @csrf
                                            @method('PUT')

                                            <div class="grid gap-3 sm:grid-cols-4">
                                                <label class="block">
                                                    <span class="label">Label</span>
                                                    <input type="text" name="label" maxlength="60" required
                                                           value="{{ $status->label }}" class="input">
                                                </label>

                                                <label class="block">
                                                    <span class="label">Key</span>
                                                    <input type="text" name="key" maxlength="64"
                                                           value="{{ $status->key }}" class="input font-mono text-xs"
                                                           @disabled($status->isSystem())>
                                                    @if ($status->isSystem())
                                                        <span class="help">Locked: this register's own code moves records into this state by name.</span>
                                                    @endif
                                                </label>

                                                <label class="block">
                                                    <span class="label">Colour</span>
                                                    <select name="color" class="select">
                                                        @foreach ($tones as $value => $name)
                                                            <option value="{{ $value }}" @selected($status->tone() === $value)>{{ $name }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>

                                                <label class="block">
                                                    <span class="label">Order</span>
                                                    <input type="number" name="sort" min="0" max="99999"
                                                           value="{{ $status->sort }}" class="input">
                                                </label>
                                            </div>

                                            <div class="flex flex-wrap items-center gap-4 text-sm text-[var(--text-body)]">
                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" name="is_initial" value="1" @checked($status->is_initial)>
                                                    New records start here
                                                </label>

                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" name="is_terminal" value="1"
                                                           @checked($status->is_terminal) @disabled($status->isSystem())>
                                                    Nothing happens after this
                                                </label>

                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" name="is_active" value="1"
                                                           @checked($status->is_active) @disabled($status->isSystem())>
                                                    In use
                                                </label>

                                                @if ($status->isSystem())
                                                    <span class="text-xs text-[var(--text-muted)]">
                                                        Built-in states cannot be switched off — the platform moves records
                                                        into this one, and a retired state would make those moves fail silently.
                                                    </span>
                                                @endif
                                            </div>

                                            <div class="flex items-center gap-2">
                                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                                <button type="button" class="btn btn-secondary btn-sm" @click="editing = false">Cancel</button>

                                                @unless ($status->isSystem())
                                                    <span class="ml-auto">
                                                        <x-delete-form
                                                            :action="route('configuration.statuses.destroy', [$entityType, $status])"
                                                            label="Delete this state"
                                                            confirm="Delete it? Any move naming it goes too."/>
                                                    </span>
                                                @endunless
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($canManage)
                <div class="border-t border-[var(--surface-border)] p-4">
                    <form method="POST" action="{{ route('configuration.statuses.store', $entityType) }}"
                          class="flex flex-wrap items-end gap-3">
                        @csrf

                        <label class="block">
                            <span class="label">Add a state</span>
                            <input type="text" name="label" maxlength="60" required
                                   placeholder="e.g. Awaiting client" class="input">
                        </label>

                        <label class="block">
                            <span class="label">Key <span class="text-[var(--text-muted)]">(optional)</span></span>
                            <input type="text" name="key" maxlength="64" placeholder="awaiting_client"
                                   class="input font-mono text-xs">
                        </label>

                        <label class="block">
                            <span class="label">Colour</span>
                            <select name="color" class="select">
                                @foreach ($tones as $value => $name)
                                    <option value="{{ $value }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="flex items-center gap-2 pb-2 text-sm text-[var(--text-body)]">
                            <input type="checkbox" name="is_terminal" value="1"> Ends here
                        </label>

                        <button type="submit" class="btn btn-primary btn-sm">Add</button>
                    </form>
                </div>
            @endif
        </div>
    </section>

    {{-- ============================================================ transitions --}}

    <section>
        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-[var(--text-strong)]">
            The moves
        </h2>
        <p class="mb-3 max-w-3xl text-sm text-[var(--text-muted)]">
            A move not listed here is refused, whoever asks for it. Leaving the permission blank means
            anyone who can edit the record can make the move — which is right for "start work" and
            wrong for "approve".
        </p>

        <div class="card overflow-hidden">
            @if ($transitions->isEmpty())
                <x-empty-state icon="target" title="Nothing can move"
                               description="With no permitted moves, every record of this kind is stuck in the state it was created in. Add the first move below."/>
            @else
                <table class="table">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Button</th>
                            <th>Who may</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transitions as $transition)
                            <tr x-data="{ editing: false }">
                                <td class="align-top">
                                    @if ($transition->from)
                                        <x-badge :tone="$transition->from->tone()">{{ $transition->from->label }}</x-badge>
                                    @else
                                        <span class="text-xs italic text-[var(--text-muted)]">anywhere</span>
                                    @endif
                                </td>

                                <td class="align-top">
                                    @if ($transition->to)
                                        <x-badge :tone="$transition->to->tone()">{{ $transition->to->label }}</x-badge>
                                    @endif
                                </td>

                                <td class="align-top text-sm text-[var(--text-body)]">
                                    {{ $transition->label ?: '—' }}
                                    @if ($transition->requires_comment)
                                        <span class="block text-xs text-[var(--text-muted)]">a reason must be given</span>
                                    @endif
                                </td>

                                <td class="align-top">
                                    @if ($transition->permission_key)
                                        <span class="font-mono text-xs text-[var(--text-muted)]">{{ $transition->permission_key }}</span>
                                    @else
                                        <span class="text-xs text-[var(--text-muted)]">anyone who may edit the record</span>
                                    @endif
                                </td>

                                <td class="align-top text-right">
                                    @if ($canManage)
                                        <button type="button" class="btn btn-secondary btn-sm" @click="editing = ! editing">
                                            <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                                        </button>
                                    @endif
                                </td>
                            </tr>

                            @if ($canManage)
                                <tr x-show="editing" x-cloak>
                                    <td colspan="5" class="bg-[var(--surface-muted)]">
                                        <form method="POST"
                                              action="{{ route('configuration.statuses.transitions.update', [$entityType, $transition]) }}"
                                              class="space-y-3 p-2">
                                            @csrf
                                            @method('PUT')

                                            {{--
                                                The two ends are shown, not offered. Repointing a move
                                                would silently redefine something somebody audited, so
                                                a different move is a different row.
                                            --}}
                                            <input type="hidden" name="from_status_id" value="{{ $transition->from_status_id }}">
                                            <input type="hidden" name="to_status_id" value="{{ $transition->to_status_id }}">

                                            <div class="grid gap-3 sm:grid-cols-3">
                                                <label class="block">
                                                    <span class="label">Button wording</span>
                                                    <input type="text" name="label" maxlength="60"
                                                           value="{{ $transition->label }}" class="input">
                                                </label>

                                                <label class="block sm:col-span-2">
                                                    <span class="label">Permission required</span>
                                                    <select name="permission_key" class="select font-mono text-xs">
                                                        <option value="">— anyone who may edit the record —</option>
                                                        @foreach ($permissionOptions as $value => $name)
                                                            <option value="{{ $value }}" @selected($transition->permission_key === $value)>{{ $name }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                            </div>

                                            <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                                                <input type="checkbox" name="requires_comment" value="1"
                                                       @checked($transition->requires_comment)>
                                                A reason must be given
                                            </label>

                                            <div class="flex items-center gap-2">
                                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                                <button type="button" class="btn btn-secondary btn-sm" @click="editing = false">Cancel</button>
                                                <span class="ml-auto">
                                                    <x-delete-form
                                                        :action="route('configuration.statuses.transitions.destroy', [$entityType, $transition])"
                                                        label="Withdraw this move"
                                                        confirm="Withdraw it? Nobody will be able to make this move."/>
                                                </span>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($canManage && $ordered->isNotEmpty())
                <div class="border-t border-[var(--surface-border)] p-4">
                    <form method="POST" action="{{ route('configuration.statuses.transitions.store', $entityType) }}"
                          class="flex flex-wrap items-end gap-3">
                        @csrf

                        <label class="block">
                            <span class="label">From</span>
                            <select name="from_status_id" class="select">
                                <option value="">anywhere</option>
                                @foreach ($statusOptions as $value => $name)
                                    <option value="{{ $value }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block">
                            <span class="label">To</span>
                            <select name="to_status_id" required class="select">
                                @foreach ($statusOptions as $value => $name)
                                    <option value="{{ $value }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="block">
                            <span class="label">Button wording</span>
                            <input type="text" name="label" maxlength="60" placeholder="e.g. Send for review" class="input">
                        </label>

                        <label class="block">
                            <span class="label">Permission required</span>
                            <select name="permission_key" class="select font-mono text-xs">
                                <option value="">— anyone who may edit the record —</option>
                                @foreach ($permissionOptions as $value => $name)
                                    <option value="{{ $value }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <button type="submit" class="btn btn-primary btn-sm">Allow it</button>
                    </form>
                </div>
            @endif
        </div>
    </section>

</x-layouts.app>
