{{--
    The builder. Phase 3's exit criteria are written about this screen: a route defined entirely
    here routes a real document through approval, with nobody editing a file and nothing deployed.

    THE CHECKLIST IS SHOWN WHILE THE ROUTE IS STILL A DRAFT, not as an error after pressing Publish.
    The person configuring a route is almost never the person who discovers it is broken — that
    happens days later, to whoever's document stalled — so every reason it cannot go live is listed
    the whole time it cannot.

    REORDERING IS ITS OWN ENDPOINT because `sequence` is uniquely indexed per workflow and swapping
    two stages collides mid-update. Up/down buttons rather than a drag library: no extra dependency,
    and it works on a touchscreen and with a keyboard, which dragging does not.

    Expects: $workflow, $stages, $problems, $conditionFields, $assigneeTypes, $stageActions,
             $quorums, $operators, $autoActions, $spawnableRecords, $timeline, $canEdit, $canPublish, $canVersion,
             $canDeactivate, $canDelete, $canAddStage
--}}
<x-layouts.app :title="$workflow->name">

    <x-page-header
        :title="$workflow->name"
        :description="$workflow->displayName().' · routes '.strtolower(\App\Support\WorkflowCatalogue::routableEntities()[$workflow->entity_type] ?? $workflow->entity_type)">
        <x-slot:actions>
            <a href="{{ route('workflows.index') }}" class="btn btn-sm btn-ghost">All routes</a>

            @if ($canEdit)
                <a href="{{ route('workflows.edit', $workflow) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Particulars
                </a>
            @endif

            @if ($canAddStage)
                <a href="{{ route('workflows.stages.create', $workflow) }}" class="btn btn-sm btn-primary">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add stage
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('publish')
        <div class="card mb-6 border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ---------------------------------------------------------- stages --}}
        <div class="space-y-6 lg:col-span-2"
             x-data="{
                order: {{ Js::from($stages->pluck('id')->values()) }},
                saving: false,
                error: '',
                move(index, delta) {
                    const target = index + delta;
                    if (target < 0 || target >= this.order.length) return;
                    const rows = Array.from(this.$refs.stages.children);
                    this.$refs.stages.insertBefore(rows[index], delta < 0 ? rows[target] : rows[target].nextSibling);
                    const [id] = this.order.splice(index, 1);
                    this.order.splice(target, 0, id);
                    this.persist();
                },
                async persist() {
                    this.saving = true;
                    this.error = '';
                    try {
                        const response = await fetch('{{ route('workflows.stages.reorder', $workflow) }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                            },
                            body: JSON.stringify({ stages: this.order }),
                        });
                        if (!response.ok) {
                            const body = await response.json().catch(() => ({}));
                            this.error = body.message ?? 'The new order was not saved. Reload before making further changes.';
                        }
                    } catch (e) {
                        this.error = 'No connection — the new order was not saved.';
                    } finally {
                        this.saving = false;
                    }
                },
             }">

            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">Stages</h3>
                    <span class="text-xs text-[var(--text-muted)]" x-show="saving" x-cloak>Saving order…</span>
                </div>

                <p class="border-b border-[var(--surface-border)] px-5 py-2 text-xs text-rose-600"
                   x-show="error" x-cloak x-text="error"></p>

                @if ($stages->isEmpty())
                    <x-empty-state
                        icon="target"
                        title="No stages yet"
                        description="A route with no stages approves nothing. Add the first step — who has to act, and what you are asking them to do.">
                        @if ($canAddStage)
                            <x-slot:actions>
                                <a href="{{ route('workflows.stages.create', $workflow) }}" class="btn btn-primary btn-sm">
                                    Add the first stage
                                </a>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]" x-ref="stages">
                        @foreach ($stages as $index => $stage)
                            <li class="p-5 {{ $stage->is_active ? '' : 'opacity-60' }}">
                                <div class="flex items-start gap-4">
                                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--surface-muted)] font-mono text-xs font-medium text-[var(--text-strong)]">
                                        {{ $stage->sequence }}
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-[var(--text-strong)]">{{ $stage->name }}</span>
                                            <x-badge tone="neutral">{{ $stage->actionType()->label() }}</x-badge>
                                            @unless ($stage->is_active)
                                                <x-badge tone="warning">Inactive</x-badge>
                                            @endunless
                                            @if ($stage->allow_self_approval)
                                                {{-- Called out rather than buried: self-approval is the
                                                     commonest control failure in approval systems, and
                                                     an administrator inheriting this route should see
                                                     that somebody chose it. --}}
                                                <x-badge tone="danger">Self-approval allowed</x-badge>
                                            @endif
                                        </div>

                                        <p class="mt-1 text-sm text-[var(--text-body)]">
                                            {{ $stage->assigneeType()->label() }}@if (filled($stage->assignee_ref))<span class="text-[var(--text-muted)]"> — {{ $stage->assignee_ref }}</span>@endif
                                        </p>

                                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                                            {{ $stage->quorumLabel() }}
                                            @if ($stage->hasSla())
                                                · due within {{ $stage->sla_hours }} working {{ Str::plural('hour', $stage->sla_hours) }}
                                            @else
                                                · no deadline
                                            @endif
                                            · on rejection, {{ strtolower(\App\Models\WorkflowStage::rejectionOptions()[$stage->on_reject] ?? $stage->on_reject) }}
                                            @unless ($stage->allow_delegate)
                                                · cannot be delegated
                                            @endunless
                                        </p>

                                        @if ($stage->instructions)
                                            <p class="mt-2 rounded-md bg-[var(--surface-muted)] px-3 py-2 text-sm text-[var(--text-body)]">
                                                {{ $stage->instructions }}
                                            </p>
                                        @endif

                                        @if ($stage->conditions->isNotEmpty())
                                            <div class="mt-2">
                                                <p class="text-xs font-medium text-[var(--text-strong)]">Only runs when</p>
                                                <ul class="mt-1 space-y-0.5">
                                                    @foreach ($stage->conditions as $condition)
                                                        <li class="text-xs text-[var(--text-muted)]">
                                                            @if (! $loop->first)
                                                                <span class="font-medium uppercase">{{ $condition->logic }}</span>
                                                            @endif
                                                            <span class="font-mono">{{ $conditionFields[$condition->field] ?? $condition->field }}</span>
                                                            {{ $operators[$condition->operator] ?? $condition->operator }}
                                                            <span class="font-mono">{{ $condition->value ?? '—' }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                                <p class="mt-1 text-xs text-[var(--text-muted)]">
                                                    A stage whose conditions fail is recorded as skipped, never silently omitted.
                                                </p>
                                            </div>
                                        @endif

                                        @if (filled($stage->auto_actions))
                                            <div class="mt-2">
                                                <p class="text-xs font-medium text-[var(--text-strong)]">When it opens, it also</p>
                                                <ul class="mt-1 space-y-0.5">
                                                    @foreach ((array) $stage->auto_actions as $action)
                                                        <li class="text-xs text-[var(--text-muted)]">
                                                            {{ $autoActions[$action['verb'] ?? ''] ?? ($action['verb'] ?? 'does something') }}
                                                            {{-- The named parameter, whichever one this verb uses. `value` was never a
                                                                 key the request accepted, so the old branch here could never fire. --}}
                                                            @php
                                                                $detail = match ($action['verb'] ?? '') {
                                                                    'create_record' => $spawnableRecords[$action['record'] ?? ''] ?? ($action['record'] ?? null),
                                                                    'set_status' => $action['status'] ?? null,
                                                                    'create_task' => $action['title'] ?? null,
                                                                    'notify' => $action['message'] ?? null,
                                                                    default => null,
                                                                };
                                                            @endphp
                                                            @if (filled($detail))
                                                                — <span class="font-mono">{{ Str::limit($detail, 60) }}</span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    </div>

                                    @if ($canEdit)
                                        <div class="flex shrink-0 flex-col items-end gap-1">
                                            <div class="flex items-center gap-1">
                                                <button type="button" class="btn btn-sm btn-ghost"
                                                        @click="move({{ $index }}, -1)"
                                                        @disabled($loop->first) title="Move earlier">↑</button>
                                                <button type="button" class="btn btn-sm btn-ghost"
                                                        @click="move({{ $index }}, 1)"
                                                        @disabled($loop->last) title="Move later">↓</button>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <a href="{{ route('workflows.stages.edit', [$workflow, $stage]) }}"
                                                   class="btn btn-sm btn-ghost">Edit</a>
                                                <x-delete-form
                                                    :action="route('workflows.stages.destroy', [$workflow, $stage])"
                                                    label="Remove"
                                                    :confirm="'Remove the stage '.$stage->name.'? The sequence closes up behind it.'"/>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <x-timeline :entries="$timeline" title="History of this route"
                        empty="Nothing has happened to this definition yet."/>
        </div>

        {{-- ---------------------------------------------------------- state --}}
        <div class="space-y-6">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status</h3>
                    <x-badge :tone="match ($workflow->statusLabel()) {
                        'Published' => 'success',
                        'Draft' => 'warning',
                        default => 'neutral',
                    }">{{ $workflow->statusLabel() }}</x-badge>
                </div>

                <div class="space-y-4 p-5">
                    @if ($workflow->isDraft())
                        @if ($problems === [])
                            <p class="text-sm text-[var(--text-body)]">
                                Nothing is stopping this going live. Publishing claims the default for its
                                module if you asked for that, retires the version it supersedes, and from
                                that moment new records take this route.
                            </p>
                        @else
                            <div>
                                <p class="text-sm font-medium text-[var(--text-strong)]">Not ready to publish</p>
                                <ul class="mt-2 space-y-1">
                                    @foreach ($problems as $problem)
                                        <li class="flex items-start gap-2 text-sm text-[var(--text-body)]">
                                            <x-icon name="alert" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600"/>
                                            <span>{{ $problem }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="help">
                                    Every reason is listed at once. A route that publishes cleanly and then
                                    stalls on its first document is discovered days later by somebody who
                                    did not configure it.
                                </p>
                            </div>
                        @endif

                        @if ($canPublish)
                            <form method="POST" action="{{ route('workflows.publish', $workflow) }}"
                                  onsubmit="return confirm('Publish this route? New records in this module start taking it immediately.');">
                                @csrf
                                <button type="submit" class="btn btn-primary w-full" @disabled($problems !== [])>
                                    <x-icon name="check" class="h-3.5 w-3.5"/> Publish
                                </button>
                            </form>
                        @endif
                    @else
                        <p class="text-sm text-[var(--text-body)]">
                            @if ($workflow->is_active)
                                This route is live. It is frozen — approvals travelling it must finish the
                                route they entered, so changes create version {{ $workflow->version + 1 }}
                                as a draft instead.
                            @else
                                This version has been superseded. It is kept because the approvals that
                                travelled it are only readable against the definition they were written for.
                            @endif
                        </p>

                        @if ($canVersion && $workflow->is_active)
                            <form method="POST" action="{{ route('workflows.version', $workflow) }}">
                                @csrf
                                <button type="submit" class="btn btn-secondary w-full">
                                    Start version {{ $workflow->version + 1 }}
                                </button>
                            </form>
                        @endif

                        @if ($canDeactivate && $workflow->is_active)
                            <form method="POST" action="{{ route('workflows.deactivate', $workflow) }}"
                                  onsubmit="return confirm('Take this route out of use? Approvals already running are unaffected.');">
                                @csrf
                                <button type="submit" class="btn btn-ghost w-full">Take out of use</button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="divide-y divide-[var(--surface-border)] text-sm">
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Module</dt>
                        <dd class="text-right text-[var(--text-body)]">
                            {{ \App\Support\WorkflowCatalogue::routableEntities()[$workflow->entity_type] ?? $workflow->entity_type }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Version</dt>
                        <dd class="text-right font-mono text-[var(--text-body)]">v{{ $workflow->version }}</dd>
                    </div>
                    @if ($workflow->supersedes)
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Replaces</dt>
                            <dd class="text-right text-[var(--text-body)]">
                                <a href="{{ route('workflows.show', $workflow->supersedes) }}" class="hover:text-brand-600">
                                    v{{ $workflow->supersedes->version }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Default for the module</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $workflow->is_default ? 'Yes' : 'No' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Started by</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $workflow->creator?->name ?? '—' }}</dd>
                    </div>
                    @if ($workflow->published_at)
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Published</dt>
                            <dd class="text-right text-[var(--text-body)]">
                                {{ $workflow->published_at->format('j M Y') }}
                                <span class="block text-xs text-[var(--text-muted)]">{{ $workflow->publisher?->name }}</span>
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($workflow->description)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $workflow->description }}</p>
                    </div>
                @endif
            </div>

            @if ($canDelete && $workflow->isDraft())
                <div class="card p-5">
                    <p class="text-sm font-medium text-[var(--text-strong)]">Delete this draft</p>
                    <p class="mt-1 text-sm text-[var(--text-muted)]">
                        Only ever offered on a draft. A published route is taken out of use, never deleted —
                        the approvals that travelled it point at it.
                    </p>
                    <div class="mt-3">
                        <x-delete-form
                            :action="route('workflows.destroy', $workflow)"
                            label="Delete draft"
                            :confirm="'Delete the draft '.$workflow->name.'? This cannot be undone.'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
