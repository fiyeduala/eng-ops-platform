<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\OptionList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules behind the form builder (§73).
 *
 * ONE SENTENCE HOLDS THIS WHOLE CLASS TOGETHER: a submission binds to a VERSION, so a published
 * version is a promise about what was asked. Everything below is either keeping that promise or
 * deciding, once, where the promise stops.
 *
 * THE THREE STATES, AND WHY THERE ARE THREE. A draft is being written and nobody can fill it in. A
 * published version is the one people are served, and there is at most one per form. A retired
 * version is one people WERE served — it is not deleted, because its submissions still have to be
 * readable against the questions they answered. Without the third state a company could only keep
 * history by keeping obsolete forms on the menu.
 *
 * WHAT MAY CHANGE IN PLACE, AND WHAT FORCES A NEW VERSION. This is the decision people will argue
 * with, so it is stated plainly:
 *
 *   WORDING may be corrected on a published version — a field's label, help text and placeholder,
 *   a section's title and description, the form's own name and description. Nothing about what is
 *   asked changes; only how it reads.
 *
 *   MEANING may not. Adding, removing or reordering a question, changing its key, its type, its
 *   option list, its default, whether it is required, or when it appears — all of it opens a new
 *   draft instead.
 *
 * The line is drawn there rather than at "published versions are frozen, full stop" because the
 * strict rule is the one that fails in practice: a company that must publish v4 to fix a spelling
 * mistake will do exactly that, and then its inspection data is spread over four versions for no
 * reason anybody can reconstruct. Fragmented reporting is a worse and quieter failure than a
 * reworded label.
 *
 * THE COST OF THAT CHOICE IS REAL AND IS NOT HIDDEN. "Scaffold tagged?" reworded to "Scaffold
 * untagged?" inverts every recorded answer, and a wording-only edit cannot tell that apart from
 * fixing a typo. So every wording change is recorded with its before and after (§59), and a person
 * reading March's submission can see the question was reworded and what it used to say. The
 * platform does not pretend to judge which rewordings are innocent; it makes sure they are visible.
 *
 * NOTHING HERE ASKS WHETHER THE ACTOR MAY DO IT. Policies answer that, at the controller. This
 * class answers whether the DEFINITION allows it — and it answers the same way for a controller, a
 * console command or an importer, which is the point of it being here at all.
 */
