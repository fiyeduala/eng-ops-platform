{{--
    The course catalogue.

    THE VALIDITY COLUMN IS THE ONE THAT MATTERS. It is the only field on a course that does any
    work: it turns a completion date into an expiry date at the moment a certificate is recorded,
    and the expiry date is the only reason anybody opens the training register. "Never expires" is
    printed as words rather than left blank, because a blank cell reads as "nobody has decided yet"
    and an induction genuinely does not lapse.

    Expects: $courses, $categories, $filters, $canManage
--}}
<x-layouts.app title="Training courses">

    <x-page-header
        title="Training courses"
        description="What this company trains people in, and how long each ticket lasts.">
        @if ($canManage)
            <x-slot:actions>
                <a href="{{ route('training-courses.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a course
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form method="GET" action="{{ route('training-courses.index') }}"
          class="mb-6 flex flex-wrap items-end gap-3">
        <div class="w-56">
            <label for="category" class="label">Category</label>
            <x-select name="category" :options="$categories" :selected="$filters['category']"
                      placeholder="Every category"/>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        @if ($filters['category'])
            <a href="{{ route('training-courses.index') }}" class="btn btn-ghost btn-sm">Clear</a>
        @endif
    </form>

    @if ($courses->isEmpty())
        <div class="card overflow-hidden">
            <x-empty-state icon="badge" title="No courses yet"
                           description="Until a course exists there is nothing to record certificates against. Add the training this company actually runs or accepts.">
                @if ($canManage)
                    <x-slot:actions>
                        <a href="{{ route('training-courses.create') }}" class="btn btn-primary btn-sm">Add the first course</a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Course</th>
                        <th>Category</th>
                        <th>Valid for</th>
                        <th>Provider</th>
                        <th class="text-right">Certificates</th>
                        <th>State</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($courses as $course)
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('training-courses.show', $course) }}"
                                   class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                    {{ $course->code }}
                                </a>
                            </td>

                            <td>
                                <a href="{{ route('training-courses.show', $course) }}"
                                   class="text-[var(--text-body)] hover:text-brand-600">
                                    {{ $course->name }}
                                </a>
                                @if ($course->is_mandatory)
                                    <span class="ml-1 text-xs font-medium text-amber-700 dark:text-amber-300">mandatory</span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-[var(--text-muted)]">
                                {{ $course->category ?: '—' }}
                            </td>

                            <td class="whitespace-nowrap text-[var(--text-muted)]">
                                @if ($course->validity_months === null)
                                    Never expires
                                @else
                                    {{ $course->validity_months }} months
                                @endif
                            </td>

                            <td class="whitespace-nowrap text-[var(--text-muted)]">
                                {{ $course->provider ?: '—' }}
                            </td>

                            <td class="whitespace-nowrap text-right tabular-nums text-[var(--text-muted)]">
                                {{ $course->records_count }}
                            </td>

                            <td class="whitespace-nowrap">
                                @if ($course->is_active)
                                    <x-badge tone="success">In use</x-badge>
                                @else
                                    <x-badge tone="neutral">Retired</x-badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</x-layouts.app>
