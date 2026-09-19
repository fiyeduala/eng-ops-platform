{{--
    Renewals — the dates this company has asked to be warned about.

    Expects: $watches, $entities, $dateFields, $roles, $people, $defaultLeadDays, $maxLeadDays, $canManage

    THE FORM IS ONE FORM, used both to add a watch and to edit one. Alpine holds the draft and the
    edit buttons load a row into it; there is no second markup for "edit", because a watch whose add
    screen and edit screen drift apart is a watch that gains a field nobody can change afterwards.

    The date select is filtered in the browser from $dateFields, which is a convenience and nothing
    more — ExpiryWatchService checks the pairing against ExpiryCatalogue on the way in regardless,
    because a select box is a suggestion and the server is the rule (§84).
--}}
<x-layouts.app title="Renewals & expiry">

    <x-page-header
        title="Renewals & expiry"
        description="Every date this company wants chasing — certificates, insurances, permits, training, warranties. Say which date, how far ahead, and who hears about it.">
        <x-slot:actions>
            <a href="{{ route('configuration.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Configuration
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
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That watch was not saved
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
                You can read these watches but not change them. Editing needs the
                <span class="font-medium">expiry_watch.manage</span> permission.
            </p>
        </div>
    @endunless

    @php
        // The draft the editor opens on. Built here rather than in the controller because it is
        // entirely about what the FORM should show — including whatever a refused submission was
        // carrying, so a rejected watch comes back filled in rather than blank.
        $draft = [
            'id' => null,
            'entity_type' => old('entity_type', ''),
            'date_field' => old('date_field', ''),
            'name' => old('name', ''),
            'lead_days' => old('lead_days', implode(', ', $defaultLeadDays)),
            'warn_when_overdue' => (bool) old('warn_when_overdue', false),
            'notify_owner' => (bool) old('notify_owner', true),
            'notify_followers' => (bool) old('notify_followers', true),
            // Strings, because a <select multiple> compares its option values as strings and
            // integers would leave every already-chosen recipient looking unselected.
            'notify_role_ids' => array_map('strval', (array) old('notify_role_ids', [])),
            'notify_user_ids' => array_map('strval', (array) old('notify_user_ids', [])),
        ];
    @endphp

    <div class="grid gap-5 lg:grid-cols-5" x-data='{
            fields: @json($dateFields),
            blank: @json($draft + ["id" => null]),
            draft: @json($draft),
            load(watch) {
                this.draft = {
                    ...watch,
                    notify_role_ids: watch.notify_role_ids.map(String),
                    notify_user_ids: watch.notify_user_ids.map(String),
                };
                window.scrollTo({ top: 0, behavior: "smooth" });
            },
            reset() { this.draft = { ...this.blank, id: null }; },
        }'>

        {{-- ------------------------------------------------------------------ the watches --}}
        <div class="{{ $canManage ? 'lg:col-span-3' : 'lg:col-span-5' }} space-y-5">
            @if ($watches->isEmpty())
                <div class="card p-6 text-sm text-[var(--text-muted)]">
                    <p class="mb-2 font-medium text-[var(--text-strong)]">Nothing is being watched yet.</p>
                    <p>
                        A watch turns a date sitting in a register into an e-mail somebody gets in
                        time to do something about it. Until one exists, an insurance certificate
                        lapses quietly and the first anybody hears of it is the incident report.
                    </p>
                </div>
            @else
                @foreach ($watches->groupBy('entity_type') as $entityType => $group)
                    <div class="card overflow-hidden">
                        <div class="card-header">
                            <h2 class="card-title">{{ $entities[$entityType] ?? \Illuminate\Support\Str::headline($entityType) }}</h2>
                        </div>

                        <div class="divide-y divide-[var(--surface-border)]">
                            @foreach ($group as $watch)
                                <div class="p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="font-medium text-[var(--text-strong)]">
                                                {{ $watch->name }}
                                                @unless ($watch->is_active)
                                                    <x-badge tone="warning">Switched off</x-badge>
                                                @endunless
                                            </p>
                                            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                                Watches
                                                <span class="font-medium">{{ $dateFields[$entityType][$watch->date_field] ?? \Illuminate\Support\Str::headline($watch->date_field) }}</span>
                                                — warns
                                                {{ collect($watch->leadDays())->map(fn ($d) => $d === 0 ? 'on the day' : $d.' days before')->join(', ', ' and ') }}.
                                                @if ($watch->warn_when_overdue)
                                                    Keeps warning every day once the date has passed.
                                                @endif
                                            </p>

                                            <p class="mt-1 text-xs text-[var(--text-muted)]">
                                                Tells:
                                                @php
                                                    $audience = [];
                                                    if ($watch->notify_owner) { $audience[] = 'whoever owns the record'; }
                                                    if ($watch->notify_followers) { $audience[] = 'anybody following it'; }
                                                    foreach ($roles->whereIn('id', $watch->notifyRoleIds()) as $role) { $audience[] = 'every '.$role->name; }
                                                    foreach ($people->whereIn('id', $watch->notifyUserIds()) as $person) { $audience[] = $person->name; }
                                                @endphp
                                                {{ $audience === [] ? 'nobody' : implode(', ', $audience) }}.
                                                <span class="block italic">
                                                    Anybody on that list who cannot open the record is left off the reminder.
                                                </span>
                                            </p>
                                        </div>

                                        @if ($canManage)
                                            @php
                                                // Built here rather than inline in the @click, because @json splits its
                                                // expression on commas — the first comma becomes the flags argument and
                                                // everything after it is silently dropped. A one-element expression is
                                                // the only shape it can be trusted with.
                                                //
                                                // The shape is the draft's shape exactly. The editor swaps this in
                                                // wholesale, so a key that exists here and not there (or the reverse) is
                                                // a field that stops being editable the moment somebody clicks Edit.
                                                $editable = [
                                                    'id' => $watch->id,
                                                    'entity_type' => $watch->entity_type,
                                                    'date_field' => $watch->date_field,
                                                    'name' => $watch->name,
                                                    'lead_days' => implode(', ', $watch->leadDays()),
                                                    'warn_when_overdue' => (bool) $watch->warn_when_overdue,
                                                    'notify_owner' => (bool) $watch->notify_owner,
                                                    'notify_followers' => (bool) $watch->notify_followers,
                                                    'notify_role_ids' => array_map('strval', $watch->notifyRoleIds()),
                                                    'notify_user_ids' => array_map('strval', $watch->notifyUserIds()),
                                                ];
                                            @endphp

                                            <div class="flex shrink-0 items-center gap-2">
                                                <button type="button" class="btn btn-secondary btn-sm"
                                                        @click='load(@json($editable))'>
                                                    Edit
                                                </button>

                                                <form method="POST"
                                                      action="{{ route('configuration.expiry-watches.toggle', $watch) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="btn btn-secondary btn-sm">
                                                        {{ $watch->is_active ? 'Switch off' : 'Switch on' }}
                                                    </button>
                                                </form>

                                                <form method="POST"
                                                      action="{{ route('configuration.expiry-watches.destroy', $watch) }}"
                                                      onsubmit="return confirm('Delete this watch? Switching it off keeps the settings; deleting loses them.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- ------------------------------------------------------------------ the editor --}}
        @if ($canManage)
            <form method="POST" class="card self-start lg:col-span-2"
                  :action="draft.id
                      ? '{{ url('configuration/expiry-watches') }}/' + draft.id
                      : '{{ route('configuration.expiry-watches.store') }}'">
                @csrf
                <template x-if="draft.id">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                <div class="card-header">
                    <h2 class="card-title" x-text="draft.id ? 'Edit watch' : 'Watch a date'"></h2>
                    <button type="button" x-show="draft.id" class="btn btn-secondary btn-sm" @click="reset()">
                        Cancel
                    </button>
                </div>

                <div class="space-y-4 p-4">
                    <div>
                        <label class="label" for="entity_type">Register</label>
                        <select id="entity_type" name="entity_type" class="input" required
                                x-model="draft.entity_type" @change="draft.date_field = ''">
                            <option value="">Choose a register…</option>
                            @foreach ($entities as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="label" for="date_field">Which date</label>
                        <select id="date_field" name="date_field" class="input" required x-model="draft.date_field">
                            <option value="">Choose a date…</option>
                            <template x-for="(label, column) in (fields[draft.entity_type] || {})" :key="column">
                                <option :value="column" x-text="label"></option>
                            </template>
                        </select>
                        <p class="help">Only dates the platform can watch are listed. One watch per date.</p>
                    </div>

                    <div>
                        <label class="label" for="name">What to call it</label>
                        <input type="text" id="name" name="name" class="input" required maxlength="120"
                               x-model="draft.name" placeholder="Insurance certificate due to expire">
                        <p class="help">This is the sentence people read in the reminder, so write it for them.</p>
                    </div>

                    <div>
                        <label class="label" for="lead_days">Warn how many days ahead</label>
                        <input type="text" id="lead_days" name="lead_days" class="input" x-model="draft.lead_days"
                               placeholder="{{ implode(', ', $defaultLeadDays) }}">
                        <p class="help">
                            Separate them with commas — at most {{ $maxLeadDays }}. Use 0 for the day itself.
                            Far enough out to renew something, close enough to chase it.
                        </p>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="warn_when_overdue" value="1" class="mt-0.5"
                               x-model="draft.warn_when_overdue">
                        <span>
                            Keep warning after the date has passed
                            <span class="block text-xs text-[var(--text-muted)]">
                                For things that become a liability rather than merely stale — an uninsured
                                vehicle still on the road. Leave it off for dates that are simply history.
                            </span>
                        </span>
                    </label>

                    <div class="border-t border-[var(--surface-border)] pt-4">
                        <p class="label">Who hears about it</p>

                        <label class="mt-2 flex items-center gap-2 text-sm">
                            <input type="checkbox" name="notify_owner" value="1" x-model="draft.notify_owner">
                            Whoever owns the record
                        </label>

                        <label class="mt-2 flex items-center gap-2 text-sm">
                            <input type="checkbox" name="notify_followers" value="1" x-model="draft.notify_followers">
                            Anybody following it
                        </label>

                        <div class="mt-3">
                            <label class="label" for="notify_role_ids">Roles</label>
                            <select id="notify_role_ids" name="notify_role_ids[]" class="input" multiple size="4"
                                    x-model="draft.notify_role_ids">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mt-3">
                            <label class="label" for="notify_user_ids">Particular people</label>
                            <select id="notify_user_ids" name="notify_user_ids[]" class="input" multiple size="4"
                                    x-model="draft.notify_user_ids">
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <p class="help mt-2">
                            A reminder names the record it is about, so anybody on this list who is not
                            allowed to open that record is quietly left off it. Choose people who can act.
                        </p>
                    </div>
                </div>

                <div class="flex justify-end border-t border-[var(--surface-border)] p-4">
                    <button type="submit" class="btn btn-primary btn-sm"
                            x-text="draft.id ? 'Save changes' : 'Start watching'"></button>
                </div>
            </form>
        @endif
    </div>


</x-layouts.app>
