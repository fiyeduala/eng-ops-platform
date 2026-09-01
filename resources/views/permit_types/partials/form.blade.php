{{--
    A permit type.

    THE PRECAUTION TEMPLATE IS THE POINT OF THIS SCREEN. Everything else here is a label; the
    checklist is what gets copied onto every future permit of this kind, and it is why this module
    sits behind `permit.manage` rather than `permit.edit` — somebody issuing permits day to day must
    not be able to take the gas test off the confined-space template.

    EDITING IT IS SAFE, and that is a deliberate property rather than an accident: the template is
    COPIED at permit creation, never referenced, so nothing changed here reaches backwards into a
    permit somebody has already signed (§59). Retiring a type is `is_active`, not deletion, so the
    permits filed under it keep their heading.

    Expects: $type, $precautions
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The type</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Code" name="code" required
                             help="Appears in permit numbers. Letters, numbers, hyphens.">
                        <x-input name="code" :value="old('code', $type->code)" required
                                 class="font-mono uppercase"/>
                    </x-field>

                    <div class="sm:col-span-2">
                        <x-field label="Name" name="name" required>
                            <x-input name="name" :value="old('name', $type->name)" required
                                     placeholder="e.g. Confined space entry"/>
                        </x-field>
                    </div>
                </div>

                <x-field label="What it covers" name="description"
                         help="Read by whoever is choosing a type when they raise a permit.">
                    <x-textarea name="description" rows="3">{{ old('description', $type->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Default duration" name="default_duration_hours"
                             help="Hours. Fills in the end of the window when it is left blank.">
                        <x-input type="number" name="default_duration_hours" min="1" max="336"
                                 :value="old('default_duration_hours', $type->default_duration_hours)"/>
                    </x-field>

                    <x-field label="Order" name="sort_order"
                             help="Where it sits in the picker.">
                        <x-input type="number" name="sort_order" min="0" max="9999"
                                 :value="old('sort_order', $type->sort_order)"/>
                    </x-field>

                    <x-field label="Colour" name="color"
                             help="Optional. Used on boards and printouts.">
                        <x-input name="color" :value="old('color', $type->color)" placeholder="#b45309"/>
                    </x-field>
                </div>

                <div class="space-y-3 border-t border-[var(--surface-border)] pt-4">
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="requires_gas_test" value="0">
                        <input type="checkbox" name="requires_gas_test" value="1" class="checkbox mt-0.5"
                               @checked(old('requires_gas_test', $type->requires_gas_test))>
                        <span class="text-sm text-[var(--text-body)]">
                            Requires a gas test
                            <span class="block text-xs text-[var(--text-muted)]">
                                A permit of this kind cannot be issued until a reading has been recorded.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2">
                        <input type="hidden" name="requires_isolation" value="0">
                        <input type="checkbox" name="requires_isolation" value="1" class="checkbox mt-0.5"
                               @checked(old('requires_isolation', $type->requires_isolation))>
                        <span class="text-sm text-[var(--text-body)]">
                            Requires an isolation
                            <span class="block text-xs text-[var(--text-muted)]">
                                Prompts the requester for the lock-out certificate reference.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="checkbox mt-0.5"
                               @checked(old('is_active', $type->is_active))>
                        <span class="text-sm text-[var(--text-body)]">
                            In use
                            <span class="block text-xs text-[var(--text-muted)]">
                                Unticking retires the type. Permits already filed under it keep their heading.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card" x-data="precautionTable(@js(old('precautions', $precautions)))">
            <div class="card-header">
                <h3 class="card-title">Precautions</h3>
                <p class="card-description">
                    Copied onto every new permit of this type.
                    <span x-text="requiredCount"></span> of them will block issuing.
                </p>
            </div>

            <div class="space-y-4 p-5">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="space-y-2 rounded-md border border-[var(--surface-border)] p-3">
                        <textarea x-model="row.description" rows="2" class="input text-sm"
                                  :name="`precautions[${i}][description]`"
                                  placeholder="e.g. Space isolated, purged and ventilated"></textarea>

                        <div class="flex items-center justify-between gap-2">
                            <label class="flex items-center gap-2 text-xs text-[var(--text-muted)]">
                                <input type="hidden" :name="`precautions[${i}][is_required]`" value="0">
                                <input type="checkbox" value="1" class="checkbox"
                                       x-model="row.is_required"
                                       :name="`precautions[${i}][is_required]`">
                                Must be confirmed before issue
                            </label>

                            <button type="button" class="btn btn-ghost btn-sm" @click="remove(i)">Remove</button>
                        </div>
                    </div>
                </template>

                <button type="button" class="btn btn-secondary btn-sm w-full" @click="add()">
                    Add a precaution
                </button>

                {{-- Blank rows are dropped by the form request rather than by the browser, so a row
                     somebody started and thought better of costs nothing. --}}
                <p class="text-xs text-[var(--text-muted)]">
                    Empty lines are discarded when you save.
                </p>
            </div>
        </div>
    </div>
</div>
