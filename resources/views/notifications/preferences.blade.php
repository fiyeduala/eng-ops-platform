{{--
    Notification settings.

    What this screen controls is delivery, and only delivery. It cannot widen anything: a person who
    switches everything on still hears only about records they could open themselves, because that
    decision was made before this one, in NotificationDispatcher.

    Mandatory rows keep their in-app copy fixed on, and say so in words rather than showing a
    disabled switch. Being handed work you never hear about is a worse failure than an inbox you
    cannot fully mute.

    Two channels are absent on purpose. Push has a column in the database and a default in the
    catalogue but no transport yet; the daily digest has a preference column but no job to assemble
    it. Neither is offered here, because a switch with nothing behind it is indistinguishable from a
    feature that is quietly broken — the stored choice will simply start working when the mechanism
    lands (docs/10 §3, §4).

    Expects: $groups — a collection of definition arrays keyed by group label, each with ['current']
--}}
<x-layouts.app title="Notification settings">

    <x-page-header
        title="Notification settings"
        description="How you would like to be told. What you are told about is decided by your access, not here.">
        <x-slot:actions>
            <a href="{{ route('notifications.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="bell" class="h-3.5 w-3.5"/> Back to notifications
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('notifications.preferences.update') }}">
        @csrf
        @method('PUT')

        @foreach ($groups as $groupName => $rows)
            <div class="card mb-5 overflow-hidden">
                <div class="border-b border-[var(--surface-border)] px-4 py-3">
                    <h2 class="text-sm font-semibold text-[var(--text-strong)]">{{ $groupName }}</h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Notification</th>
                                <th class="w-24 text-center">In app</th>
                                <th class="w-24 text-center">Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>
                                        <span class="font-medium text-[var(--text-strong)]">{{ $row['label'] }}</span>
                                        @if ($row['urgency'] !== 'normal')
                                            <x-badge :tone="$row['urgency'] === 'urgent' ? 'danger' : 'warning'" class="ml-1">
                                                {{ ucfirst($row['urgency']) }}
                                            </x-badge>
                                        @endif
                                        <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                            {{ $row['description'] }}
                                            @if ($row['mandatory'])
                                                <span class="mt-0.5 block">
                                                    Always appears in the application — this one tells you something
                                                    you are expected to act on.
                                                </span>
                                            @endif
                                        </span>
                                    </td>

                                    {{--
                                        The hidden field before each box is what makes "off" arrive at all: an
                                        unticked checkbox sends nothing, and nothing is indistinguishable from a
                                        field that was never on the page. Same pattern as the settings screen.
                                    --}}
                                    <td class="text-center">
                                        @if ($row['mandatory'])
                                            <input type="hidden" name="preferences[{{ $row['key'] }}][in_app]" value="1">
                                            <x-icon name="lock" class="mx-auto h-4 w-4 text-[var(--text-muted)]"/>
                                        @else
                                            <input type="hidden" name="preferences[{{ $row['key'] }}][in_app]" value="0">
                                            <input type="checkbox"
                                                   name="preferences[{{ $row['key'] }}][in_app]"
                                                   value="1"
                                                   aria-label="Show {{ $row['label'] }} in the application"
                                                   @checked($row['current']['in_app'])
                                                   class="h-4 w-4 rounded border-[var(--surface-border)]">
                                        @endif
                                    </td>

                                    <td class="text-center">
                                        <input type="hidden" name="preferences[{{ $row['key'] }}][email]" value="0">
                                        <input type="checkbox"
                                               name="preferences[{{ $row['key'] }}][email]"
                                               value="1"
                                               aria-label="Email me about {{ $row['label'] }}"
                                               @checked($row['current']['email'])
                                               class="h-4 w-4 rounded border-[var(--surface-border)]">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save settings
            </button>
            <a href="{{ route('notifications.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
