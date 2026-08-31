{{--
    The route's own particulars. Its stages are added afterwards, on the builder.

    Deliberately short. A route is a name, a module and a default flag; everything that makes it
    interesting is in its stages, and asking for all of it on one screen produces a form people
    abandon halfway. Creating the shell first also means the stage editor can validate conditions
    against a module that is already known.

    Expects: $workflow, $entities, and optionally $willFork
--}}
@php
    $editing = $workflow->exists;
@endphp

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ $editing ? 'Route particulars' : 'A new approval route' }}</h3>
        @if ($editing)
            <span class="text-xs text-[var(--text-muted)]">Version {{ $workflow->version }}</span>
        @endif
    </div>

    <div class="space-y-5 p-5">
        <x-field label="Name" name="name" required
                 help="What people will call it. &quot;Drawing approval&quot; beats &quot;WF-003&quot; on the day somebody has to work out why their document went where it did.">
            <x-input name="name" :value="old('name', $workflow->name)" required maxlength="150"
                     placeholder="e.g. Drawing approval — discipline then lead"/>
        </x-field>

        <x-field label="What it routes" name="entity_type" :required="! $editing"
                 :help="$editing
                    ? 'Fixed after creation. A route\'s stages, conditions and history are all written against one kind of record.'
                    : 'Only modules that can be routed are listed — the list comes from the code, so a module built later appears here without anybody registering it.'">
            @if ($editing)
                <x-input name="entity_type_display" :value="$entities[$workflow->entity_type] ?? $workflow->entity_type" disabled/>
            @else
                <x-select name="entity_type" :options="$entities" :selected="old('entity_type', $workflow->entity_type)"
                          placeholder="Choose a module" required/>
            @endif
        </x-field>

        <x-field label="Description" name="description"
                 help="Why this route exists and when it applies. Read by whoever inherits it.">
            <x-textarea name="description" :value="$workflow->description" rows="3"/>
        </x-field>

        <label class="flex items-start gap-3">
            <input type="hidden" name="is_default" value="0">
            <input type="checkbox" name="is_default" value="1"
                   @checked(old('is_default', $workflow->is_default))
                   class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
            <span class="text-sm">
                <span class="font-medium text-[var(--text-strong)]">Use this when nothing else names a route</span>
                <span class="block text-[var(--text-muted)]">
                    A record whose own category names a route takes that one. Everything else in this
                    module takes the default. Only one route per module can hold it, and it is claimed
                    at publication rather than now — a draft can never quietly become the default.
                </span>
            </span>
        </label>
    </div>

    @isset($willFork)
        @if ($willFork)
            <div class="border-t border-[var(--surface-border)] bg-[var(--surface-muted)] p-5">
                <p class="text-sm font-medium text-[var(--text-strong)]">Saving will create version {{ $workflow->version + 1 }}.</p>
                <p class="mt-1 text-sm text-[var(--text-muted)]">
                    This route is published, so it is frozen. Approvals already travelling it must finish
                    the route they entered — editing it underneath them would leave an approval history
                    describing something that never happened. The new version starts as a draft, and
                    version {{ $workflow->version }} stays live until you publish it.
                </p>
            </div>
        @endif
    @endisset
</div>
