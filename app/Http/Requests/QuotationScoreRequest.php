<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * One evaluator's marks on one quotation.
 *
 * NOTE WHAT IS NOT HERE: whose marks these are. The evaluator is `$request->user()` and there is no
 * field, hidden or otherwise, that could say otherwise — QuotationService::recordScores() writes
 * only the actor's own rows and QuotationScorePolicy refuses to let anybody edit anybody else's,
 * including the buyer running the evaluation and the organization owner. An evaluation where a
 * manager can adjust a colleague's marks is not an evaluation (§84).
 *
 * THE CRITERIA ARE THE COMPANY'S. The keys are checked against the configured list inside the
 * service, and a key that is not on it is skipped rather than refused — an evaluation screen that
 * was open when an administrator edited the criteria should save the marks it can rather than
 * throwing the panel's work away.
 *
 * A BLANK MARK IS A LEGITIMATE ANSWER. "I am not qualified to judge the technical compliance of a
 * transformer" is worth recording as an absence rather than as a zero, and a zero would drag the
 * weighted average down as though the supplier had failed it.
 */
class QuotationScoreRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'scores' => ['required', 'array', 'max:50'],
            // Bounded here only as a sanity check; the real ceiling is the company's own scale,
            // read from `procurement.score_out_of` and clamped in the service.
            'scores.*.score' => ['nullable', 'numeric', 'between:0,100'],
            'scores.*.comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string,array{score:mixed,comment?:string|null}> */
    public function marks(): array
    {
        /** @var array<string,array{score:mixed,comment?:string|null}> $scores */
        $scores = $this->validated()['scores'] ?? [];

        return $scores;
    }
}
