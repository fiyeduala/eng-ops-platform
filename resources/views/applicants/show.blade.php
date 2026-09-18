<x-layouts.app :title="$applicant->number">
    <x-page-header :title="$applicant->fullName()" :description="$applicant->number.' — '.($applicant->vacancy?->title ?? 'no vacancy')">
        <x-slot:actions>
            @if ($canDownload)<a href="{{ route('applicants.cv', $applicant) }}" class="btn btn-secondary btn-sm">Download CV</a>@endif
            @if ($canEdit)<a href="{{ route('applicants.edit', $applicant) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php $status = $applicant->statusKey(); @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($applicant->notes)
                <div class="card p-5"><h2 class="card-title">Notes</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $applicant->notes }}</p></div>
            @endif

            @if ($applicant->decision_reason)
                <div class="card p-5">
                    <h2 class="card-title">{{ $status === 'withdrawn' ? 'Why they withdrew' : 'Why they were not taken forward' }}</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $applicant->decision_reason }}</p>
                </div>
            @endif

            <div class="card p-5">
                <h2 class="card-title">Interviews and stages</h2>
                @if ($stages->isEmpty())
                    <p class="mt-2 text-sm text-[var(--text-muted)]">No stage has been added yet.</p>
                @else
                    <div class="mt-3 space-y-3">
                        @foreach ($stages as $stage)
                            <div class="rounded border border-[var(--border)] p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="font-medium">{{ $stage->name }}</p>
                                    <x-badge :tone="match ($stage->outcome) { 'passed' => 'success', 'failed' => 'danger', default => 'neutral' }">{{ ucfirst($stage->outcome) }}</x-badge>
                                </div>
                                <p class="mt-1 text-xs text-[var(--text-muted)]">
                                    {{ $stage->scheduled_at?->format('j M Y H:i') ?? 'Not scheduled' }}
                                    @if ($stage->interviewer) · {{ $stage->interviewer->name }} @endif
                                    @if ($stage->score !== null) · scored {{ $stage->score }}/5 @endif
                                </p>
                                @if ($stage->comments)<p class="mt-2 whitespace-pre-line text-sm">{{ $stage->comments }}</p>@endif
                                @if ($canRecordStage && $stage->outcome === 'pending')
                                    <form method="POST" action="{{ route('applicant-stages.record', $stage) }}" class="mt-3 grid gap-2 sm:grid-cols-4">@csrf
                                        <x-select name="outcome" :options="['passed' => 'Passed', 'failed' => 'Failed']" placeholder="Outcome"/>
                                        <x-input type="number" name="score" min="0" max="5" placeholder="Score 0–5"/>
                                        <x-input name="comments" maxlength="500" placeholder="Comments" class="sm:col-span-2"/>
                                        <button class="btn btn-secondary btn-sm sm:col-span-4">Record outcome</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($canRecordStage)
                    <form method="POST" action="{{ route('applicants.stages.store', $applicant) }}" class="mt-4 grid gap-2 border-t border-[var(--border)] pt-4 sm:grid-cols-4">@csrf
                        <x-input name="name" maxlength="120" placeholder="Stage, e.g. Technical interview"/>
                        <x-input type="datetime-local" name="scheduled_at"/>
                        <x-select name="interviewer_id" :options="$people" placeholder="Interviewer"/>
                        <button class="btn btn-secondary btn-sm">Add stage</button>
                    </form>
                @endif
            </div>

            @if ($canHire && $status === 'offered')
                <div class="card p-5">
                    <h2 class="card-title">Hire</h2>
                    <p class="mt-1 text-sm text-[var(--text-muted)]">This writes a personnel record and counts a head against the vacancy. It does not create a sign-in — an administrator does that separately.</p>
                    <form method="POST" action="{{ route('applicants.hire', $applicant) }}" class="mt-4 grid gap-4 md:grid-cols-2">@csrf
                        <x-field label="Staff number" name="employee_no" required><x-input name="employee_no" maxlength="40"/></x-field>
                        <x-field label="Start date" name="hired_on" required><x-input type="date" name="hired_on" :value="old('hired_on', now()->toDateString())"/></x-field>
                        <x-field label="Probation ends" name="probation_ends_on"><x-input type="date" name="probation_ends_on"/></x-field>
                        <x-field label="Position" name="position_id"><x-select name="position_id" :options="$positions" :selected="$applicant->vacancy?->position_id" placeholder="From the vacancy"/></x-field>
                        <x-field label="Department" name="org_unit_id"><x-select name="org_unit_id" :options="$units" :selected="$applicant->vacancy?->org_unit_id" placeholder="From the vacancy"/></x-field>
                        <x-field label="Location" name="location_id"><x-select name="location_id" :options="$locations" :selected="$applicant->vacancy?->location_id" placeholder="From the vacancy"/></x-field>
                        <x-field label="Supervisor" name="supervisor_id" class="md:col-span-2"><x-select name="supervisor_id" :options="$supervisors" placeholder="None"/></x-field>
                        <div class="md:col-span-2"><button class="btn btn-primary">Hire {{ $applicant->fullName() }}</button></div>
                    </form>
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this application."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$applicant->status?->label"/>
                    <x-register.fact label="Vacancy" :value="$applicant->vacancy?->number"/>
                    <x-register.fact label="Email" :value="$applicant->email"/>
                    <x-register.fact label="Phone" :value="$applicant->phone"/>
                    <x-register.fact label="Source" :value="$applicant->source"/>
                    <x-register.fact label="Offered" :value="$applicant->offered_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Decided" :value="$applicant->decided_at?->format('j M Y H:i')"/>
                    @if ($applicant->hiredEmployee)
                        <x-register.fact label="Hired as" :value="$applicant->hiredEmployee->employee_no"/>
                    @endif
                </dl>
            </div>

            <div class="card p-5">
                <h2 class="card-title">CV</h2>
                @if ($applicant->hasCv())
                    <p class="mt-2 text-sm">{{ $applicant->cv_name }}</p>
                    <div class="mt-3 flex gap-2">
                        @if ($canDownload)<a href="{{ route('applicants.cv', $applicant) }}" class="btn btn-secondary btn-sm">Download</a>@endif
                        @if ($canAttachCv)
                            <form method="POST" action="{{ route('applicants.cv.remove', $applicant) }}">@csrf @method('DELETE')
                                <button class="btn btn-ghost btn-sm" onclick="return confirm('Remove this CV?')">Remove</button>
                            </form>
                        @endif
                    </div>
                @else
                    <p class="mt-2 text-sm text-[var(--text-muted)]">No CV on file.</p>
                @endif
                @if ($canAttachCv)
                    <form method="POST" action="{{ route('applicants.cv.attach', $applicant) }}" enctype="multipart/form-data" class="mt-4 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <input type="file" name="cv" class="input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <button class="btn btn-secondary btn-sm w-full">{{ $applicant->hasCv() ? 'Replace CV' : 'Attach CV' }}</button>
                    </form>
                @endif
            </div>

            @if ($canDecide && in_array($status, ['applied', 'screening', 'interview', 'offered'], true))
                <div class="card p-5 space-y-4">
                    <h2 class="card-title">Decision</h2>
                    @if ($status !== 'offered')
                        <form method="POST" action="{{ route('applicants.offer', $applicant) }}">@csrf
                            <button class="btn btn-primary btn-sm w-full">Make an offer</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('applicants.reject', $applicant) }}" class="space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Why are they not being taken forward?"></x-textarea>
                        <button class="btn btn-danger btn-sm w-full">Not taken forward</button>
                    </form>
                    <form method="POST" action="{{ route('applicants.withdraw', $applicant) }}" class="space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Anything they said (optional)"></x-textarea>
                        <button class="btn btn-ghost btn-sm w-full">They withdrew</button>
                    </form>
                </div>
            @endif

            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('applicants.destroy', $applicant)" label="Delete application" confirm="Delete this application?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
