<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Engines\Template\TemplateRenderer;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Template;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Templates and their placeholder language (§66).
 *
 * A template is CONTENT AN ADMINISTRATOR TYPES INTO A WEB FORM, and every test here follows from
 * that one sentence. The person editing a letterhead holds `template.manage`, which is an ordinary
 * configuration permission held by ordinary configuration staff. It must not, by any route, become
 * the ability to run code or to read data they were not granted.
 *
 * SO THE PLACEHOLDER LANGUAGE IS NOT BLADE, AND THE FIRST GROUP OF TESTS EXISTS TO KEEP IT THAT WAY.
 * `@php`, `{{ file_get_contents(...) }}`, `{!! !!}` — each one is typed into a template body here
 * and asserted to come out as inert text. Compiling template bodies as Blade is the single most
 * tempting shortcut in this module and it is a straight privilege escalation from "can format a
 * letter" to "owns the server".
 *
 * THE WHITELIST IS THE OTHER HALF. A placeholder resolves only if its namespace is known AND the
 * field was deliberately exposed, so `{{user.password}}` cannot reach a hash and no dotted path
 * typed into a text box walks the object graph. The test for that asserts on a real hash never
 * appearing, not merely on the token surviving.
 *
 * AND SUBSTITUTION HAPPENS ONCE, AT THE MOMENT OF USE. A procedure approved in March saying
 * "Prepared by: Amina Bello" must still say that in November after Amina has left. Live
 * substitution would quietly rewrite approved history every time somebody opened the page, which is
 * the difference between a document management system and a mail merge.
 */
