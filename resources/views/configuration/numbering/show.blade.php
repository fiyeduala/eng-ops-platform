{{--
    One register's reference numbering.

    THE PREVIEW IS THE WHOLE INTERFACE. Everything else on this page is a field; the preview is the
    only part that answers the question the administrator actually has, which is "what will my
    records be called". It is rendered by the server — by the same engine that issues real numbers —
    because a JavaScript copy of the token rules would eventually disagree with the real thing and
    show a number the platform would never issue.

    The preview also carries the refusals. A pattern missing {SEQ}, or a yearly reset with no year in
    the pattern, is reported here as the administrator types rather than only when they save, so the
    problem is attached to the thing that caused it.

    Expects: $entityType, $scheme, $counters, $example, $tokens, $resetPeriods, $canManage
--}}
<x-layouts.app :title="$scheme->displayName()">

    <x-page-header
        :title="$scheme->displayName()"
        description="How records in this register are named. Changing any of this affects future records only.">
        <x-slot:actions>
            <a href="{{ route('configuration.numbering.index') }}" class="btn btn-secondary btn-sm">
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
                You can read this configuration but not change it. Editing needs the
                <span class="font-medium">numbering.manage</span> permission.
            </p>
        </div>
    @endunless

    <div class="grid gap-5 lg:grid-cols-3"
         x-data="{
            pattern: @js(old('pattern', $scheme->pattern)),
            prefix: @js(old('prefix', $scheme->prefix ?? '')),
            reset: @js(old('reset_period', $scheme->reset_period)),
            padding: @js((int) old('padding', $scheme->padding)),
            example: @js($example),
            problem: '',
            checking: false,
            timer: null,
            /*
             * Debounced because this is keystroke-driven. Without it a twenty-character pattern is
             * twenty round trips, and the answers can arrive out of order — leaving the preview
             * showing the result of a pattern the administrator has already moved past.
             */
            queue() {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.refresh(), 350);
            },
            async refresh() {
                this.checking = true;
                try {
                    const response = await fetch('{{ route('configuration.numbering.preview', $entityType) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        },
                        body: JSON.stringify({
                            pattern: this.pattern,
                            prefix: this.prefix,
                            reset_period: this.reset,
                            padding: this.padding,
                        }),
                    });
                    if (!response.ok) {
                        this.problem = 'That is not something this screen can check — correct the fields above.';
                        this.example = null;
                        return;
                    }
                    const body = await response.json();
                    this.example = body.example;
                    this.problem = body.problem ?? '';
                } catch (e) {
                    /* Offline: leave the last good example alone rather than claim a problem
                       with the pattern, which would be a lie about the administrator's work. */
                    this.problem = '';
                } finally {
                    this.checking = false;
                }
            },
            insert(token) {
                this.pattern = (this.pattern ?? '') + '{' + token + '}';
                this.queue();
            },
         }">

        {{-- ------------------------------------------------------------- the form --}}
        <div class="lg:col-span-2">
            <form method="POST" action="{{ route('configuration.numbering.update', $entityType) }}" class="card">
                @csrf
                @method('PUT')

                <div class="card-header">
                    <h2 class="card-title">The pattern</h2>
                </div>

                <div class="space-y-4 p-4">
                    <div>
                        <label class="label" for="pattern">Pattern</label>
                        <input type="text" id="pattern" name="pattern" class="input font-mono"
                               x-model="pattern" @input="queue()"
                               @disabled(! $canManage) required maxlength="120">
                        <p class="help">
                            Anything outside the {{ '{braces}' }} is printed as you type it — including the
                            dashes and slashes that separate the parts.
                        </p>
                    </div>

                    @if ($canManage)
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($tokens as $token => $meaning)
                                <button type="button" @click="insert(@js($token))"
                                        class="btn btn-secondary btn-sm font-mono"
                                        title="{{ $meaning }}">{{ '{'.$token.'}' }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label class="label" for="prefix">Prefix</label>
                            <input type="text" id="prefix" name="prefix" class="input font-mono"
                                   x-model="prefix" @input="queue()"
                                   @disabled(! $canManage) maxlength="12">
                            <p class="help">What {{ '{PREFIX}' }} becomes.</p>
                        </div>

                        <div>
                            <label class="label" for="padding">Digits</label>
                            <input type="number" id="padding" name="padding" class="input"
                                   x-model.number="padding" @input="queue()"
                                   @disabled(! $canManage) min="1" max="12" required>
                            <p class="help">4 gives 0007.</p>
                        </div>

                        <div>
                            <label class="label" for="start_at">Starting number</label>
                            <input type="number" id="start_at" name="start_at" class="input"
                                   value="{{ old('start_at', $scheme->start_at) }}"
                                   @disabled(! $canManage) min="1" required>
                            <p class="help">Where a new period begins counting.</p>
                        </div>
                    </div>

                    <div>
                        <label class="label" for="reset_period">Start counting again</label>
                        <select id="reset_period" name="reset_period" class="select"
                                x-model="reset" @change="queue()" @disabled(! $canManage)>
                            @foreach ($resetPeriods as $period)
                                <option value="{{ $period }}">
                                    {{ ['none' => 'Never — one continuous series', 'yearly' => 'Every year', 'monthly' => 'Every month'][$period] ?? $period }}
                                </option>
                            @endforeach
                        </select>
                        <p class="help">
                            A series that starts again needs the date in the pattern, or next year's
                            references would repeat this year's.
                        </p>
                    </div>

                    <div>
                        <label class="label" for="label">Name this scheme</label>
                        <input type="text" id="label" name="label" class="input"
                               value="{{ old('label', $scheme->label) }}"
                               @disabled(! $canManage) maxlength="80">
                        <p class="help">For your own reference on the list of registers.</p>
                    </div>
                </div>

                @if ($canManage)
                    <div class="flex items-center justify-end gap-2 border-t border-[var(--surface-border)] p-4">
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                @endif
            </form>
        </div>

        {{-- ------------------------------------------------------- preview and counters --}}
        <div class="space-y-5">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">The next reference</h2>
                </div>

                <div class="p-4">
                    <p class="font-mono text-lg text-[var(--text-strong)]"
                       :class="checking && 'opacity-50'"
                       x-text="example ?? '—'">{{ $example }}</p>

                    <p class="mt-2 text-sm text-red-600" x-show="problem" x-cloak x-text="problem"></p>

                    <p class="help mt-3" x-show="! problem">
                        This is the reference the next record created in this register would carry.
                        Nothing here consumes it.
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Issued so far</h2>
                </div>

                @if ($counters === [])
                    <p class="p-4 text-sm text-[var(--text-muted)]">
                        Nothing has been numbered in this register yet, so the pattern can still be
                        changed freely.
                    </p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th class="text-right">Issued</th>
                                <th class="text-right">Next</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($counters as $counter)
                                <tr>
                                    <td class="whitespace-nowrap">{{ $counter['period'] }}</td>
                                    <td class="text-right tabular-nums text-[var(--text-muted)]">{{ $counter['issued'] }}</td>
                                    <td class="text-right tabular-nums text-[var(--text-muted)]">{{ $counter['next'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p class="help border-t border-[var(--surface-border)] p-4">
                        References already issued keep the form they were given. Changing the pattern
                        renames nothing.
                    </p>
                @endif
            </div>
        </div>

    </div>

</x-layouts.app>
