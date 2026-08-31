<?php

declare(strict_types=1);

namespace App\Support;

use App\Engines\Configuration\SettingsRepository;
use Illuminate\Support\Str;

/**
 * A short, company-editable list of choices, stored as one setting.
 *
 * Half a dozen fields in Phase 4 are a dropdown whose contents are a company's own vocabulary and
 * nobody else's: deliverable types, submittal types, submittal review codes, drawing purposes.
 * One firm's review codes are A/B/C/D, the next uses 1/2/3/4, the third writes them out in words.
 * Hard-coding any of those is exactly the failure the configurability mandate names — a company
 * that has to file a change request to add "Method Statement" to a dropdown does not have a
 * configurable system.
 *
 * WHY A SETTING AND NOT A TABLE. A lookup table needs a CRUD screen, a policy, a soft-delete
 * story and a decision about what happens to records pointing at a row somebody deleted. These
 * lists are four to ten short strings that change once a year. A comma-separated setting, editable
 * on the settings screen every administrator already knows, buys the same configurability for none
 * of that surface — and because the stored value on a record is the SLUG, removing an entry from
 * the list leaves historical records readable rather than orphaned.
 *
 * VALUES ARE SLUGS, LABELS ARE WHAT WAS TYPED. "For Construction" stores `for_construction`. That
 * separation is what lets a company fix a typo in a label without rewriting every row that used it,
 * and it keeps the values safe to put in a URL or a workflow condition.
 *
 * NOT A VALIDATION BOUNDARY BY ACCIDENT. Form requests validate against `keys()` from this class,
 * so a posted value outside the configured list is rejected server-side (§84) — the dropdown is
 * the convenience, this is the rule.
 */
class OptionList
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * The list for one setting key, as value => label.
     *
     * @param  array<int,string>  $fallback  labels used when the company has never set the list
     * @return array<string,string>
     */
    public function for(string $key, array $fallback = [], ?int $organizationId = null): array
    {
        $raw = trim($this->settings->string($key, '', $organizationId));

        $labels = $raw === ''
            ? $fallback
            : array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));

        $options = [];

        foreach ($labels as $label) {
            $options[self::slug($label)] = $label;
        }

        return $options;
    }

    /**
     * Just the acceptable values, for a validation rule.
     *
     * @param  array<int,string>  $fallback
     * @return array<int,string>
     */
    public function keys(string $key, array $fallback = [], ?int $organizationId = null): array
    {
        return array_keys($this->for($key, $fallback, $organizationId));
    }

    /**
     * Turn a stored value back into something readable.
     *
     * Falls back to a headline of the slug rather than to an empty cell, because a record whose
     * type was removed from the list six months ago still has to render — and "For Construction"
     * derived from the slug is a far better answer than a blank column that looks like data loss.
     *
     * @param  array<int,string>  $fallback
     */
    public function label(string $key, ?string $value, array $fallback = [], ?int $organizationId = null): string
    {
        if (blank($value)) {
            return '—';
        }

        return $this->for($key, $fallback, $organizationId)[$value]
            ?? Str::headline($value);
    }

    /** "For Construction" => "for_construction". Stable, URL-safe, and safe in a workflow condition. */
    public static function slug(string $label): string
    {
        return Str::of($label)->lower()->replace(['/', '-', '.'], ' ')->snake()->toString();
    }
}