final class TemplateRenderTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private DocumentCategory $procedures;

    private User $adaeze;   // template administrator

    private User $ngozi;    // author: uses templates, cannot edit them

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);

        $administrator = $this->makeRole($this->org, 'Template Administrator', [
            'template.view' => 'organization',
            'template.manage' => 'organization',
            'document_category.view' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        $author = $this->makeRole($this->org, 'Document Author', [
            'document.view' => 'organization',
            'document.create' => 'organization',
            'document.edit' => 'organization',
            'document.submit' => 'organization',
            'document_category.view' => 'organization',
            'org_unit.view' => 'organization',
            'template.view' => 'organization',
        ]);

        $this->adaeze = $this->makePerson($this->org, 'Adaeze Obi', $engineering, [$administrator]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$author]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
            'workflow_id' => null,
            'draft_revision_prefix' => null,
        ]);
    }

    // ================================================================== it is not Blade

    #[Test]
    public function blade_syntax_typed_into_a_template_is_inert_text(): void
    {
        $hostile = <<<'HTML'
            <p>@php echo 'executed'; @endphp</p>
            <p>{{ 7 * 6 }}</p>
            <p>{!! 'raw' !!}</p>
            <p>@if (true) branch @endif</p>
            <p>{{ config('app.key') }}</p>
        HTML;

        $template = $this->template(['content_html' => $hostile]);
        $rendered = app(TemplateRenderer::class)->render($template);

        // NOTHING WAS COMPILED, and the proof is that the directives are still THERE. Had Blade
        // run, `@php ... @endphp` would have been consumed and replaced by its output; instead the
        // whole construct survives verbatim as text on a page. Same for the conditional: `@if` and
        // `@endif` are both still present, so `branch` is literal text rather than a taken branch.
        $this->assertStringContainsString('@php', $rendered);
        $this->assertStringContainsString('@endphp', $rendered);
        $this->assertStringContainsString('@if', $rendered);
        $this->assertStringContainsString('@endif', $rendered);

        // Nothing evaluated. `7 * 6` did not become 42, and the application key did not resolve —
        // because this class does string replacement against a resolved map and nothing else: no
        // expressions, no conditionals, no loops, no calls.
        $this->assertStringNotContainsString('42', $rendered);
        $this->assertStringNotContainsString((string) config('app.key'), $rendered);

        // `{{ 7 * 6 }}` is not a placeholder — it has spaces and an operator — so it survives as
        // literal text, which is the visible-failure behaviour the module chose on purpose.
        $this->assertStringContainsString('7 * 6', $rendered);
    }

    #[Test]
    public function a_template_body_cannot_smuggle_a_script_past_the_sanitizer(): void
    {
        $template = $this->template([
            'content_html' => '<p>Notice</p><script>fetch("/api/steal")</script>'
                .'<p onclick="alert(1)">Click</p><a href="javascript:alert(1)">Link</a>',
        ]);

        $rendered = app(TemplateRenderer::class)->render($template);

        // Escaping values as they are substituted would not have caught any of this: the template
        // author wrote the markup directly into the body. That is why the OUTPUT is cleaned too.
        $this->assertStringNotContainsString('<script', $rendered);
        $this->assertStringNotContainsString('onclick', $rendered);
        $this->assertStringNotContainsString('javascript:', $rendered);
        $this->assertStringContainsString('Notice', $rendered);
    }

    #[Test]
    public function a_value_substituted_into_a_template_is_escaped(): void
    {
        $document = $this->document('<img src=x onerror=alert(1)> Shoring');
        $template = $this->template(['content_html' => '<p>Title: {{document.title}}</p>']);

        $rendered = app(TemplateRenderer::class)->render($template, ['document' => $document]);

        // The hostile string came from a DOCUMENT TITLE, not from the template body. Both doors
        // need locking, and this is the second one.
        //
        // The assertion is about the TAG, not about the word. `onerror` still appears — as five
        // harmless characters inside an escaped entity — and demanding it disappear would be
        // demanding the value be mangled rather than escaped. What must not exist is a live
        // element: no `<img`, and the angle brackets arriving as entities.
        $this->assertStringNotContainsString('<img', $rendered);
        $this->assertStringContainsString('&lt;img', $rendered);
        $this->assertStringContainsString('Shoring', $rendered);
    }

    // ================================================================== the whitelist

    #[Test]
    public function a_placeholder_resolves_only_if_it_was_deliberately_exposed(): void
    {
        $template = $this->template([
            'content_html' => '<p>{{user.name}} · {{user.password}} · {{user.remember_token}}'
                .' · {{user.is_super_admin}} · {{secret.key}} · {{organization.name}}</p>',
        ]);

        $rendered = app(TemplateRenderer::class)->render($template, [
            'user' => $this->ngozi,
            'organization' => $this->org->refresh(),
            'secret' => $this->org,
        ]);

        $this->assertStringContainsString('Ngozi Eze', $rendered);
        $this->assertStringContainsString('Meridian Engineering', $rendered);

        // The hash never appears. Not "the token is left standing" — the VALUE is unreachable,
        // because `password` is not on the exposed list and there is no path from a name typed
        // into a text box to an arbitrary attribute.
        $this->assertStringNotContainsString((string) $this->ngozi->password, $rendered);
        $this->assertNotEmpty($this->ngozi->password);

        // An unknown namespace contributes nothing whatever was passed under it.
        $this->assertStringContainsString('{{secret.key}}', $rendered);
    }

    #[Test]
    public function an_unresolvable_placeholder_stays_visible_rather_than_vanishing(): void
    {
        $template = $this->template([
            'content_html' => '<p>Contract value: {{contract.value}}</p><p>Year: {{year}}</p>',
        ]);

        $rendered = app(TemplateRenderer::class)->render($template);

        // A letter that silently drops `{{contract.value}}` reads as finished and is wrong. One
        // that still shows the placeholder is obviously unfinished, and somebody fixes it before it
        // goes out.
        $this->assertStringContainsString('{{contract.value}}', $rendered);
        $this->assertStringContainsString(now()->format('Y'), $rendered);
    }

    #[Test]
    public function a_resolved_but_empty_field_becomes_empty_rather_than_staying_visible(): void
    {
        $document = $this->document();
        $template = $this->template(['content_html' => '<p>[{{document.description}}]</p>']);

        $rendered = app(TemplateRenderer::class)->render($template, ['document' => $document]);

        // The distinction the renderer draws with array_key_exists rather than isset: "we know this
        // field and it is blank" is a different statement from "we have never heard of this field",
        // and collapsing them would either blank real typos or print empty brackets full of braces.
        $this->assertStringNotContainsString('{{document.description}}', $rendered);
        $this->assertStringContainsString('[]', preg_replace('/\s+/', '', $rendered) ?? '');
    }

    // ================================================================== substitution happens once

    #[Test]
    public function a_document_started_from_a_template_keeps_the_values_as_they_were(): void
    {
        $template = $this->template([
            'content_html' => '<h1>{{document.title}}</h1><p>Prepared by {{user.name}} '
                .'for {{organization.name}}.</p><p>Method statement follows.</p>',
        ]);

        $this->actingFresh($this->ngozi)
            ->post(route('documents.store'), [
                'title' => 'Excavation and shoring procedure',
                'kind' => 'native',
                'confidentiality' => 'internal',
                'document_category_id' => $this->procedures->id,
                'org_unit_id' => $this->civil->id,
                'template_id' => $template->id,
            ])->assertRedirect();

        $document = Document::withoutGlobalScopes()->latest('id')->firstOrFail();
        $version = DocumentVersion::withoutGlobalScopes()
            ->where('document_id', $document->id)->latest('id')->firstOrFail();

        $this->assertStringContainsString('Excavation and shoring procedure', (string) $version->content_html);
        $this->assertStringContainsString('Ngozi Eze', (string) $version->content_html);
        $this->assertStringContainsString('Meridian Engineering', (string) $version->content_html);

        // Resolved ONCE, at the moment of use — the stored content holds values, not tokens.
        $this->assertStringNotContainsString('{{user.name}}', (string) $version->content_html);

        // Now the world moves on. The template is rewritten and the author is renamed.
        $this->actingFresh($this->adaeze)
            ->put(route('templates.update', $template), $this->templatePayload([
                'content_html' => '<p>Completely different boilerplate.</p>',
            ]))->assertRedirect();

        $this->ngozi->forceFill(['name' => 'Ngozi Okonkwo'])->save();

        // The document does not. A procedure approved in March that says "Prepared by Ngozi Eze"
        // still says that in November, because the revision is a record of what was written — not
        // a live view that rewrites history whenever somebody opens the page.
        $this->assertStringContainsString('Ngozi Eze', (string) $version->refresh()->content_html);
        $this->assertStringNotContainsString('Completely different boilerplate', (string) $version->content_html);
    }

    #[Test]
    public function a_retired_template_cannot_be_used_to_start_a_new_document(): void
    {
        $template = $this->template(['content_html' => '<p>Letterhead.</p>']);

        // Documents already made from it are untouched — retiring is not deleting.
        $existing = $this->documentFromTemplate($template, 'Excavation and shoring procedure');

        $this->actingFresh($this->adaeze)
            ->post(route('templates.activate', $template), ['is_active' => '0'])
            ->assertRedirect();

        $this->assertFalse((bool) $template->refresh()->is_active);

        // Refused at VALIDATION, not silently ignored downstream. `existsInTenant(..., activeOnly:
        // true)` scopes the lookup to live templates, so a retired id posted by hand — or by a form
        // somebody left open in a tab since before it was retired — is a stated error rather than a
        // document that quietly comes out blank and gets noticed after it is written.
        $this->actingFresh($this->ngozi)
            ->post(route('documents.store'), [
                'title' => 'Lifting operations procedure',
                'kind' => 'native',
                'confidentiality' => 'internal',
                'document_category_id' => $this->procedures->id,
                'org_unit_id' => $this->civil->id,
                'template_id' => $template->id,
            ])->assertSessionHasErrors('template_id');

        $this->assertSame(1, Document::withoutGlobalScopes()->count());
        $this->assertStringContainsString('Letterhead', (string) $existing->content_html);
    }

    // ================================================================== authorization

    #[Test]
    public function using_a_template_is_reading_it_and_editing_one_is_not(): void
    {
        $template = $this->template(['content_html' => '<p>Letterhead.</p>']);

        // Seeding a new document with a letterhead is reading the template. Requiring more would
        // mean only administrators could write a letter.
        $this->actingFresh($this->ngozi)
            ->get(route('templates.show', $template))
            ->assertOk();

        $this->actingFresh($this->ngozi)
            ->get(route('templates.preview', $template))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        foreach ([
            fn () => $this->actingFresh($this->ngozi)
                ->put(route('templates.update', $template), $this->templatePayload()),
            fn () => $this->actingFresh($this->ngozi)
                ->post(route('templates.duplicate', $template)),
            fn () => $this->actingFresh($this->ngozi)
                ->delete(route('templates.destroy', $template)),
        ] as $attempt) {
            $attempt()->assertForbidden();
        }

        $this->assertSame('<p>Letterhead.</p>', (string) $template->refresh()->content_html);
    }

    #[Test]
    public function the_preview_never_reaches_for_a_real_record(): void
    {
        $this->document('Confidential shoring method');

        $template = $this->template([
            'content_html' => '<p>{{document.title}} — {{document.number}}</p>',
        ]);

        $pdf = $this->actingFresh($this->ngozi)
            ->get(route('templates.preview', $template))
            ->assertOk();

        $this->assertStringStartsWith('%PDF', substr($pdf->getContent(), 0, 4));

        // A preview that pulled in a live record to look convincing would become a way to read a
        // document the previewer has no right to (§61). It renders with the placeholder names
        // standing instead, which is also what an administrator designing a letterhead wants.
        $this->assertStringNotContainsString('Confidential shoring method', $pdf->getContent());
    }

    #[Test]
    public function a_template_from_another_organization_is_not_found(): void
    {
        $template = $this->template(['content_html' => '<p>Meridian letterhead.</p>']);

        $other = $this->makeOrganization('Kestrel Infrastructure');
        $otherUnit = $this->makeUnit($other, 'Projects', OrgUnitType::Department);
        $intruder = $this->makePerson($other, 'Sade Ajayi', $otherUnit, [
            $this->makeRole($other, 'Administrator', [
                'template.view' => 'organization',
                'template.manage' => 'organization',
            ]),
        ]);

        foreach (['templates.show', 'templates.edit', 'templates.preview'] as $name) {
            $this->actingFresh($intruder)->get(route($name, $template))->assertNotFound();
        }

        $this->actingFresh($intruder)
            ->put(route('templates.update', $template), $this->templatePayload(['name' => 'Taken']))
            ->assertNotFound();

        $this->assertStringContainsString('Meridian letterhead', (string) $template->refresh()->content_html);
    }

    // ================================================================== the insert menu

    #[Test]
    public function the_insert_menu_offers_exactly_what_the_renderer_can_resolve(): void
    {
        $available = TemplateRenderer::available();

        $this->assertArrayHasKey('general', $available);
        $this->assertContains('{{today}}', $available['general']);
        $this->assertContains('{{document.number}}', $available['document']);

        // Nothing on the menu may be a placeholder the renderer would leave standing — an insert
        // menu that offers a token producing literal braces in a finished letter is worse than no
        // menu at all. So every offered token is put through the renderer and required to resolve.
        //
        // `project` is checked separately below, because it resolves only when the document is on
        // a project. A menu offering it unconditionally is correct: a project token in a document
        // that has no project SHOULD stay visible, which is the module's whole failure posture.
        $context = [
            'document' => $this->document(),
            'organization' => $this->org->refresh(),
            'user' => $this->ngozi,
            'category' => $this->procedures,
        ];

        foreach (['general', 'document', 'organization', 'user', 'category'] as $namespace) {
            $template = $this->template(['content_html' => '<p>'.implode(' ', $available[$namespace]).'</p>']);

            $this->assertStringNotContainsString(
                '{{',
                app(TemplateRenderer::class)->render($template, $context),
                'The insert menu offers a '.$namespace.' token the renderer cannot resolve.',
            );
        }

        $this->assertArrayHasKey('project', $available);
        $this->assertContains('{{project.code}}', $available['project']);
    }

    // ================================================================== fixture

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function template(array $overrides = []): Template
    {
        $this->actingFresh($this->adaeze)
            ->post(route('templates.store'), $this->templatePayload($overrides))
            ->assertRedirect();

        return Template::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function templatePayload(array $overrides = []): array
    {
        return [
            'name' => 'Procedure letterhead',
            'description' => 'Standard front matter for procedures.',
            'kind' => 'document',
            'content_html' => '<p>Body.</p>',
            'header_html' => '<p>{{organization.name}}</p>',
            'footer_html' => '<p>Page footer</p>',
            'page_size' => 'A4',
            'orientation' => 'portrait',
            'is_active' => '1',
            'is_default' => '0',
            ...$overrides,
        ];
    }

    /** A document created through the form with a template attached, returning its first revision. */
    private function documentFromTemplate(Template $template, string $title): DocumentVersion
    {
        $this->actingFresh($this->ngozi)
            ->post(route('documents.store'), [
                'title' => $title,
                'kind' => 'native',
                'confidentiality' => 'internal',
                'document_category_id' => $this->procedures->id,
                'org_unit_id' => $this->civil->id,
                'template_id' => $template->id,
            ])->assertSessionHasNoErrors();

        $document = Document::withoutGlobalScopes()->latest('id')->firstOrFail();

        return DocumentVersion::withoutGlobalScopes()
            ->where('document_id', $document->id)->latest('id')->firstOrFail();
    }

    private function document(string $title = 'Excavation and shoring procedure'): Document
    {
        return $this->makeDocument($this->org, $this->ngozi, $title, [
            'document_category_id' => $this->procedures->id,
            'org_unit_id' => $this->civil->id,
        ]);
    }
}
