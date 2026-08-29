<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\SettingsRepository;
use App\Models\Setting;
use App\Support\SettingsCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The configuration centre (§5, §72, §86).
 *
 * Every value on this page is a decision a company might make differently, which is exactly why
 * none of them is a constant in code. Changing how the company works is a database write, not a
 * deployment.
 *
 * The form is a FIXED vocabulary, not a key-value writer. A submitted key that is not in
 * SettingsCatalogue is refused rather than stored (§84) — otherwise this one screen would be a
 * way to write arbitrary rows into the settings table, and `settings.is_editable = false` would
 * mean nothing. Locked rows are shown read-only and rejected on submit even if the input reappears.
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Setting::class);

        $organizationId = (int) $request->user()->organization_id;

        return view('settings.index', [
            'groups' => SettingsCatalogue::groups(),
            'values' => $this->settings->resolved($organizationId),
            'locked' => $this->lockedKeys($organizationId),
            'canManage' => $request->user()->hasPermission('setting.manage'),
        ]);
    }

    public function update(Request $request, SettingsRepository $settings): RedirectResponse
    {
        // Settings are organization-wide configuration: they carry no owner, no unit and no
        // project, so there is no record-level reach to compute and no single instance to
        // authorize against — this writes many rows at once. The class-level ability IS the whole
        // question here, and `is_editable` below stands in for the per-record refusal.
        abort_unless($request->user()->hasPermission('setting.manage'), 403);

        return $this->apply($request, $settings);
    }

    /**
     * Validate and write the submitted subset.
     */
    private function apply(Request $request, SettingsRepository $settings): RedirectResponse
    {
        $actor = $request->user();
        $organizationId = (int) $actor->organization_id;
        $submitted = (array) $request->input('settings', []);
        $locked = $this->lockedKeys($organizationId);

        // Only keys the catalogue knows survive. Everything else is discarded before validation,
        // so an injected key never reaches the repository at all.
        $known = array_intersect_key($submitted, SettingsCatalogue::definitions());

        $rules = [];
        $payload = [];

        // Validated FLAT, one setting per top-level key, even though the form posts them nested
        // under `settings[...]`. Every key in the catalogue contains dots — `org.label.division` —
        // and a rule path is a NESTING path: `settings.org.label.division` asks for four levels
        // deep, finds nothing, and fails `required` on a value that was submitted perfectly well.
        //
        // The dots are escaped so the validator reads them as part of the name rather than as
        // structure. `validated()` and the error bag both come back keyed with real dots, which is
        // what the form reads back with $errors->first($key).
        foreach ($known as $key => $value) {
            if (in_array($key, $locked, true)) {
                continue;
            }

            $rules[self::escape($key)] = SettingsCatalogue::rulesFor($key);
            $payload[$key] = $value;
        }

        // Unticked checkboxes and empty multi-selects do not post at all, so a boolean absent from
        // the submission means false — but only for a group that was actually on the screen. The
        // form carries a `present[]` list naming every key it rendered, which is what tells the
        // difference between "turned off" and "not shown".
        foreach ((array) $request->input('present', []) as $key) {
            if (! SettingsCatalogue::has($key) || in_array($key, $locked, true) || array_key_exists($key, $payload)) {
                continue;
            }

            $definition = SettingsCatalogue::definitions()[$key];

            if ($definition['type'] === 'boolean') {
                $payload[$key] = false;
                $rules[self::escape($key)] = ['boolean'];
            } elseif ($definition['type'] === 'multiselect') {
                $payload[$key] = [];
                $rules[self::escape($key)] = ['array'];
            }
        }

        $validated = Validator::make($payload, $rules, [], $this->labels())->validate();

        $changed = [];

        foreach ($validated as $key => $value) {
            $value = SettingsCatalogue::cast($key, $value);
            $current = $settings->get($key, null, $organizationId);

            if ($current === $value) {
                continue;
            }

            // set() audits each write itself (§71) and flushes the tenant's settings cache.
            $settings->set($key, $value, $organizationId);
            $changed[] = $key;
        }

        if ($changed !== []) {
            $this->activity->record(
                $actor->organization,
                'updated',
                $actor->name.' changed '.count($changed).' '.(count($changed) === 1 ? 'setting' : 'settings'),
                ['keys' => $changed],
            );
        }

        return redirect()->route('settings.index')->with(
            'status',
            $changed === []
                ? 'No settings were changed.'
                : count($changed).' '.(count($changed) === 1 ? 'setting has' : 'settings have').' been updated.',
        );
    }

    /**
     * A setting key, written so the validator treats its dots as part of the name.
     *
     * Only rule keys are escaped. Custom attribute names are looked up under the plain key, and
     * both `validated()` and the error bag come back with the plain key too.
     */
    private static function escape(string $key): string
    {
        return str_replace('.', '\.', $key);
    }

    /**
     * Keys this tenant is not allowed to change.
     *
     * A row flagged `is_editable = false` is platform-enforced structure rather than a company
     * preference. SettingsRepository::set() throws on one; this stops the form offering it, and
     * stops a resubmitted input reaching that throw.
     *
     * @return array<int,string>
     */
    private function lockedKeys(int $organizationId): array
    {
        return Setting::query()
            ->where(fn ($q) => $q->where('organization_id', $organizationId)->orWhereNull('organization_id'))
            ->where('is_editable', false)
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Human names for validation messages, so an error reads "Minimum password length must be at
     * least 8" rather than naming a dotted key.
     *
     * Keyed to match the flat attribute names used in apply(); the error bag is keyed the same way,
     * which is what the form reads back with $errors->first($key).
     *
     * @return array<string,string>
     */
    private function labels(): array
    {
        $out = [];

        foreach (SettingsCatalogue::definitions() as $key => $definition) {
            $out[$key] = mb_strtolower((string) $definition['label']);
        }

        return $out;
    }
}
