<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CustomFieldRequest;
use App\Models\CustomField;
use App\Services\CustomFieldService;
use App\Support\CustomFieldCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The custom-field builder — how a company records what nobody else does (§73).
 *
 * ONE PAGE PER REGISTER, because a field only means anything next to the other fields on the same
 * form: "do we already ask for a cost code?" is the first question anybody adding one has, and a
 * flat list of every field in the company answers it badly.
 *
 * TWO THINGS THIS CONTROLLER OWES THAT THE SERVICE CANNOT SEE. Authorization, as everywhere (§6).
 * And the fact that a path segment is not evidence: the register in the URL is checked against the
 * catalogue rather than trusted, and a field bound from the URL must belong to the register whose
 * page posted it — otherwise an id from Projects, submitted through the Incidents page, would be
 * rewritten while the audit entry named Incidents (§84).
 */
class CustomFieldController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', CustomField::class);

        return view('configuration.custom-fields.index', [
            'registers' => $this->service->registers(),
            'canManage' => $request->user()->can('create', CustomField::class),
        ]);
    }

    public function show(Request $request, string $register): View
    {
        $this->authorize('viewAny', CustomField::class);

        $this->assertKnownRegister($register);

        return view('configuration.custom-fields.show', [
            'register' => $register,
            'registerName' => CustomFieldCatalogue::label($register),
            'fields' => $this->service->fieldsFor($register),
            'answers' => $this->service->answerCounts($register),
            'optionLists' => $this->service->optionLists(),
            'types' => CustomField::TYPES,
            'canManage' => $request->user()->can('create', CustomField::class),
        ]);
    }

    public function store(CustomFieldRequest $request): RedirectResponse
    {
        $this->authorize('create', CustomField::class);

        $attributes = $request->fieldAttributes();

        return $this->attempt((string) $attributes['entity_type'], fn () => '"'.$this->service
            ->create($attributes, $request->user())
            ->label.'" has been added.');
    }

    /**
     * Amend a definition.
     *
     * `entity_type` arrives in the payload — the form posts it — and is deliberately never read
     * here or in the service. A field does not move between registers: its answers are filed
     * against records of one kind, and moving the definition would leave them describing records
     * of another (§84).
     */
    public function update(CustomFieldRequest $request, CustomField $customField): RedirectResponse
    {
        $this->authorize('update', $customField);

        return $this->attempt($customField->entity_type, fn () => '"'.$this->service
            ->update($customField, $request->fieldAttributes(), $request->user())
            ->label.'" has been updated.');
    }

    public function destroy(Request $request, CustomField $customField): RedirectResponse
    {
        // Authorized on `update`, mirroring the status and option-list editors: both map to
        // `custom_field.manage`, so the authority question is identical.
        $this->authorize('update', $customField);

        $register = $customField->entity_type;
        $label = $customField->label;

        return $this->attempt($register, function () use ($customField, $request, $label) {
            $this->service->delete($customField, $request->user());

            return '"'.$label.'" and every answer stored in it have been deleted.';
        });
    }

    public function reorder(Request $request, string $register): RedirectResponse
    {
        $this->authorize('create', CustomField::class);

        $this->assertKnownRegister($register);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:200'],
            'order.*' => ['integer'],
        ]);

        return $this->attempt($register, function () use ($register, $validated, $request) {
            $this->service->reorder($register, $validated['order'], $request->user());

            return 'The order has been saved.';
        });
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Run a write and turn a refusal into a sentence.
     *
     * Without withInput(), for the reason given in StatusConfigController: this page carries one
     * form per field plus an "add" form, all sharing field names, so repopulating from old() would
     * push the rejected row's text into every other row on the screen.
     *
     * @param  callable():string  $write
     */
    private function attempt(string $register, callable $write): RedirectResponse
    {
        try {
            $message = $write();
        } catch (\DomainException $e) {
            return back()->withErrors(['configuration' => $e->getMessage()]);
        }

        return redirect()->route('configuration.custom-fields.show', $register)->with('status', $message);
    }

    private function assertKnownRegister(string $register): void
    {
        if (! CustomFieldCatalogue::supports($register)) {
            throw new NotFoundHttpException;
        }
    }
}
