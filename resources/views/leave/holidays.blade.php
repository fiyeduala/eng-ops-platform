<x-layouts.app title="Public holidays">
    <x-page-header :title="'Public holidays '.$year" description="Days the company does not work. Leave counts skip them.">
        <x-slot:actions>
            <a href="{{ route('public-holidays.index', ['year' => $year - 1]) }}" class="btn btn-ghost btn-sm">← {{ $year - 1 }}</a>
            <a href="{{ route('public-holidays.index', ['year' => $year + 1]) }}" class="btn btn-ghost btn-sm">{{ $year + 1 }} →</a>
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-x-auto lg:col-span-2">
            <table class="table">
                <thead><tr><th>Date</th><th>Holiday</th><th>Repeats</th><th></th></tr></thead>
                <tbody>
                    @forelse ($holidays as $holiday)
                        <tr>
                            <td class="whitespace-nowrap">{{ $holiday->is_recurring ? $holiday->holiday_date->format('j M') : $holiday->holiday_date->format('D j M Y') }}</td>
                            <td>{{ $holiday->name }}</td>
                            <td>{{ $holiday->is_recurring ? 'Every year' : 'This year only' }}</td>
                            <td class="text-right">
                                @can('delete', $holiday)
                                    <x-delete-form :action="route('public-holidays.destroy', $holiday)" label="Remove" confirm="Remove this public holiday?"/>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-10 text-center">No public holidays for {{ $year }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($canCreate)
            <form method="POST" action="{{ route('public-holidays.store') }}" class="card space-y-4 p-5">
                @csrf
                <h2 class="card-title">Add a holiday</h2>
                <x-field label="Name" name="name" required><x-input name="name" :value="old('name')" maxlength="120"/></x-field>
                <x-field label="Date" name="holiday_date" required><x-input type="date" name="holiday_date" :value="old('holiday_date')"/></x-field>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_recurring" value="0"><input type="checkbox" name="is_recurring" value="1" @checked(old('is_recurring'))> Same date every year</label>
                <button class="btn btn-primary btn-sm">Add holiday</button>
            </form>
        @endif
    </div>
</x-layouts.app>
