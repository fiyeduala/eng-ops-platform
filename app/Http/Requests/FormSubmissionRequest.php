<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Engines\Configuration\FormCompiler;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The answers somebody typed into a form they were served.
 *
 * THE RULES ARE NOT IN THIS FILE, and that is the whole design. What a form accepts is decided by
 * the version the submission was started against — which questions it asks, what type each one is,
 * which option list it draws on — and none of that is knowable until the record is in hand. So this
 * class asks FormCompiler to compile the stored definition, on every request, and validates against
 * that. Nothing is read from the page that was posted: a hidden field claiming a question is
 * optional, or that a select accepts a value, changes nothing (§84).
 *
 * THE VERSION COMES FROM THE SUBMISSION, NEVER FROM THE ROUTE OR THE PAYLOAD. A `form_version_id`
 * in the body would let somebody validate March's answers against today's laxer questions, which is
 * the one move that would make versioning decorative.
 *
 * `required` IS NOT ENFORCED HERE, ON EITHER ROUTE, and that is not an oversight. A rule at this
 * boundary rejects the WHOLE payload, so a person pressing Submit with one box empty would lose the
 * other forty-nine answers they had just typed on a tablet in a yard. Completeness is judged after
 * the answers are safely written, by FormSubmissionService::submit(), which refuses the submission
 * and names what is missing while every keystroke stays where they put it. Type rules apply on both
 * routes: 'abc' must never reach a number column, draft or not.
 */
class FormSubmissionRequest extends FormRequest
{
    /**
     * Authorization is the controller's, through the policy. This class only shapes the payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        $version = $this->version();

        if ($version === null) {
            // Unreachable through the routes: the submission is route-bound and every submission
            // has a version, NOT NULL since the migration. Refusing the whole payload rather than
            // validating nothing is the safe failure if that ever stops being true.
            return [FormCompiler::INPUT_KEY => ['prohibited']];
        }

        return $this->compiler()->rules(
            $version,
            (array) $this->input(FormCompiler::INPUT_KEY, []),
        );
    }

    /**
     * Human names, so a failure reads "inspector name is required" rather than
     * "answers.inspector is required".
     *
     * @return array<string,string>
     */
    public function attributes(): array
    {
        $version = $this->version();

        return $version === null ? [] : $this->compiler()->attributeNames($version);
    }

    /**
     * The answers, after validation, exactly as the compiler expects them.
     *
     * @return array<string,mixed>
     */
    public function answers(): array
    {
        return (array) ($this->validated()[FormCompiler::INPUT_KEY] ?? []);
    }

    private function version(): ?FormVersion
    {
        $submission = $this->route('submission');

        if (! $submission instanceof FormSubmission) {
            return null;
        }

        // Loaded with its fields because the compiler is about to walk every one of them, and
        // lazy loading is disabled outside production (§89).
        $submission->loadMissing('version.fields');

        return $submission->version;
    }

    private function compiler(): FormCompiler
    {
        return app(FormCompiler::class);
    }
}
