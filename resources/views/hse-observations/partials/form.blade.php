{{--
    An observation card. It has to be fillable in under a minute or nobody fills it.

    ONLY THE TITLE IS REQUIRED. Everything else on this form can be left blank, because the worst
    possible outcome for a near-miss scheme is a card that goes unfiled because the person could not
    remember which category it was. A thin card is worth more than no card.

    ANONYMOUS IS A REAL PROMISE, NOT A DISPLAY SETTING. When it is ticked the service writes no
    `observed_by` at all, so there is nothing to leak later to somebody with database access. The
    help text says the cost out loud — nobody can come back to you with a question — because a
    promise whose terms are hidden is not one.

    ACTION REQUIRED IS A FLAG, NOT AN ACTION. Ticking it puts the card in the "action required" view;
    the corrective action itself is raised on the card's own page, where it gets a number, an owner
    and a date.

    Expects: $observation, $projects, $units, $confidentialities, $types, $categories, $riskLevels
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What you saw</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="In a line" name="title" required>
                    <x-input name="title" :value="old('title', $observation->title)" required
                             placeholder="Scaffold guard rail missing on level 3"/>
                </x-field>

                <x-field label="More, if there is more" name="description">
                    <x-textarea name="description" rows="4">{{ old('description', $observation->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Type" name="observation_type">
                        <x-select name="observation_type" :options="$types"
                                  :selected="old('observation_type', $observation->observation_type)"
                                  placeholder="Choose one"/>
                    </x-field>

                    <x-field label="Category" name="category">
                        <x-select name="category" :options="$categories"
                                  :selected="old('category', $observation->category)"
                                  placeholder="Choose one"/>
                    </x-field>

                    <x-field label="Risk" name="risk_level">
                        <x-select name="risk_level" :options="$riskLevels"
                                  :selected="old('risk_level', $observation->risk_level)"
                                  placeholder="Choose one"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Where" name="location">
                        <x-input name="location" :value="old('location', $observation->location)"/>
                    </x-field>

                    <x-field label="When" name="observed_at">
                        <x-input type="datetime-local" name="observed_at"
                                 :value="old('observed_at', $observation->observed_at?->format('Y-m-d\TH:i'))"/>
                    </x-field>
                </div>

                <x-field label="Anything done there and then" name="immediate_action">
                    <x-textarea name="immediate_action" rows="2">{{ old('immediate_action', $observation->immediate_action) }}</x-textarea>
                </x-field>

                <label class="flex items-start gap-3">
                    <input type="hidden" name="action_required" value="0">
                    <input type="checkbox" name="action_required" value="1" class="checkbox mt-0.5"
                           @checked(old('action_required', $observation->action_required))>
                    <span>
                        <span class="block text-sm font-medium text-[var(--text-strong)]">
                            Something needs doing about this
                        </span>
                        <span class="block text-xs text-[var(--text-muted)]">
                            Puts the card in the "action required" view. The action itself is raised
                            on the card's own page.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Who is reporting</h3>
            </div>
            <div class="space-y-5 p-5" x-data="{ anon: @js((bool) old('is_anonymous', $observation->is_anonymous)) }">
                <label class="flex items-start gap-3">
                    <input type="hidden" name="is_anonymous" value="0">
                    <input type="checkbox" name="is_anonymous" value="1" class="checkbox mt-0.5"
                           x-model="anon">
                    <span>
                        <span class="block text-sm font-medium text-[var(--text-strong)]">
                            Report this anonymously
                        </span>
                        <span class="block text-xs text-[var(--text-muted)]">
                            Nothing linking the card to you is stored — not hidden, not stored. The
                            cost is that nobody can come back to you with a question, and the card
                            will not appear in your own list.
                        </span>
                    </span>
                </label>

                <div x-show="! anon" x-cloak>
                    <x-field label="Name to show" name="observer_name"
                             help="Leave blank to use your own account name.">
                        <x-input name="observer_name"
                                 :value="old('observer_name', $observation->observer_name)"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it sits</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $observation->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $observation->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $observation->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="Usually internal. An observation the site cannot read teaches the site nothing.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $observation->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
