<x-layouts.app title="Joining and leaving lists">
    <x-page-header title="Joining and leaving lists" description="What a new starter is given and what a leaver hands back. None of it is in code — edit it here.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('checklist-templates.create', ['kind' => 'onboarding']) }}" class="btn btn-primary btn-sm">New list</a>
            @endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['Onboarding' => $onboarding, 'Exit' => $exit] as $heading => $group)
            <div class="card p-5">
                <h2 class="card-title">{{ $heading }}</h2>
                @if ($group->isEmpty())
                    <p class="mt-2 text-sm text-[var(--text-muted)]">No {{ mb_strtolower($heading) }} list yet.</p>
                @else
                    <div class="mt-3 divide-y divide-[var(--border)]">
                        @foreach ($group as $template)
                            <div class="flex items-center justify-between gap-3 py-3">
                                <div>
                                    <p class="font-medium">
                                        {{ $template->name }}
                                        @if ($template->is_default)<x-badge tone="info">Default</x-badge>@endif
                                        @if (! $template->is_active)<x-badge tone="neutral">Inactive</x-badge>@endif
                                    </p>
                                    <p class="mt-1 text-xs text-[var(--text-muted)]">
                                        {{ $template->items_count }} {{ Str::plural('step', $template->items_count) }}
                                        @if ($template->orgUnit) · {{ $template->orgUnit->name }} @endif
                                    </p>
                                </div>
                                <a href="{{ route('checklist-templates.edit', $template) }}" class="btn btn-ghost btn-sm">Edit</a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-layouts.app>
