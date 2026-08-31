<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Template;
use App\Models\User;

/**
 * Document templates (§65, §66).
 *
 * Two permissions: `template.view` for everyone who writes documents, `template.manage` for the few
 * who decide what the company's letterhead looks like. Anyone drafting a procedure needs to pick a
 * template; almost nobody needs to change one.
 *
 * WHY `manage` RATHER THAN THE USUAL CREATE/EDIT/DELETE SPLIT: a template body is rendered into
 * every document written from it, and its header appears on every page of the resulting PDF. There
 * is no useful role that may edit a letterhead but not create one. Collapsing the three actions to
 * one keeps the role editor honest about what is actually being granted — authority over how the
 * organization's documents look to the outside world.
 *
 * The content itself is sanitised by TemplateService on the way in, so this permission grants
 * authorship, never the ability to inject markup that runs for other readers (§84).
 */
class TemplatePolicy extends ScopedPolicy
{
    protected string $module = 'template';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'delete_permanently',
    ];

    /**
     * Copying a template is creating one, not editing the original.
     *
     * Worth stating because the obvious reading — "they can already see it, let them copy it" —
     * would hand template authorship to every document author in the company through the back door.
     */
    public function duplicate(User $user, Template $template): bool
    {
        return $this->check($user, 'view', $template) && $this->check($user, 'create');
    }

    /**
     * Using a template to start a document is a `view`, deliberately.
     *
     * Seeding a new document with a letterhead is reading the template, not changing it, and
     * requiring anything more would mean only administrators could write a letter.
     */
    public function use(User $user, Template $template): bool
    {
        return $template->is_active && $this->check($user, 'view', $template);
    }

    public function preview(User $user, Template $template): bool
    {
        return $this->check($user, 'view', $template);
    }
}
