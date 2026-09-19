<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BelongsToForm;
use App\Http\Requests\FormFieldRequest;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Services\FormDefinitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The questions themselves.
 *
 * THE MOST SECURITY-RELEVANT CONTROLLER IN THE FORM BUILDER, and not for the reason it looks. The
 * dangerous payload here is not a malicious one — it is `parent_field_id`, an ordinary number that
 * the screen posts whenever somebody adds a column to a repeating table. Taken at face value it
 * would let a column be hung off a table on a DIFFERENT version of the same form, which is how a
 * published edition grows a question after the fact. The service resolves it against the version
 * it is writing to and returns null for anything else; this controller's job is to make sure the
 * version it is writing to is the one on the page (§84).
 *
 * WHAT A PUBLISHED VERSION ACCEPTS is decided in FormDefinitionService and nowhere else. The
 * builder greys out the key, the type and the required box on a published edition, and the service
 * refuses them regardless — because the grey is CSS and a form post does not read CSS (§6).
 */
class FormFieldController extends Controller
{
    use BelongsToForm;

    public function __construct(
        private readonly FormDefinitionService $service,
    ) {}

    public function store(FormFieldRequest $request, Form $form, FormSection $section): RedirectResponse
    {
        $this->authorize('update', $form);

        $version = $this->versionOfSection($form, $section);

        return $this->attemptOnBuilder($form, $version, fn () => '"'.$this->service
            ->addField($section, $request->fieldAttributes(), $request->user())
            ->label.'" has been added.');
    }

    /**
     * Change a question.
     *
     * On a draft this is an ordinary edit. On a published edition it is a rewording and nothing
     * else, refused with its reason the moment the payload differs in key, type, option list,
     * default, condition or whether an answer is compulsory — and recorded with its before and
     * after either way, so a reader of March's submission can see the question was touched (§59).
     */
    public function update(FormFieldRequest $request, Form $form, FormField $field): RedirectResponse
    {
        $this->authorize('update', $form);

        $section = $this->sectionOfField($form, $field);
        $version = $this->versionOfSection($form, $section);

        return $this->attemptOnBuilder($form, $version, fn () => '"'.$this->service
            ->updateField($field, $request->fieldAttributes(), $request->user())
            ->label.'" has been updated.');
    }

    public function destroy(Request $request, Form $form, FormField $field): RedirectResponse
    {
        $this->authorize('update', $form);

        $section = $this->sectionOfField($form, $field);
        $version = $this->versionOfSection($form, $section);
        $label = $field->label;

        return $this->attemptOnBuilder($form, $version, function () use ($field, $request, $label) {
            $this->service->deleteField($field, $request->user());

            return '"'.$label.'" has been removed from this draft.';
        });
    }

    /**
     * Reorder the questions in a section, or the columns of one repeating table.
     *
     * `parent` decides which of the two, and is resolved through the service against the version
     * being written to. A parent that belongs elsewhere reorders the section's top-level questions
     * instead of somebody else's columns — the failure is inert rather than cross-wired.
     */
    public function reorder(Request $request, Form $form, FormSection $section): RedirectResponse
    {
        $this->authorize('update', $form);

        $version = $this->versionOfSection($form, $section);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200'],
            'order.*' => ['integer'],
            'parent' => ['nullable', 'integer'],
        ]);

        $parent = isset($validated['parent'])
            ? FormField::query()
                ->whereKey((int) $validated['parent'])
                ->where('form_version_id', $version->getKey())
                ->first()
            : null;

        return $this->attemptOnBuilder($form, $version, function () use ($section, $validated, $parent, $request) {
            $this->service->reorderFields($section, $validated['order'], $request->user(), $parent);

            return 'The order of the questions has been saved.';
        });
    }
}
