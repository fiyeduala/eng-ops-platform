<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Document\ContentSanitizer;
use App\Engines\Document\PdfRenderer;
use App\Engines\Template\TemplateRenderer;
use App\Models\Document;
use App\Models\Template;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * House styles: letterheads, standard forms, the shape a procedure is supposed to have (§65, §66).
 *
 * A template is a document you have not written yet, and treating it as literally that is what keeps
 * this from becoming a second content stack (§90). Same editor, same sanitiser, same renderer — the
 * only difference is that a template's body contains placeholders and nobody signs it.
 *
 * THE SANITISER RUNS HERE TOO, and that deserves saying out loud. It is tempting to trust template
 * content because only administrators write it, but "only administrators" is a permission, not a
 * guarantee: an administrator account is exactly what an attacker aims for, and a template body is
 * rendered into every document that uses it. A stored script in a letterhead would run for every
 * reader in the company. So template HTML goes through the same whitelist as document HTML, and
 * there is no privileged path that skips it (§84).
 *
 * PLACEHOLDERS ARE NOT BLADE. TemplateRenderer substitutes from a fixed whitelist of exposed fields
 * and leaves anything else visible on the page. This service never compiles, never evaluates, and
 * never passes template content to a view engine.
 */
class TemplateService
{
    public function __construct(
        private readonly ContentSanitizer $sanitizer,
        private readonly TemplateRenderer $renderer,
        private readonly PdfRenderer $pdf,
        private readonly ActivityRecorder $activity,
    ) {}

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes, User $actor): Template
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $template = new Template($this->safeAttributes($attributes));

            $template->organization_id = $actor->organization_id;
            $template->created_by = $actor->getKey();
            $template->slug = $this->uniqueSlug($attributes['name'] ?? 'template', (int) $actor->organization_id);

            $this->applyContent($template, $attributes);

            $template->save();

            if ($template->is_default) {
                $this->claimDefault($template);
            }

            $this->activity->record(
                $template,
                'created',
                $actor->name.' created the "'.$template->name.'" template.',
                ['kind' => $template->kind],
                $actor,
            );

            return $template;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function update(Template $template, array $attributes, User $actor): Template
    {
        return DB::transaction(function () use ($template, $attributes, $actor) {
            $template->fill($this->safeAttributes($attributes));

            $this->applyContent($template, $attributes);

            // Captured before the save, because afterwards isDirty() is empty and the interesting
            // question — did the body change, or only the name — is no longer answerable.
            $bodyChanged = $template->isDirty(['content_html', 'header_html', 'footer_html']);
            $changed = array_keys($template->getDirty());

            $template->save();

            if ($template->is_default) {
                $this->claimDefault($template);
            }

            /*
             * The audit trail catches this automatically (§59), but the audit trail is not what a
             * document controller reads. A letterhead quietly changing under two hundred documents
             * is precisely the kind of event somebody later needs to date, so it belongs in the
             * human-readable history too.
             */
            if ($changed !== []) {
                $this->activity->record(
                    $template,
                    'updated',
                    $actor->name.($bodyChanged
                        ? ' changed the body of the "'.$template->name.'" template.'
                        : ' updated the "'.$template->name.'" template.'),
                    ['fields' => $changed],
                    $actor,
                );
            }

            return $template;
        });
    }

    /**
     * Copy a template so somebody can vary it without disturbing the original.
     *
     * The everyday case: "the standard method statement, but for the Lagos office". Duplicating beats
     * editing, because the original is in use by documents that were written against it.
     */
    public function duplicate(Template $template, User $actor, ?string $name = null): Template
    {
        return DB::transaction(function () use ($template, $actor, $name) {
            $copy = $template->replicate([
                'id', 'slug', 'is_default', 'created_by', 'created_at', 'updated_at', 'deleted_at',
            ]);

            $copy->name = $name ?? $template->name.' (copy)';
            $copy->slug = $this->uniqueSlug($copy->name, (int) $template->organization_id);
            $copy->is_default = false;
            $copy->created_by = $actor->getKey();

            $copy->save();

            return $copy;
        });
    }

    public function activate(Template $template, bool $active): Template
    {
        $template->forceFill([
            'is_active' => $active,
            'is_default' => $active ? $template->is_default : false,
        ])->save();

        return $template;
    }

    public function delete(Template $template, User $actor): void
    {
        $template->delete();

        $this->activity->record(
            $template,
            'deleted',
            $actor->name.' removed the "'.$template->name.'" template. Documents already written from it keep their content.',
            [],
            $actor,
        );
    }

    // ================================================================== using a template

    /**
     * The body a new document starts life with.
     *
     * Placeholders are resolved ONCE, here, at the moment of use — never at render time. A procedure
     * approved in March that says "Prepared by: Amina Bello" must still say that in November after
     * Amina has left, because the approved revision is a record of what was approved. Live
     * substitution would quietly rewrite history every time somebody opened the page.
     *
     * @param  array<string,mixed>  $context
     */
    public function seedContent(Template $template, array $context = []): string
    {
        return $this->renderer->render($template, $context);
    }

    /**
     * Placeholder values drawn from a real document, for seeding and for preview.
     *
     * @return array<string,mixed>
     */
    public function contextFor(Document $document, ?User $user = null): array
    {
        $document->loadMissing(['project', 'category', 'organization', 'owner']);

        return array_filter([
            'document' => $document,
            'project' => $document->project,
            'category' => $document->category,
            'organization' => $document->organization,
            'user' => $user ?? $document->owner,
        ], fn ($value) => $value !== null);
    }

    /**
     * What the template looks like filled in, without creating anything.
     *
     * Deliberately rendered with the placeholder names left standing rather than against a live
     * record: an administrator designing a letterhead should not have to pick a real document, and a
     * preview that pulled one in would become a way to read a document the previewer has no right to
     * (§61).
     */
    public function preview(Template $template): string
    {
        return $this->pdf->renderHtml(
            title: $template->name,
            bodyHtml: $template->content_html ?? '',
            headerHtml: $template->header_html,
            footerHtml: $template->footer_html,
            template: $template,
            watermark: 'PREVIEW',
        );
    }

    // ================================================================== internals

    /**
     * Sanitise every content field, and record which placeholders the body actually uses.
     *
     * The recorded list is an affordance for the editor's insert menu; TemplateRenderer resolves
     * against its own whitelist regardless, so a hand-edited `placeholders` column cannot widen what
     * a template is able to read.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function applyContent(Template $template, array $attributes): void
    {
        foreach (['content_html', 'header_html', 'footer_html'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $template->{$field} = $this->sanitizer->clean($attributes[$field]);
            }
        }

        if (array_key_exists('content_json', $attributes)) {
            $template->content_json = is_array($attributes['content_json']) ? $attributes['content_json'] : null;
        }

        $template->placeholders = $this->placeholdersIn(
            ($template->content_html ?? '').($template->header_html ?? '').($template->footer_html ?? '')
        );
    }

    /** @return array<int,string> */
    private function placeholdersIn(string $html): array
    {
        preg_match_all(TemplateRenderer::PLACEHOLDER_PATTERN, $html, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }

    private function uniqueSlug(string $name, int $organizationId): string
    {
        $base = Str::slug($name) ?: 'template';
        $slug = $base;
        $suffix = 2;

        while (Template::withTrashed()
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /** One default per kind, per register. */
    private function claimDefault(Template $template): void
    {
        Template::query()
            ->where('kind', $template->kind)
            ->where('document_category_id', $template->document_category_id)
            ->where('id', '!=', $template->getKey())
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function safeAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'organization_id', 'slug', 'created_by', 'created_at', 'updated_at', 'deleted_at',
            'content_html', 'header_html', 'footer_html', 'content_json', 'placeholders',
        ]));
    }
}
