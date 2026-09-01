<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TrainingCourse;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Define a course this company records certificates against.
 *
 * `validity_months` IS WHAT MAKES THE TRAINING REGISTER USEFUL. It is the figure
 * TrainingCourse::expiryFrom() turns a completion date into an expiry date with, and the expiry
 * date is the only reason anybody opens the register. Nullable, because plenty of training never
 * expires — an induction, a degree — and a course forced to claim an expiry it does not have would
 * fill the sixty-day report with noise until people stopped reading it.
 *
 * CHANGING IT DOES NOT MOVE EXISTING CERTIFICATES. The expiry is computed once, at completion, and
 * stored on the record; a company that shortens a course's validity from three years to two is
 * saying something about the courses it runs from now on, not re-dating the tickets its people are
 * already holding.
 *
 * `is_mandatory` IS A REPORTING FLAG, NOT A GATE. Nothing in this application refuses a person work
 * because they lack a mandatory course — that is a decision a supervisor makes with facts in front
 * of them, and a platform that blocked it would be worked around within a week.
 */
class TrainingCourseRequest extends TenantRequest
{
    public function rules(): array
    {
        $ignore = $this->route('training_course')?->getKey();

        return [
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                $this->uniqueInTenant('training_courses', 'code', $ignore),
            ],
            'description' => ['nullable', 'string', 'max:5000'],

            'category' => [
                'nullable',
                Rule::in(app(OptionList::class)->keys('training_course.categories', TrainingCourse::DEFAULT_CATEGORIES)),
            ],

            'validity_months' => ['nullable', 'integer', 'between:1,600'],
            'is_mandatory' => ['boolean'],

            'provider' => ['nullable', 'string', 'max:200'],
            'duration_hours' => ['nullable', 'numeric', 'between:0,9999'],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_mandatory', 'is_active']);

        $this->nullifyBlanks([
            'description', 'category', 'validity_months', 'provider', 'duration_hours', 'sort_order',
        ]);
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, numbers, hyphens and underscores only.',
        ];
    }

    /** @return array<string,mixed> */
    public function courseAttributes(): array
    {
        $data = $this->validated();
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
