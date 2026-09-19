<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Is this piece actually part of that form?"
 *
 * THE ONE QUESTION ROUTE MODEL BINDING DOES NOT ASK. Every builder URL names two things — the form
 * whose page you are on, and the section or question you are acting on — and the binder resolves
 * them independently. The policy is then asked about the FORM, so `PUT /forms/3/fields/91` passes
 * every gate if you may edit form 3, and rewrites question 91 wherever it actually lives.
 *
 * The organization scope means 91 cannot belong to another company (§75). It can very easily
 * belong to another FORM in your own, and that is the interesting case: the audit entry would name
 * form 3, the redirect would show form 3, and the question that changed would be on form 8 — a
 * change nobody can find afterwards because every record of it points at the wrong place (§84).
 *
 * A mismatch is a 404 rather than a 403, because from the caller's position the thing they named
 * does not exist: there is no question 91 on form 3. Answering 403 would confirm that 91 exists
 * somewhere, which is a disclosure paid for nothing (§61).
 */
trait BelongsToForm
{
    protected function assertVersionOf(Form $form, FormVersion $version): void
    {
        if ((int) $version->form_id !== (int) $form->getKey()) {
            throw new NotFoundHttpException;
        }
    }

    /** The version a section sits on, having first checked the section sits on this form. */
    protected function versionOfSection(Form $form, FormSection $section): FormVersion
    {
        $version = FormVersion::query()->whereKey($section->form_version_id)->first();

        if ($version === null) {
            throw new NotFoundHttpException;
        }

        $this->assertVersionOf($form, $version);

        return $version;
    }

    /** The section a question sits on, having first checked the question sits on this form. */
    protected function sectionOfField(Form $form, FormField $field): FormSection
    {
        $section = FormSection::query()->whereKey($field->form_section_id)->first();

        if ($section === null) {
            throw new NotFoundHttpException;
        }

        $this->versionOfSection($form, $section);

        return $section;
    }

    /**
     * Run a builder write and turn a refusal into a sentence on the page it came from.
     *
     * Without withInput(), and the reason is specific to this screen rather than a habit: the
     * builder carries an edit form for every section, an edit form for every question, and an add
     * form beside each — all sharing the names `label`, `key`, `type`. Repopulating from old()
     * would take the rejected question's text and paste it into all of them.
     *
     * @param  callable():string  $write
     */
    protected function attemptOnBuilder(Form $form, ?FormVersion $version, callable $write): RedirectResponse
    {
        try {
            $message = $write();
        } catch (\DomainException $e) {
            return back()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()
            ->route('forms.show', array_filter([
                'form' => $form->getKey(),
                'version' => $version?->getKey(),
            ]))
            ->with('status', $message);
    }
}
