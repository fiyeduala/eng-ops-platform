{{--
    One training course.

    Expects: $course, $recordCount, $timeline, $canEdit, $canDelete
--}}
<x-layouts.app :title="$course->displayName()">

    <x-page-header :title="$course->name" :description="$course->code">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('training-courses.edit', $course) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
            <a href="{{ route('training-courses.index') }}" class="btn btn-ghost btn-sm">All courses</a>
        </x-slot:actions>
    </x-page-header>

    @unless ($course->is_active)
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm font-semibold text-[var(--text-strong)]">Retired</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                New certificates cannot be recorded against this course. The {{ $recordCount }}
                already on file are unaffected.
            </p>
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">How long the ticket lasts</h3>
                </div>
                <div class="space-y-4 p-5">
                    @if ($course->validity_months === null)
                        <p class="text-sm text-[var(--text-body)]">
                            <span class="font-medium text-[var(--text-strong)]">Never expires.</span>
                            Certificates against this course have no expiry date and never appear on
                            the lapsing report.
                        </p>
                    @else
                        <p class="text-sm text-[var(--text-body)]">
                            <span class="font-medium text-[var(--text-strong)]">{{ $course->validity_months }} months</span>
                            from the day the course was completed. A certificate recorded today would
                            run to
                            <span class="font-medium text-[var(--text-strong)]">{{ $course->expiryFrom(now())?->format('j M Y') }}</span>.
                        </p>
                    @endif

                    {{-- Said plainly on the page an administrator actually reads before editing,
                         not only in the edit form's warning. --}}
                    <p class="text-xs text-[var(--text-muted)]">
                        The expiry is worked out once, when a certificate is recorded, and stored on
                        that record. Changing this figure never re-dates a ticket somebody is
                        already carrying.
                    </p>
                </div>
            </div>

            @if ($course->description)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">What it covers</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm leading-relaxed text-[var(--text-body)]">{{ $course->description }}</p>
                    </div>
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History"
                        empty="This course has not been changed since it was added."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Code" :value="$course->code" mono/>
                    <x-register.fact label="State">
                        @if ($course->is_active)
                            <x-badge tone="success">In use</x-badge>
                        @else
                            <x-badge tone="neutral">Retired</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Category" :value="$course->category"/>
                    <x-register.fact label="Valid for"
                                     :value="$course->validity_months ? $course->validity_months.' months' : 'Never expires'"/>
                    <x-register.fact label="Mandatory" :value="$course->is_mandatory ? 'Yes (reporting only)' : 'No'"/>
                    <x-register.fact label="Provider" :value="$course->provider"/>
                    <x-register.fact label="Duration"
                                     :value="$course->duration_hours ? $course->duration_hours.' hours' : null"/>
                    <x-register.fact label="Certificates" :value="(string) $recordCount"/>
                    <x-register.fact label="Order" :value="(string) $course->sort_order"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        @if ($recordCount > 0)
                            <p class="text-sm text-[var(--text-muted)]">
                                {{ $recordCount }} {{ Str::plural('certificate', $recordCount) }} are
                                recorded against this course, so it cannot be deleted — those records
                                would lose what they are for. Untick "in use" to retire it instead.
                            </p>
                        @else
                            <p class="text-sm text-[var(--text-muted)]">
                                Nothing has been recorded against this course, so removing it loses nothing.
                            </p>
                            <x-delete-form :action="route('training-courses.destroy', $course)"
                                           label="Delete this course"
                                           confirm="Delete this course?"/>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
