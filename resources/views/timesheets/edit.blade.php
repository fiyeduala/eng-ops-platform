<x-layouts.app :title="'Hours — '.$sheet->number">
    <x-page-header :title="$sheet->employee?->fullName().' — '.$sheet->periodLabel()" :description="$sheet->number.' · hours in decimals, e.g. 7.5'">
        <x-slot:actions>
            <a href="{{ route('timesheets.show', $sheet) }}" class="btn btn-ghost btn-sm">View</a>
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php
        $oldLines = old('lines', $lines);
        $keys = array_map(fn ($d) => $d->toDateString(), $days);
    @endphp

    <form method="POST" action="{{ route('timesheets.update', $sheet) }}"
          x-data="{
              keys: @js($keys),
              total(key) {
                  let sum = 0;
                  this.$root.querySelectorAll('[data-day=\'' + key + '\']').forEach(i => sum += parseFloat(i.value) || 0);
                  return Math.round(sum * 100) / 100;
              },
              week() { return Math.round(this.keys.reduce((s, k) => s + this.total(k), 0) * 100) / 100; },
              tick: 0
          }"
          x-on:input="tick++">
        @csrf @method('PUT')
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[16rem]">Project, task and what you did</th>
                            @foreach ($days as $day)<th class="text-right">{{ $day->format('D') }}<br><span class="font-normal text-[var(--text-muted)]">{{ $day->format('j M') }}</span></th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($oldLines as $i => $line)
                            <tr>
                                <td class="space-y-2">
                                    <x-select :name="'lines['.$i.'][project_id]'" :options="$projects" :selected="$line['project_id'] ?? null" placeholder="Project"/>
                                    @if ($tasks !== [])
                                        <x-select :name="'lines['.$i.'][task_id]'" :options="$tasks" :selected="$line['task_id'] ?? null" placeholder="Task (optional)"/>
                                    @endif
                                    <x-input :name="'lines['.$i.'][description]'" :value="$line['description'] ?? ''" maxlength="255" placeholder="Description (optional)"/>
                                </td>
                                @foreach ($keys as $key)
                                    <td class="align-top">
                                        <input type="text" inputmode="decimal" maxlength="5" data-day="{{ $key }}"
                                               name="lines[{{ $i }}][hours][{{ $key }}]" value="{{ $line['hours'][$key] ?? '' }}"
                                               class="input w-16 text-right font-mono" aria-label="Hours on {{ $key }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <td>Booked</td>
                            @foreach ($keys as $key)<td class="text-right font-mono" x-text="tick, total('{{ $key }}')"></td>@endforeach
                        </tr>
                        <tr class="text-[var(--text-muted)]">
                            <td>Clocked</td>
                            @foreach ($keys as $key)<td class="text-right font-mono">{{ isset($clocked[$key]) ? round($clocked[$key] / 60, 2) : '' }}</td>@endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <p class="mt-2 text-sm">Week total: <span class="font-mono font-semibold" x-text="tick, week()"></span>h
            <span class="text-xs text-[var(--text-muted)]">· save to add more rows. Only projects you work on are offered.</span></p>

        <div class="card mt-6 p-5">
            <x-field label="Notes for your approver" name="notes">
                <x-textarea name="notes" rows="2">{{ old('notes', $sheet->notes) }}</x-textarea>
            </x-field>
            <div class="mt-5 flex flex-wrap gap-2">
                <button class="btn btn-secondary">Save hours</button>
                @if ($canSubmit)
                    <button class="btn btn-primary" name="submit_now" value="1">Save and submit</button>
                @endif
                <a href="{{ route('timesheets.show', $sheet) }}" class="btn btn-ghost">Cancel</a>
            </div>
        </div>
    </form>
</x-layouts.app>
