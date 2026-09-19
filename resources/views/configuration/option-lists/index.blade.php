{{--
    Every list of words this company has defined for itself.

    Expects: $lists, $canManage
--}}
<x-layouts.app title="Option lists">

    <x-page-header
        title="Option lists"
        description="Your own words for the things this platform has no opinion about — disciplines, causes, classes, grades. Any dropdown field can offer one of these.">
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
                You can read these lists but not change them. Editing needs the
                <span class="font-medium">custom_field.manage</span> permission.
            </p>
        </div>
    @endunless

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="{{ $canManage ? 'lg:col-span-2' : 'lg:col-span-3' }}">
            <div class="card overflow-x-auto">
                @if ($lists->isEmpty())
                    <p class="p-6 text-sm text-[var(--text-muted)]">
                        No lists yet. Create one, fill it with the words your company actually uses,
                        and any dropdown field can then offer it.
                    </p>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th>List</th>
                                <th class="text-right">Options</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lists as $list)
                                <tr>
                                    <td>
                                        <a href="{{ route('configuration.option-lists.show', $list) }}"
                                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                            {{ $list->name }}
                                        </a>
                                        <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $list->key }}</span>
                                        @if ($list->description)
                                            <span class="mt-1 block text-xs text-[var(--text-muted)]">{{ $list->description }}</span>
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                                        {{ $list->active_items_count }}
                                        @if ($list->items_count > $list->active_items_count)
                                            <span class="text-xs">(+{{ $list->items_count - $list->active_items_count }} retired)</span>
                                        @endif
                                    </td>

                                    <td class="whitespace-nowrap text-right">
                                        @if ($list->isSystem())
                                            <x-badge tone="neutral">Built in</x-badge>
                                        @endif
                                        @unless ($list->is_active)
                                            <x-badge tone="warning">Switched off</x-badge>
                                        @endunless
                                        @if ($list->active_items_count === 0)
                                            <x-badge tone="danger">Empty</x-badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @if ($canManage)
            <form method="POST" action="{{ route('configuration.option-lists.store') }}" class="card self-start">
                @csrf

                <div class="card-header">
                    <h2 class="card-title">New list</h2>
                </div>

                <div class="space-y-4 p-4">
                    <div>
                        <label class="label" for="name">Name</label>
                        <input type="text" id="name" name="name" class="input"
                               value="{{ old('name') }}" required maxlength="120">
                        <p class="help">What you call it — "Disciplines", "Incident causes".</p>
                    </div>

                    <div>
                        <label class="label" for="key">Key</label>
                        <input type="text" id="key" name="key" class="input font-mono"
                               value="{{ old('key') }}" maxlength="64">
                        <p class="help">Leave blank and one will be made from the name.</p>
                    </div>

                    <div>
                        <label class="label" for="description">Description</label>
                        <input type="text" id="description" name="description" class="input"
                               value="{{ old('description') }}" maxlength="255">
                        <p class="help">For whoever configures this next year.</p>
                    </div>
                </div>

                <div class="flex justify-end border-t border-[var(--surface-border)] p-4">
                    <button type="submit" class="btn btn-primary btn-sm">Create list</button>
                </div>
            </form>
        @endif
    </div>

</x-layouts.app>
