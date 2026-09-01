{{--
    A training course.

    "NEVER EXPIRES" IS A CHOICE, NOT A BLANK. Leaving the validity empty is the honest way to record
    an induction, and the field says so in words — because the other reading, "nobody has decided
    yet", would quietly fill the sixty-day expiry report with noise until nobody read it.

    CHANGING THE VALIDITY DOES NOT RE-DATE ANYBODY'S TICKET. The expiry is worked out once, at the
    moment a certificate is recorded, and stored on that record. Shortening three years to two says
    something about the courses run from today (§59) — the help text says so, because an
    administrator who fears otherwise will leave a wrong figure in place for years.

    `is_mandatory` REFUSES NOTHING. It is a reporting flag: it puts the gap in front of the
    supervisor who decides, and a platform that blocked a person from a permit for want of a ticket
    would be worked around within a week.

    Expects: $course, $categories
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The course</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Code" name="code" required
                             help="Letters, numbers, hyphens.">
                        <x-input name="code" :value="old('code', $course->code)" required
                                 class="font-mono uppercase"/>
                    </x-field>

                    <div class="sm:col-span-2">
                        <x-field label="Name" name="name" required>
                            <x-input name="name" :value="old('name', $course->name)" required
                                     placeholder="e.g. Confined space entry and rescue"/>
                        </x-field>
                    </div>
                </div>

                <x-field label="What it covers" name="description">
                    <x-textarea name="description" rows="3">{{ old('description', $course->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Category" name="category">
                        <x-select name="category" :options="$categories"
                                  :selected="old('category', $course->category)"
                                  placeholder="Uncategorised"/>
                    </x-field>

                    <x-field label="Provider" name="provider"
                             help="Who runs it. Leave blank if it varies.">
                        <x-input name="provider" :value="old('provider', $course->provider)"/>
                    </x-field>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">How long it lasts</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Valid for (months)" name="validity_months"
                         help="Leave blank if this ticket never expires — an induction, for instance. Changing it does not move expiry dates on certificates already recorded.">
                    <x-input type="number" name="validity_months" min="1" max="600"
                             :value="old('validity_months', $course->validity_months)"
                             placeholder="Never expires"/>
                </x-field>

                <x-field label="Duration (hours)" name="duration_hours">
                    <x-input type="number" name="duration_hours" min="0" max="9999" step="0.5"
                             :value="old('duration_hours', $course->duration_hours)"/>
                </x-field>

                <x-field label="Order" name="sort_order" help="Where it sits in the picker.">
                    <x-input type="number" name="sort_order" min="0" max="9999"
                             :value="old('sort_order', $course->sort_order)"/>
                </x-field>

                <div class="space-y-3 border-t border-[var(--surface-border)] pt-4">
                    <label class="flex items-start gap-2">
                        <input type="hidden" name="is_mandatory" value="0">
                        <input type="checkbox" name="is_mandatory" value="1" class="checkbox mt-0.5"
                               @checked(old('is_mandatory', $course->is_mandatory))>
                        <span class="text-sm text-[var(--text-body)]">
                            Mandatory
                            <span class="block text-xs text-[var(--text-muted)]">
                                Reporting only. Nothing in the system refuses work because this is missing.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="checkbox mt-0.5"
                               @checked(old('is_active', $course->is_active))>
                        <span class="text-sm text-[var(--text-body)]">
                            In use
                            <span class="block text-xs text-[var(--text-muted)]">
                                Unticking retires it. Certificates already recorded are unaffected.
                            </span>
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>