final class FormDefinitionService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ActivityRecorder $activity,
    ) {}

    // ================================================================== the form

    /**
     * A new form, and the empty first draft to build it in.
     *
     * The draft is created here rather than on first edit because a form with no version is a
     * state nothing else in this class knows how to handle — and it is the state a builder screen
     * would land in the moment somebody pressed Save and then navigated away.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function createForm(array $attributes, User $actor): Form
    {
        $name = $this->requireText($attributes['name'] ?? null, 'A form needs a name.');
        $key = $this->normaliseKey((string) ($attributes['key'] ?? ''), $name);

        $this->assertKeyIsFree($key, null);

        return DB::transaction(function () use ($attributes, $name, $key, $actor) {
            $form = Form::create([
                // §74. Never from input.
                'organization_id' => $this->context->idOrFail(),
                'name' => $name,
                'key' => $key,
                'description' => $this->textOrNull($attributes['description'] ?? null),
                'category' => $this->textOrNull($attributes['category'] ?? null),
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ]);

            $version = $this->newVersion($form, 1);

            // One section to put the first question in. A builder that opens with nowhere to type
            // makes the person's first act an administrative one about a concept — "sections" —
            // they have not been introduced to yet.
            $this->rawSection($version, 'Details', null, 0);

            $this->activity->record($form, 'created',
                $actor->name.' created the "'.$name.'" form.', [], $actor);

            return $form;
        });
    }

    /**
     * Rename, re-describe, re-file or switch off a form.
     *
     * THE KEY IS FROZEN ONCE ANYBODY HAS FILLED THIS FORM IN, for the reason every key in this
     * build is frozen: it is what exports, reports and anything naming this form from outside call
     * it, and changing it renames that column under whoever is already reading it.
     *
     * `is_active` IS NOT A DELETE. A form switched off stops being offered and keeps every
     * submission ever made against it.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function updateForm(Form $form, array $attributes, User $actor): Form
    {
        $name = $this->requireText($attributes['name'] ?? null, 'A form needs a name.');

        $key = array_key_exists('key', $attributes) && trim((string) $attributes['key']) !== ''
            ? $this->normaliseKey((string) $attributes['key'], $name)
            : $form->key;

        if ($key !== $form->key) {
            $this->assertKeyMayChange($form);
            $this->assertKeyIsFree($key, $form);
        }

        return DB::transaction(function () use ($form, $attributes, $name, $key, $actor) {
            $was = $form->name;

            $form->fill([
                'name' => $name,
                'key' => $key,
                'description' => $this->textOrNull($attributes['description'] ?? null),
                'category' => $this->textOrNull($attributes['category'] ?? null),
                'is_active' => array_key_exists('is_active', $attributes)
                    ? (bool) $attributes['is_active']
                    : (bool) $form->is_active,
            ])->save();

            $this->activity->record($form, 'updated',
                $was === $name
                    ? $actor->name.' updated the "'.$name.'" form.'
                    : $actor->name.' renamed "'.$was.'" to "'.$name.'".',
                $was === $name ? [] : ['was' => $was, 'now' => $name], $actor);

            return $form;
        });
    }

    /**
     * Remove a form outright.
     *
     * REFUSED ONCE ANYBODY HAS FILLED IT IN, and this is the one place in the configuration screens
     * where deletion is refused rather than confirmed. A custom field's answers are a column beside
     * a record that survives without them; a form's submissions ARE the records — an inspection
     * sheet, a permit, a handover — and deleting the form would take the evidence with it. The
     * reversible door is switching the form off, and the screen offers that first.
     */
    public function deleteForm(Form $form, User $actor): void
    {
        $submissions = $this->submissionCount($form);

        if ($submissions > 0) {
            throw new \DomainException(
                '"'.$form->name.'" has been filled in '.$submissions.' '
                .Str::plural('time', $submissions).'. Deleting the form would take those '
                .Str::plural('record', $submissions).' with it — and they are the record of what '
                .'was inspected, signed or agreed. Switch the form off instead: it stops being '
                .'offered and everything already submitted stays readable.'
            );
        }

        DB::transaction(function () use ($form, $actor) {
            $this->activity->record($form, 'deleted',
                $actor->name.' deleted the "'.$form->name.'" form.', [], $actor);

            $form->delete();
        });
    }

    // ================================================================== versions

    /**
     * Get a draft to work in — the open one, or a copy of what is live.
     *
     * TWO OPEN DRAFTS IS THE FAILURE THIS PREVENTS. If this started a fresh draft every time
     * somebody opened the builder, two people editing the same form on the same morning would each
     * have their own, and whoever published second would silently discard the other's work without
     * either of them seeing a conflict. So an existing draft is handed back rather than replaced.
     *
     * The copy is DEEP — sections, fields and the columns inside repeating tables — because a
     * version that shared rows with its predecessor would not be a separate edition at all, and
     * editing v2 would rewrite what v1's submissions were asked.
     */
    public function openDraft(Form $form, User $actor): FormVersion
    {
        $existing = $form->versions()->draft()->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($form, $actor) {
            $source = $form->versions()->published()->first();

            $next = (int) $form->versions()->max('version') + 1;
            $draft = $this->newVersion($form, $next);

            if ($source === null) {
                $this->rawSection($draft, 'Details', null, 0);
            } else {
                $this->copyInto($source, $draft);
            }

            $this->activity->record($form, 'updated',
                $actor->name.' started version '.$next.' of "'.$form->name.'".',
                ['version' => $next, 'copied_from' => $source?->version], $actor);

            return $draft;
        });
    }

    /**
     * Throw a draft away.
     *
     * Only a draft, and the guard is not ceremony: a published or retired version has submissions
     * filed against it, and deleting it would leave answers pointing at questions that no longer
     * exist. `form_answers` would survive the cascade and mean nothing.
     */
    public function discardDraft(FormVersion $version, User $actor): void
    {
        if (! $version->isDraft()) {
            throw new \DomainException(
                'Version '.$version->version.' has been published, so it cannot be discarded. '
                .'Submissions are filed against it and are read through its questions.'
            );
        }

        DB::transaction(function () use ($version, $actor) {
            $form = $version->form;

            $this->activity->record($form, 'updated',
                $actor->name.' discarded the draft of version '.$version->version
                .' of "'.$form?->name.'".', ['version' => $version->version], $actor);

            $version->delete();
        });
    }

    /**
     * Make this draft the edition people fill in, and freeze it.
     *
     * RETIRING THE PREVIOUS VERSION HAPPENS IN THE SAME TRANSACTION, which is what makes "at most
     * one published version" true rather than merely intended. It is also why `forms` has no
     * `current_version_id`: a pointer and a state column are two facts that can disagree, and the
     * disagreement is invisible until somebody is served the wrong edition.
     *
     * The change note is REQUIRED after the first version. "What changed" is the only question a
     * person comparing two editions of an inspection sheet actually has, and it can be answered in
     * one sentence by the person making the change or reconstructed by nobody afterwards.
     */
    public function publish(FormVersion $version, User $actor, ?string $changeNote = null): FormVersion
    {
        if (! $version->isDraft()) {
            throw new \DomainException(
                'Version '.$version->version.' is already '.mb_strtolower($version->stateLabel()).'.'
            );
        }

        $this->assertPublishable($version);

        $note = $this->textOrNull($changeNote);

        if ($version->version > 1 && $note === null) {
            throw new \DomainException(
                'Say what changed in this version. Somebody comparing version '
                .($version->version - 1).' with version '.$version->version.' a year from now has '
                .'no other way of finding out, and the answers they are comparing depend on it.'
            );
        }

        return DB::transaction(function () use ($version, $note, $actor) {
            $form = $version->form;

            $previous = $form?->versions()->published()->first();

            if ($previous !== null) {
                $previous->forceFill(['state' => FormVersion::STATE_RETIRED])->save();
            }

            $version->forceFill([
                'state' => FormVersion::STATE_PUBLISHED,
                'published_at' => now(),
                'published_by' => $actor->getKey(),
                'change_note' => $note,
            ])->save();

            $this->activity->record($form ?? $version, 'updated',
                $actor->name.' published version '.$version->version.' of "'.$form?->name.'".',
                ['version' => $version->version, 'retired' => $previous?->version, 'note' => $note],
                $actor);

            return $version;
        });
    }

    /**
     * Take a form out of service without deleting anything.
     *
     * The published version is retired and the form switched off, so nothing new can be submitted
     * and everything already submitted still reads against the questions it answered.
     */
    public function retire(Form $form, User $actor): void
    {
        DB::transaction(function () use ($form, $actor) {
            $live = $form->versions()->published()->first();

            if ($live !== null) {
                $live->forceFill(['state' => FormVersion::STATE_RETIRED])->save();
            }

            $form->forceFill(['is_active' => false])->save();

            $this->activity->record($form, 'updated',
                $actor->name.' took "'.$form->name.'" out of service.',
                ['version' => $live?->version], $actor);
        });
    }

    // ================================================================== sections

    /** @param array<string,mixed> $attributes */
    public function addSection(FormVersion $version, array $attributes, User $actor): FormSection
    {
        $this->assertStructuralEditsAllowed($version);

        $title = $this->requireText($attributes['title'] ?? null, 'A section needs a heading.');

        return DB::transaction(function () use ($version, $attributes, $title, $actor) {
            $section = $this->rawSection(
                $version, $title,
                $this->textOrNull($attributes['description'] ?? null),
                (int) $version->sections()->max('sort') + 1,
            );

            $this->activity->record($version->form ?? $version, 'updated',
                $actor->name.' added the "'.$title.'" section.', [], $actor);

            return $section;
        });
    }

    /**
     * Retitle a section.
     *
     * WORDING ONLY, so this is allowed on a published version — see the class docblock. A section
     * has nothing but wording on it: its title and description are read by the person filling the
     * form in and stored by nothing.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function updateSection(FormSection $section, array $attributes, User $actor): FormSection
    {
        $title = $this->requireText($attributes['title'] ?? null, 'A section needs a heading.');

        return DB::transaction(function () use ($section, $attributes, $title, $actor) {
            $was = $section->title;

            $section->fill([
                'title' => $title,
                'description' => $this->textOrNull($attributes['description'] ?? null),
            ])->save();

            $this->recordWording($section->version, $actor,
                $was === $title
                    ? $actor->name.' reworded the "'.$title.'" section.'
                    : $actor->name.' retitled the "'.$was.'" section to "'.$title.'".',
                ['was' => $was, 'now' => $title]);

            return $section;
        });
    }

    /**
     * Remove a section and every question in it.
     *
     * Structural, so a published version refuses. The last section is refused too: a version with
     * nowhere to put a question is a version the builder cannot show anything in.
     */
    public function deleteSection(FormSection $section, User $actor): void
    {
        $version = $this->versionOf($section);

        $this->assertStructuralEditsAllowed($version);

        if ($version !== null && $version->sections()->count() <= 1) {
            throw new \DomainException(
                'A form needs at least one section. Rename this one rather than removing it.'
            );
        }

        DB::transaction(function () use ($section, $version, $actor) {
            $this->activity->record($version?->form ?? $section, 'updated',
                $actor->name.' removed the "'.$section->title.'" section and its questions.',
                ['fields' => $section->fields()->count()], $actor);

            $section->delete();
        });
    }

    /**
     * Put a version's sections in the order they should be filled in.
     *
     * Ids are trusted for ORDER ONLY; each is matched against this version's own sections, so an
     * id from another form — or another company — moves nothing (§75, §84).
     *
     * @param  array<int,int|string>  $orderedIds
     */
    public function reorderSections(FormVersion $version, array $orderedIds, User $actor): void
    {
        $this->assertStructuralEditsAllowed($version);

        $sections = $version->sections()->get()
            ->keyBy(fn (FormSection $section) => (int) $section->getKey());

        DB::transaction(function () use ($sections, $orderedIds, $version, $actor) {
            $sort = 0;

            foreach ($orderedIds as $id) {
                $sections->get((int) $id)?->forceFill(['sort' => $sort++])->save();
            }

            $this->activity->record($version->form ?? $version, 'updated',
                $actor->name.' reordered the sections.', [], $actor);
        });
    }

    // ================================================================== fields

    /**
     * Add a question.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function addField(FormSection $section, array $attributes, User $actor): FormField
    {
        $version = $this->versionOf($section);

        $this->assertStructuralEditsAllowed($version);

        $label = $this->requireText($attributes['label'] ?? null,
            'A question needs a label — the words that appear above the box.');

        $type = $this->assertKnownType((string) ($attributes['type'] ?? ''));
        $key = $this->normaliseKey((string) ($attributes['key'] ?? ''), $label);

        $this->assertKeyIsFreeOnVersion($version, $key, null);

        $parent = $this->resolveParent($version, $attributes['parent_field_id'] ?? null);

        $this->assertTypeFitsItsPlace($type, $parent);

        $optionListKey = $this->assertChoiceHasAList($type, $attributes['option_list_key'] ?? null);

        return DB::transaction(function () use (
            $version, $section, $attributes, $label, $key, $type, $optionListKey, $parent, $actor
        ) {
            $siblings = FormField::query()
                ->where('form_version_id', $version->getKey())
                ->when($parent === null,
                    fn ($query) => $query->whereNull('parent_field_id')->where('form_section_id', $section->getKey()),
                    fn ($query) => $query->where('parent_field_id', $parent->getKey()));

            $field = FormField::create([
                // §74. Never from input.
                'organization_id' => $this->context->idOrFail(),
                'form_version_id' => $version->getKey(),
                'form_section_id' => $section->getKey(),
                'parent_field_id' => $parent?->getKey(),
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'option_list_key' => $optionListKey,
                'help' => $this->textOrNull($attributes['help'] ?? null),
                'placeholder' => $this->textOrNull($attributes['placeholder'] ?? null),
                'default_value' => $this->defaultFor($type, $attributes['default_value'] ?? null),
                'is_required' => in_array($type, FormField::STATIC_TYPES, true)
                    ? false
                    : (bool) ($attributes['is_required'] ?? false),
                'config' => $this->configFor($type, $attributes['config'] ?? null),
                'applies_when_key' => $this->textOrNull($attributes['applies_when_key'] ?? null),
                'applies_when_value' => $this->textOrNull($attributes['applies_when_value'] ?? null),
                'sort' => (int) $siblings->max('sort') + 1,
            ]);

            $this->activity->record($version->form ?? $version, 'updated',
                $actor->name.' added the question "'.$label.'".', ['key' => $key], $actor);

            return $field;
        });
    }

    /**
     * Change a question.
     *
     * THE ONE METHOD WHERE THE WORDING/MEANING LINE IS ACTUALLY DRAWN. On a draft everything is
     * open. On a published version only the label, the help text and the placeholder move, and any
     * attempt at the rest is refused with the reason — because the person making it is looking at
     * a screen, not at the eleven months of submissions the change would reach back into.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function updateField(FormField $field, array $attributes, User $actor): FormField
    {
        $version = $this->versionOf($field);

        $label = $this->requireText($attributes['label'] ?? null,
            'A question needs a label — the words that appear above the box.');

        $structural = $version !== null && ! $version->isEditable();

        if ($structural) {
            $this->assertOnlyWordingChanges($field, $attributes);

            return DB::transaction(function () use ($field, $version, $attributes, $label, $actor) {
                $was = $field->label;

                $field->fill([
                    'label' => $label,
                    'help' => $this->textOrNull($attributes['help'] ?? null),
                    'placeholder' => $this->textOrNull($attributes['placeholder'] ?? null),
                ])->save();

                // The before and after, always, even when the two are the same: a reader of an
                // earlier submission is entitled to know the question was touched (§59).
                $this->recordWording($version, $actor,
                    $actor->name.' reworded "'.$was.'" to "'.$label.'" on a published version.',
                    ['key' => $field->key, 'was' => $was, 'now' => $label]);

                return $field;
            });
        }

        $type = array_key_exists('type', $attributes) && trim((string) $attributes['type']) !== ''
            ? $this->assertKnownType((string) $attributes['type'])
            : $field->type;

        $key = array_key_exists('key', $attributes) && trim((string) $attributes['key']) !== ''
            ? $this->normaliseKey((string) $attributes['key'], $label)
            : $field->key;

        if ($key !== $field->key) {
            $this->assertKeyIsFreeOnVersion($version, $key, $field);
        }

        $this->assertTypeFitsItsPlace($type, $field->parent);

        $optionListKey = $this->assertChoiceHasAList(
            $type, $attributes['option_list_key'] ?? $field->option_list_key
        );

        return DB::transaction(function () use (
            $field, $version, $attributes, $label, $key, $type, $optionListKey, $actor
        ) {
            $field->fill([
                'label' => $label,
                'key' => $key,
                'type' => $type,
                'option_list_key' => $optionListKey,
                'help' => $this->textOrNull($attributes['help'] ?? null),
                'placeholder' => $this->textOrNull($attributes['placeholder'] ?? null),
                'default_value' => $this->defaultFor($type, $attributes['default_value'] ?? null),
                'is_required' => in_array($type, FormField::STATIC_TYPES, true)
                    ? false
                    : (bool) ($attributes['is_required'] ?? false),
                'config' => $this->configFor($type, $attributes['config'] ?? null),
                'applies_when_key' => $this->textOrNull($attributes['applies_when_key'] ?? null),
                'applies_when_value' => $this->textOrNull($attributes['applies_when_value'] ?? null),
            ])->save();

            $this->activity->record($version?->form ?? $field, 'updated',
                $actor->name.' changed the question "'.$label.'".', ['key' => $key], $actor);

            return $field;
        });
    }

    /** Remove a question, and the columns under it when it is a repeating table. */
    public function deleteField(FormField $field, User $actor): void
    {
        $version = $this->versionOf($field);

        $this->assertStructuralEditsAllowed($version);

        DB::transaction(function () use ($field, $version, $actor) {
            $this->activity->record($version?->form ?? $field, 'updated',
                $actor->name.' removed the question "'.$field->label.'".',
                ['key' => $field->key], $actor);

            $field->delete();
        });
    }

    /**
     * Put the questions in the order they should be asked.
     *
     * Scoped to one section — or to one repeating table's columns, when a parent is given — and
     * matched against that container's own fields, so an id from anywhere else moves nothing
     * (§75, §84).
     *
     * @param  array<int,int|string>  $orderedIds
     */
    public function reorderFields(FormSection $section, array $orderedIds, User $actor, ?FormField $parent = null): void
    {
        $version = $this->versionOf($section);

        $this->assertStructuralEditsAllowed($version);

        $fields = FormField::query()
            ->where('form_version_id', $version?->getKey())
            ->when($parent === null,
                fn ($query) => $query->where('form_section_id', $section->getKey())->whereNull('parent_field_id'),
                fn ($query) => $query->where('parent_field_id', $parent->getKey()))
            ->get()
            ->keyBy(fn (FormField $field) => (int) $field->getKey());

        DB::transaction(function () use ($fields, $orderedIds, $version, $section, $actor) {
            $sort = 0;

            foreach ($orderedIds as $id) {
                $fields->get((int) $id)?->forceFill(['sort' => $sort++])->save();
            }

            $this->activity->record($version?->form ?? $section, 'updated',
                $actor->name.' reordered the questions.', [], $actor);
        });
    }

    // ================================================================== reading

    /**
     * The option lists a choice question may point at.
     *
     * @return array<string,string> key => name
     */
    public function optionLists(): array
    {
        return OptionList::query()->where('is_active', true)->orderBy('name')
            ->pluck('name', 'key')->all();
    }

    /** How many times this form has been filled in, across every version of it. */
    public function submissionCount(Form $form): int
    {
        return FormSubmission::query()->where('form_id', $form->getKey())->count();
    }

    // ================================================================== guards

    /**
     * The one question every structural write asks.
     *
     * Expressed once so that a new editing path — a console command, an importer, next year's API
     * — cannot answer it differently by forgetting to ask.
     */
    /**
     * The version a section or a question belongs to, read fresh.
     *
     * NOT `$section->version`, and the difference is a real defect rather than a style preference.
     * A model instance caches its relations, so a section object that was handed to this service
     * before the form was published still carries the draft it loaded then — and the guard below,
     * asked about that stale copy, would wave through a structural edit to a live edition. Any read
     * that decides whether a write is allowed has to come from the database, not from whatever the
     * caller happens to be holding (§84).
     */
    private function versionOf(FormSection|FormField $owner): ?FormVersion
    {
        return FormVersion::query()->whereKey($owner->form_version_id)->first();
    }

    private function assertStructuralEditsAllowed(?FormVersion $version): void
    {
        if ($version === null) {
            throw new \DomainException('That question does not belong to a version of a form.');
        }

        if ($version->isEditable()) {
            return;
        }

        throw new \DomainException(
            'Version '.$version->version.' has been published, and people have been answering it. '
            .'Changing the questions now would change what their answers were answers to. Start a '
            .'new version instead — everything already submitted keeps the questions it was asked.'
        );
    }

    /**
     * On a published version, only the wording moves.
     *
     * Each refusal names the specific thing that was attempted rather than a flat "not allowed",
     * because the administrator is about to decide whether to publish a new version over it and
     * needs to know which of the eight fields on the form was the problem.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function assertOnlyWordingChanges(FormField $field, array $attributes): void
    {
        $refusals = [
            'key' => 'its key',
            'type' => 'the kind of question it is',
            'option_list_key' => 'the list it draws its answers from',
            'default_value' => 'its default answer',
            'applies_when_key' => 'when it appears',
            'applies_when_value' => 'when it appears',
        ];

        foreach ($refusals as $attribute => $description) {
            if (! array_key_exists($attribute, $attributes)) {
                continue;
            }

            $given = $this->textOrNull($attributes[$attribute]);

            if ($attribute === 'key' && $given !== null) {
                $given = $this->normaliseKey((string) $given, $field->label);
            }

            if ($given === $this->textOrNull($field->{$attribute})) {
                continue;
            }

            throw new \DomainException(
                'Version '.$field->version?->version.' is published, so "'.$field->label.'" can be '
                .'reworded but '.$description.' cannot change — that would change what the people '
                .'who have already answered it were asked. Start a new version.'
            );
        }

        if (array_key_exists('is_required', $attributes)
            && (bool) $attributes['is_required'] !== (bool) $field->is_required) {
            throw new \DomainException(
                'Version '.$field->version?->version.' is published, so whether "'.$field->label
                .'" must be answered cannot change now. Somebody is part-way through filling this '
                .'form in on a tablet, and the rule they started under is the one they should '
                .'finish under. Start a new version.'
            );
        }
    }

    /**
     * Whether this draft is fit to be the thing people fill in.
     *
     * Two refusals, both of which produce a form that cannot be submitted at all if they get past
     * here — which is the worst moment for them to surface, because by then it is a site supervisor
     * standing at a scaffold rather than an administrator at a desk.
     */
    private function assertPublishable(FormVersion $version): void
    {
        $fields = $version->fields()->get();

        $answering = $fields->reject(
            fn (FormField $field) => in_array($field->type, FormField::STATIC_TYPES, true)
        );

        if ($answering->isEmpty()) {
            throw new \DomainException(
                'This version asks nothing. Add at least one question people can answer before '
                .'publishing it.'
            );
        }

        $lists = OptionList::query()->where('is_active', true)->pluck('key')->all();

        foreach ($fields as $field) {
            if (! in_array($field->type, FormField::CHOICE_TYPES, true)) {
                continue;
            }

            if (! in_array((string) $field->option_list_key, $lists, true)) {
                throw new \DomainException(
                    '"'.$field->label.'" asks people to choose from a list called "'
                    .$field->option_list_key.'", and there is no such list. Publishing this would '
                    .'put a question on the form with no answer anybody could give.'
                );
            }
        }

        // AN EMPTY SECTION IS DELIBERATELY NOT REFUSED. It renders as a heading with nothing under
        // it, which is untidy and visible to the person who built it — and a publish blocked over
        // tidiness is a publish somebody works around by deleting the section they were about to
        // fill. The two refusals above are both "this form cannot be submitted at all", which is
        // the only class of problem worth stopping a build for.
    }

    private function assertKnownType(string $type): string
    {
        if (! array_key_exists($type, FormField::TYPES)) {
            throw new \DomainException('That is not a kind of question this platform can ask.');
        }

        return $type;
    }

    /**
     * A repeating table cannot contain a repeating table, or a signature.
     *
     * Not an arbitrary restriction: a table's answers are rows against one `row_index`, and a table
     * inside a table would need a second index the storage does not have. A signature block inside
     * a table would mean one signature per row, which is not what a signature means — it is one
     * person putting their name to the whole sheet.
     */
    private function assertTypeFitsItsPlace(string $type, ?FormField $parent): void
    {
        if ($parent === null) {
            return;
        }

        if (! $parent->isTable()) {
            throw new \DomainException('Only a repeating table can have columns inside it.');
        }

        if (in_array($type, FormField::NOT_IN_TABLE, true)) {
            throw new \DomainException(
                FormField::TYPES[$type].' cannot be a column inside a repeating table.'
            );
        }
    }

    private function assertChoiceHasAList(string $type, mixed $given): ?string
    {
        if (! in_array($type, FormField::CHOICE_TYPES, true)) {
            return null;
        }

        $key = is_string($given) ? trim($given) : '';

        if ($key === '') {
            throw new \DomainException(
                'A question people choose from needs a list to choose from. Pick one, or create it '
                .'on the Option lists screen first.'
            );
        }

        if (! OptionList::query()->forKey($key)->where('is_active', true)->exists()) {
            throw new \DomainException(
                'There is no option list called "'.$key.'". A question pointing at a list that '
                .'does not exist is a question with no permitted answer.'
            );
        }

        return $key;
    }

    private function assertKeyIsFree(string $key, ?Form $except): void
    {
        if ($key === '') {
            throw new \DomainException('A form needs a key — a short name without spaces, used in exports.');
        }

        $exists = Form::query()
            ->where('key', $key)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw new \DomainException('There is already a form with the key "'.$key.'".');
        }
    }

    private function assertKeyIsFreeOnVersion(?FormVersion $version, string $key, ?FormField $except): void
    {
        if ($key === '') {
            throw new \DomainException('A question needs a key — a short name without spaces, used in exports.');
        }

        $exists = FormField::query()
            ->where('form_version_id', $version?->getKey())
            ->where('key', $key)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw new \DomainException(
                'This form already has a question with the key "'.$key.'". Keys are what exports '
                .'and reports name each answer by, so two questions cannot share one.'
            );
        }
    }

    private function assertKeyMayChange(Form $form): void
    {
        $submissions = $this->submissionCount($form);

        if ($submissions === 0) {
            return;
        }

        throw new \DomainException(
            '"'.$form->name.'" has been filled in '.$submissions.' '
            .Str::plural('time', $submissions).'. The key is what exports, reports and anything '
            .'reading this form by name call it; changing it now would rename it under everything '
            .'already using it. The name is yours to change freely — that is what people read.'
        );
    }

    // ================================================================== building

    private function newVersion(Form $form, int $number): FormVersion
    {
        return FormVersion::create([
            // §74. Never from input.
            'organization_id' => $this->context->idOrFail(),
            'form_id' => $form->getKey(),
            'version' => $number,
            'state' => FormVersion::STATE_DRAFT,
        ]);
    }

    private function rawSection(FormVersion $version, string $title, ?string $description, int $sort): FormSection
    {
        return FormSection::create([
            'organization_id' => $this->context->idOrFail(),
            'form_version_id' => $version->getKey(),
            'title' => $title,
            'description' => $description,
            'sort' => $sort,
        ]);
    }

    /**
     * Copy a version's questions into a new draft.
     *
     * Two passes over the fields, and the second one is why: a column knows its repeating table by
     * id, and the new table's id does not exist until the first pass has made it. Parents are
     * copied first, then children against a map from old id to new — which is also what stops a
     * column being re-parented onto the OLD version's table and quietly editing a published
     * edition through the back door.
     */
    private function copyInto(FormVersion $source, FormVersion $draft): void
    {
        $organizationId = $this->context->idOrFail();
        $sectionMap = [];

        foreach ($source->sections()->orderBy('sort')->orderBy('id')->get() as $section) {
            $sectionMap[(int) $section->getKey()] = (int) FormSection::create([
                'organization_id' => $organizationId,
                'form_version_id' => $draft->getKey(),
                'title' => $section->title,
                'description' => $section->description,
                'sort' => $section->sort,
            ])->getKey();
        }

        $fields = $source->fields()->orderBy('sort')->orderBy('id')->get();
        $fieldMap = [];

        foreach ([null, 'children'] as $pass) {
            foreach ($fields as $field) {
                $isChild = $field->parent_field_id !== null;

                if (($pass === null) === $isChild) {
                    continue;
                }

                $fieldMap[(int) $field->getKey()] = (int) FormField::create([
                    'organization_id' => $organizationId,
                    'form_version_id' => $draft->getKey(),
                    'form_section_id' => $sectionMap[(int) $field->form_section_id] ?? null,
                    'parent_field_id' => $isChild
                        ? ($fieldMap[(int) $field->parent_field_id] ?? null)
                        : null,
                    'key' => $field->key,
                    'label' => $field->label,
                    'type' => $field->type,
                    'option_list_key' => $field->option_list_key,
                    'help' => $field->help,
                    'placeholder' => $field->placeholder,
                    'default_value' => $field->default_value,
                    'is_required' => $field->is_required,
                    'config' => $field->config,
                    'applies_when_key' => $field->applies_when_key,
                    'applies_when_value' => $field->applies_when_value,
                    'sort' => $field->sort,
                ])->getKey();
            }
        }
    }

    // ================================================================== helpers

    /** @param array<string,mixed> $context */
    private function recordWording(?FormVersion $version, User $actor, string $sentence, array $context): void
    {
        $subject = $version?->form ?? $version;

        if ($subject === null) {
            return;
        }

        $this->activity->record($subject, 'updated', $sentence, $context, $actor);
    }

    private function resolveParent(?FormVersion $version, mixed $given): ?FormField
    {
        if (blank($given)) {
            return null;
        }

        // Matched against THIS version's own fields. §84: the id came from a form, which is not
        // evidence that it belongs to the version being edited — or to this company.
        return FormField::query()
            ->where('form_version_id', $version?->getKey())
            ->whereKey($given)
            ->first();
    }

    private function requireText(mixed $given, string $message): string
    {
        $value = trim((string) ($given ?? ''));

        if ($value === '') {
            throw new \DomainException($message);
        }

        return $value;
    }

    private function textOrNull(mixed $given): ?string
    {
        if ($given === null) {
            return null;
        }

        return trim((string) $given) ?: null;
    }

    private function normaliseKey(string $given, string $fallback): string
    {
        $source = trim($given) !== '' ? $given : $fallback;

        return Str::of($source)->snake()->replace('-', '_')
            ->replaceMatches('/[^a-z0-9_]/', '')->limit(64, '')->trim('_')->toString();
    }

    private function defaultFor(string $type, mixed $given): ?string
    {
        if ($given === null) {
            return null;
        }

        $value = is_array($given) ? implode(',', $given) : trim((string) $given);

        if ($value === '') {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
            'number' => is_numeric($value) ? (string) ($value + 0) : null,
            default => $value,
        };
    }

    /**
     * The per-type presentation settings.
     *
     * NOTHING SECURITY-BEARING GOES IN HERE, as the migration says, and the filtering below is what
     * keeps that true: a caller that posted `config[is_required]` or `config[organization_id]` gets
     * them dropped rather than stored, so a later reader of this JSON cannot be talked into
     * treating it as authority (§84).
     *
     * @return array<string,mixed>|null
     */
    private function configFor(string $type, mixed $given): ?array
    {
        if (! is_array($given)) {
            return null;
        }

        $allowed = match ($type) {
            'number' => ['min', 'max', 'step', 'suffix'],
            'text', 'textarea' => ['max_length', 'rows'],
            'date' => ['min', 'max'],
            'table' => ['min_rows', 'max_rows', 'add_label'],
            'note' => ['body'],
            'signature' => ['designation_hint'],
            default => [],
        };

        $config = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $given) && $given[$key] !== null && $given[$key] !== '') {
                $config[$key] = is_scalar($given[$key]) ? $given[$key] : null;
            }
        }

        return $config === [] ? null : $config;
    }
}
