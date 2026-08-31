<?php

declare(strict_types=1);

namespace App\Engines\Template;

use App\Engines\Document\ContentSanitizer;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Fills placeholders in a template (§66).
 *
 * `{{document.number}}`, `{{organization.name}}`, `{{today}}` — that is the whole language, and
 * keeping it that small is the design.
 *
 * IT IS NOT BLADE, AND MUST NEVER BECOME BLADE. A template is content an administrator types into a
 * web form. Compiling it as Blade would give anybody who can edit a template the ability to run
 * arbitrary PHP — `@php`, `{{ file_get_contents('/etc/passwd') }}` — which is a privilege
 * escalation from "can format a letter" to "owns the server". This class does string replacement
 * against a resolved map and nothing else: no expressions, no conditionals, no loops, no calls.
 *
 * THE WHITELIST IS THE SECURITY BOUNDARY, and it works the same way ConditionEvaluator's does.
 * A placeholder resolves only if its namespace is known AND the field is one this class chose to
 * expose. `{{user.password}}` does not resolve to a hash — it resolves to nothing, because
 * `password` is not on the list and there is no path by which a name typed into a text box reaches
 * an arbitrary attribute.
 *
 * UNKNOWN PLACEHOLDERS ARE LEFT VISIBLE rather than blanked. A letter that silently drops
 * `{{contract.value}}` reads as finished and is wrong; one that still shows the placeholder is
 * obviously unfinished, and somebody fixes it before it goes out.
 */
class TemplateRenderer
{
    /**
     * Namespace => the fields exposed under it.
     *
     * Everything absent from this map is unreachable. Adding a field is a deliberate act by somebody
     * who has thought about who reads documents built from templates.
     *
     * @var array<string,array<int,string>>
     */
    private const EXPOSED = [
        'document' => ['number', 'title', 'description', 'confidentiality', 'revision', 'status'],
        'organization' => ['name', 'legal_name', 'address', 'phone', 'email', 'website'],
        'project' => ['code', 'name', 'client_name', 'location'],
        'user' => ['name', 'email', 'job_title'],
        'category' => ['name', 'code'],
    ];

    /**
     * What a placeholder looks like: `{{document.number}}`, `{{today}}`.
     *
     * Public because TemplateService scans template bodies with the same pattern to build the
     * editor's insert menu, and a second copy of this regex would eventually disagree with this one.
     */
    public const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*\}\}/i';

    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /**
     * Render a template's body with a context.
     *
     * The OUTPUT is sanitized, not the input: values are escaped as they are substituted, then the
     * whole thing is cleaned. Two layers, because escaping alone would not stop a template author
     * writing `<script>` directly into the template body.
     *
     * @param  array<string,Model|string|null>  $context  keyed by namespace — 'document', 'project'...
     */
    public function render(Template $template, array $context = []): string
    {
        return (string) $this->sanitizer->clean(
            $this->substitute((string) $template->content_html, $context)
        );
    }

    public function renderHeader(Template $template, array $context = []): ?string
    {
        return $template->header_html === null
            ? null
            : $this->sanitizer->clean($this->substitute($template->header_html, $context));
    }

    public function renderFooter(Template $template, array $context = []): ?string
    {
        return $template->footer_html === null
            ? null
            : $this->sanitizer->clean($this->substitute($template->footer_html, $context));
    }

    /**
     * Replace every `{{namespace.field}}` this class can resolve.
     *
     * The regex deliberately matches only `[a-z_]+\.[a-z_]+` plus the three bare tokens. Anything
     * with a space, a bracket, a pipe or a second dot simply is not a placeholder as far as this is
     * concerned, and survives into the output as literal text — which is the visible-failure
     * behaviour described above.
     *
     * @param  array<string,mixed>  $context
     */
    public function substitute(string $html, array $context = []): string
    {
        $map = $this->buildMap($context);

        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function (array $matches) use ($map): string {
                $token = mb_strtolower($matches[1]);

                // Not "isset" — a resolved-but-empty field should become empty, while an unresolvable
                // one stays visible. array_key_exists is what tells those two apart.
                return array_key_exists($token, $map)
                    ? e((string) $map[$token])
                    : $matches[0];
            },
            $html,
        );
    }

    /**
     * Every placeholder a template author may use, for the editor's insert menu.
     *
     * @return array<string,array<int,string>> namespace => tokens
     */
    public static function available(): array
    {
        $out = ['general' => ['{{today}}', '{{now}}', '{{year}}']];

        foreach (self::EXPOSED as $namespace => $fields) {
            $out[$namespace] = array_map(
                fn (string $field) => '{{'.$namespace.'.'.$field.'}}',
                $fields,
            );
        }

        return $out;
    }

    // ------------------------------------------------------------------ resolution

    /**
     * Flatten the context into `namespace.field => value`, obeying the whitelist.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function buildMap(array $context): array
    {
        $map = [
            'today' => now()->format('j F Y'),
            'now' => now()->format('j F Y, H:i'),
            'year' => now()->format('Y'),
        ];

        foreach ($context as $namespace => $value) {
            $namespace = mb_strtolower((string) $namespace);
            $fields = self::EXPOSED[$namespace] ?? null;

            if ($fields === null) {
                continue;   // an unknown namespace contributes nothing, whatever was passed
            }

            if (is_scalar($value)) {
                $map[$namespace] = $value;

                continue;
            }

            if (! $value instanceof Model) {
                continue;
            }

            foreach ($fields as $field) {
                $map[$namespace.'.'.$field] = $this->readField($value, $field, $namespace);
            }
        }

        return $map;
    }

    /**
     * Read one whitelisted field, with a couple of sensible indirections.
     *
     * The indirections exist because the useful value is often one hop away — a document's status is
     * on the Status model, its revision on the current version, a user's job title on their Employee
     * record. Each is coded explicitly here rather than reached by a dotted path from the template,
     * so the set of reachable values stays exactly as large as this method makes it.
     */
    private function readField(Model $model, string $field, string $namespace): string
    {
        $direct = $model->getAttribute($field);

        if ($direct !== null) {
            return $direct instanceof \BackedEnum
                ? (string) $direct->value
                : (string) (is_scalar($direct) ? $direct : '');
        }

        /*
         * Each indirection loads its relation EXPLICITLY before reading it. The models handed to a
         * renderer arrive from wherever the caller had them — a route binding, `auth()->user()`, a
         * seeder — and none of those preload a relation this class happens to want. Reaching for it
         * bare throws a lazy-loading violation everywhere except production, where it silently
         * becomes an extra query per placeholder instead. `loadMissing` is a no-op when the relation
         * is already there, so the eager-loading callers pay nothing for it.
         */
        return match (true) {
            $namespace === 'document' && $field === 'revision' => (string) ($model->loadMissing('currentVersion')->currentVersion?->revision ?? ''),
            $namespace === 'document' && $field === 'status' => (string) ($model->loadMissing('status')->status?->label ?? ''),
            // Two hops, because a job title is not a column on anybody. It is the POSITION the
            // person holds, which is a configured record with its own history — the whole reason
            // an org chart can be redrawn without rewriting every letter that ever quoted a title.
            $namespace === 'user' && $field === 'job_title' && $model instanceof User => (string) ($model->loadMissing('employee.position')->employee?->position?->title ?? ''),
            default => '',
        };
    }
}
