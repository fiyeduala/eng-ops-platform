<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What somebody filled into a form.
 *
 * THIS IS A RECORD, NOT CONFIGURATION, and the difference is the reason it is a separate policy
 * from FormPolicy rather than a couple more rows in its ability map. A form definition is company
 * -wide reading; a submission carries a project and a department, and is therefore subject to the
 * full scope machinery — an inspection on the Bonny job is readable by the people whose reach
 * covers the Bonny job, and by nobody else (§6, §75).
 *
 * `submit` IS SEPARATE FROM `create`, and the distinction is not bureaucratic. Creating a draft is
 * starting to fill something in; submitting it is asserting that what it says is true — and on an
 * inspection sheet or a permit to work, that assertion is the entire product. Companies routinely
 * want a technician who records readings and a supervisor who signs the sheet off, and one
 * permission for both makes that arrangement unexpressible.
 *
 * A SUBMITTED SUBMISSION IS NOT EDITABLE — but that is not enforced here. Whether a record may
 * change is a question about the record's state, not about the person, and answering it in a policy
 * would mean every non-policy path (an importer, a workflow action, a future API) enforced it
 * separately or not at all. FormSubmissionService owns it.
 */
class FormSubmissionPolicy extends ScopedPolicy
{
    protected string $module = 'form';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'create',
        'update' => 'edit',
        'delete' => 'delete',
        'restore' => 'edit',
        'forceDelete' => 'delete_permanently',
    ];

    /** Assert that what this says is true. */
    public function submit(User $user, Model $record): bool
    {
        return $this->check($user, 'submit', $record);
    }

    /**
     * Accept it, reject it, or send it back.
     *
     * Separate from `submit` in the direction that matters: the person who fills a sheet in is
     * frequently not the person who decides it is satisfactory, and a company that wants the two
     * held apart — which is most of them, on anything that gets audited — needs two permissions to
     * say so. Whether a particular move is allowed FROM a particular status is the status graph's
     * question, not this one's; this answers only whether reviewing is something this person does.
     */
    public function review(User $user, Model $record): bool
    {
        return $this->check($user, 'review', $record);
    }

    /**
     * Sign a submission.
     *
     * The same authority as submitting it. A signature block on a form is the person putting their
     * name to the answers, which is what submitting already means — a third permission here would
     * be a door to the same room, and the kind of thing an administrator grants without reading.
     */
    public function sign(User $user, Model $record): bool
    {
        return $this->check($user, 'submit', $record);
    }
}
