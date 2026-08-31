<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Template;
use Illuminate\Validation\Rule;

/**
 * The organization's house style, as a form (§36, §46).
 *
 * A template is the letterhead, the margins, the standard clauses and the placeholders that get
 * resolved once when a document is created from it. Administrators write it; everyone else inherits
 * it. That is the whole reason a company can use this platform instead of a shared folder of .docx
 * files nobody can keep in step.
 *
 * THE HTML IS ACCEPTED HERE AND SANITISED IN THE SERVICE, NOT VALIDATED HERE. There is exactly one
 * place that decides what HTML is safe — ContentSanitizer — and adding a weaker second opinion in a
 * form request would create the impression that content reaching the service by another path had
 * already been checked. The size cap below is a different thing: a resource limit, not a safety
 * check, and it belongs at the edge where the request is still cheap to reject.
 *
 * TEMPLATE HTML IS SANITISED EVEN THOUGH ONLY ADMINISTRATORS CAN WRITE IT. "Only administrators" is
 * a permission, not a guarantee about the bytes — and template markup is rendered into every
 * document that uses it and into every PDF built from those, so a script that survived here would
 * survive into hundreds of records read by people with no administrative rights at all (§6, §67).
 */
class TemplateRequest extends TenantRequest
{
    /** Roughly a very long standard-form contract. Well past any real letterhead. */
    private const MAX_HTML_BYTES = 1_000_000;

    public function rules(): array
    {
        $id = $this->route('template')?->getKey();

        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],

            'kind' => ['required', Rule::in(array_keys(Template::KINDS))],

            // A template may belong to one register — "this is the letterhead for RFIs" — or to
            // none, in which case it is offered everywhere.
            'document_category_id' => ['nullable', 'integer', $this->existsInTenant('document_categories')],

            'content_html' => ['nullable', 'string', 'max:'.self::MAX_HTML_BYTES],
            'content_json' => ['nullable', 'array'],
            'header_html' => ['nullable', 'string', 'max:100000'],
            'footer_html' => ['nullable', 'string', 'max:100000'],

            'page_size' => ['required', Rule::in(array_keys(Template::PAGE_SIZES))],
            'orientation' => ['required', Rule::in(['portrait', 'landscape'])],

            // Millimetres. Printers and binding margins are a real constraint in engineering
            // document control, so these are configurable rather than baked into the stylesheet.
            'margins' => ['nullable', 'array'],
            'margins.top' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'margins.right' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'margins.bottom' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'margins.left' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'is_active' => ['boolean'],
            'is_default' => ['boolean'],

            'slug' => ['nullable', 'string', 'max:160', $this->uniqueInTenant('templates', 'slug', $id)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_active', 'is_default']);
        $this->nullifyBlanks(['description', 'document_category_id', 'header_html', 'footer_html', 'slug']);
    }

    public function messages(): array
    {
        return [
            'content_html.max' => 'That template is too large to store. Split the boilerplate into '
                .'separate templates, or link the long annexes as attachments.',
        ];
    }

    /** @return array<string,mixed> */
    public function templateAttributes(): array
    {
        return $this->validated();
    }
}
