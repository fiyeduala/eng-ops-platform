<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BelongsToForm;
use App\Http\Requests\FormSectionRequest;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Services\FormDefinitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The headings on a form, and the order they come in.
 *
 * NO VIEWS OF ITS OWN. Every action here is a write posted from the builder and answered with a
 * redirect back to it, because a section is not a thing anybody wants to look at on its own — it
 * only means something next to the questions under it and the sections either side.
 *
 * AUTHORIZED ON THE FORM, NOT ON THE SECTION, and that is the correct reading rather than a
 * shortcut: FormSection::accessParentRelation() returns 'version', which inherits from the form, so
 * asking about the section would resolve to the same answer by a longer route. Asking about the
 * form directly keeps one obvious subject in the audit trail and one obvious thing to reason about.
 *
 * RETITLING IS ALLOWED ON A PUBLISHED VERSION; adding, removing and reordering are not. That line
 * is drawn in FormDefinitionService, not here — a section carries only wording, so retitling one
 * changes nothing anybody has already answered, while moving one changes the shape of the edition
 * their answers were filed against.
 */
class FormSectionController extends Controller
{
    use BelongsToForm;

    public function __construct(
        private readonly FormDefinitionService $service,
    ) {}

    public function store(FormSectionRequest $request, Form $form, FormVersion $version): RedirectResponse
    {
        $this->authorize('update', $form);

        $this->assertVersionOf($form, $version);

        return $this->attemptOnBuilder($form, $version, fn () => '"'.$this->service
            ->addSection($version, $request->sectionAttributes(), $request->user())
            ->title.'" has been added.');
    }

    public function update(FormSectionRequest $request, Form $form, FormSection $section): RedirectResponse
    {
        $this->authorize('update', $form);

        $version = $this->versionOfSection($form, $section);

        return $this->attemptOnBuilder($form, $version, fn () => '"'.$this->service
            ->updateSection($section, $request->sectionAttributes(), $request->user())
            ->title.'" has been updated.');
    }

    public function destroy(Request $request, Form $form, FormSection $section): RedirectResponse
    {
        $this->authorize('update', $form);

        $version = $this->versionOfSection($form, $section);
        $title = $section->title;

        return $this->attemptOnBuilder($form, $version, function () use ($section, $request, $title) {
            $this->service->deleteSection($section, $request->user());

            return '"'.$title.'" and the questions in it have been removed from this draft.';
        });
    }

    /**
     * Put the sections in a different order.
     *
     * The ids arrive as a flat list in the order the screen wants them. Every one of them is
     * re-scoped to this version inside the service, so an id from another form — or a repeated id,
     * or one that does not exist — moves nothing rather than shuffling somebody else's page (§84).
     */
    public function reorder(Request $request, Form $form, FormVersion $version): RedirectResponse
    {
        $this->authorize('update', $form);

        $this->assertVersionOf($form, $version);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200'],
            'order.*' => ['integer'],
        ]);

        return $this->attemptOnBuilder($form, $version, function () use ($version, $validated, $request) {
            $this->service->reorderSections($version, $validated['order'], $request->user());

            return 'The order of the sections has been saved.';
        });
    }
}
