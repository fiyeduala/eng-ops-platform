{{--
    A repeating table, as somebody fills it in.

    ROWS ARE ADDED AND REMOVED ON THE PAGE, WITHOUT A ROUND TRIP, and the mechanism is deliberately
    dull: every row that could be needed is rendered by the server, and Alpine only decides how many
    of them are on screen. Building rows in the browser would mean a second copy of every control —
    which select draws from which option list, what a date input looks like, where an error message
    goes — and two copies of that drift apart on the day somebody adds a field type.

    REMOVING A ROW SHIFTS THE ONES BELOW IT UP and blanks the last, rather than hiding the row where
    it stands. A hidden row in the middle would still be on the page, still be posted, and still be
    dropped by the compiler — so the data would be right and the screen would be lying about which
    row was which while the person was looking at it.

    A HIDDEN ROW IS AN EMPTY ROW, AND THAT IS THE WHOLE TRICK. Rows that are not on screen post blank
    cells, and FormCompiler drops any row nobody typed in and re-indexes the rest. Nothing here has
    to be trusted: min_rows and max_rows are enforced again server-side, and a payload with two
    hundred rows in it is refused by the compiler whatever this page allowed (§84).

    THE ROW NUMBER IN THE FIELD NAME IS NOT AN IDENTIFIER. It is a position in this payload, and the
    compiler re-numbers from zero on save — so a posted index of 900, or a duplicate, changes where
    a row lands and nothing else.

    Expects: $table, $columns, $rows (answered rows), $compiler, $editable
--}}
@php
    $answered = is_array($rows) ? array_values($rows) : [];
    $minimum = (int) $table->setting('min_rows', 0);
    $ceiling = (int) $table->setting('max_rows', 0);

    // On screen when the page opens: everything already answered, never fewer than the author's
    // minimum, and never fewer than one — an empty table with no row to type into is a dead end.
    $visible = max(count($answered), $minimum, 1);

    // The pool a row is added FROM. Ten spare is more than anybody adds in one sitting and far
    // below the compiler's own 200-row ceiling; somebody filling in a sixty-bay scaffold saves
    // once on the way, which is what they would do anyway.
    $spare = 10;
    $pool = $visible + $spare;

    if ($ceiling > 0) {
        $visible = min($visible, $ceiling);
        $pool = min($pool, $ceiling);
    }

    // A submitted sheet shows what was answered and nothing else. Blank rows to type into are for
    // typing into.
    if (! $editable) {
        $visible = $pool = count($answered);
    }

    $floor = max($minimum, 1);
    $addLabel = $table->setting('add_label') ?: 'Add a row';
@endphp

<div>
    <p class="label">
        {{ $table->label }}
        @if ($table->is_required)
            <span class="text-[var(--text-muted)]">*</span>
        @endif
    </p>
    @if ($table->help)
        <p class="help mb-2">{{ $table->help }}</p>
    @endif

    @if ($columns->isEmpty())
        <p class="text-sm text-[var(--text-muted)]">
            This table has no columns yet — whoever built the form needs to add them.
        </p>
    @elseif ($pool === 0)
        <p class="text-sm text-[var(--text-muted)]">Nothing was entered here.</p>
    @else
        <div x-data="{
                 shown: {{ $visible }},
                 pool: {{ $pool }},
                 floor: {{ $floor }},

                 add() {
                     if (this.shown < this.pool) this.shown++;
                 },

                 /*
                  * Shift every row below this one up, blank what is now the last row, and show one
                  * fewer. The person sees the row they pointed at disappear, which is what they
                  * asked for; what is posted is a table with one fewer row in it.
                  */
                 remove(row) {
                     const rows = Array.from(this.$refs.body.rows);
                     const at = rows.indexOf(row);

                     if (at < 0) return;

                     for (let i = at; i < this.shown - 1; i++) this.copy(rows[i + 1], rows[i]);

                     this.blank(rows[this.shown - 1]);

                     if (this.shown > this.floor) this.shown--;
                 },

                 copy(from, to) {
                     const source = from.querySelectorAll('input, select, textarea');
                     const target = to.querySelectorAll('input, select, textarea');

                     source.forEach((el, i) => {
                         if (! target[i]) return;
                         if (el.type === 'checkbox') target[i].checked = el.checked;
                         else target[i].value = el.value;
                     });
                 },

                 blank(row) {
                     row.querySelectorAll('input, select, textarea').forEach(el => {
                         /* The hidden 0 behind a checkbox is what makes 'unticked' different from
                            'never seen' — see the control partial. Blanking it would turn an empty
                            row into a row that says No. */
                         if (el.type === 'hidden') return;
                         if (el.type === 'checkbox') el.checked = false;
                         else el.value = '';
                     });
                 },
             }">
            <div class="overflow-x-auto rounded-lg border border-[var(--surface-border)]">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-10">#</th>
                            @foreach ($columns as $column)
                                <th>
                                    {{ $column->label }}
                                    @if ($column->is_required)
                                        <span class="text-[var(--text-muted)]">*</span>
                                    @endif
                                </th>
                            @endforeach
                            @if ($editable)
                                <th class="w-10"><span class="sr-only">Remove</span></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody x-ref="body">
                        @for ($row = 0; $row < $pool; $row++)
                            {{-- The inline style is what keeps the spare rows from flashing on
                                 screen before Alpine starts. x-show takes over from it on init. --}}
                            <tr x-show="shown > {{ $row }}"
                                @if ($row >= $visible) style="display: none" @endif>
                                <td class="text-[var(--text-muted)]">{{ $row + 1 }}</td>
                                @foreach ($columns as $column)
                                    <td>
                                        @include('form-submissions.partials.control', [
                                            'field' => $column,
                                            'key' => 'answers.'.$table->key.'.'.$row.'.'.$column->key,
                                            'value' => $answered[$row][$column->key] ?? null,
                                            'compiler' => $compiler,
                                            'editable' => $editable,
                                            'bare' => true,
                                        ])
                                    </td>
                                @endforeach
                                @if ($editable)
                                    <td>
                                        <button type="button" class="btn btn-ghost btn-sm"
                                                title="Remove this row"
                                                @click="remove($el.closest('tr'))">
                                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                                            <span class="sr-only">Remove row {{ $row + 1 }}</span>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>

            @if ($editable)
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <button type="button" class="btn btn-secondary btn-sm"
                            x-show="shown < pool"
                            @click="add()">
                        <x-icon name="plus" class="h-3.5 w-3.5"/> {{ $addLabel }}
                    </button>

                    <p class="help">
                        @if ($minimum > 0)
                            At least {{ $minimum }} {{ Str::plural('row', $minimum) }}.
                        @endif
                        @if ($ceiling > 0)
                            At most {{ $ceiling }} {{ Str::plural('row', $ceiling) }}.
                        @endif
                        <span x-show="shown >= pool" x-cloak>
                            Save the page to be given more rows.
                        </span>
                    </p>
                </div>
            @endif
        </div>
    @endif
</div>
