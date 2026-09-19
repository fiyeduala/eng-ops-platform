<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExpiryWatch;
use App\Models\Role;
use App\Models\User;
use App\Services\ExpiryWatchService;
use App\Support\ExpiryCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The renewals editor — every date this company wants to be warned about (§73).
 *
 * WHY THIS SCREEN EXISTS AT ALL. Before it, "warn me a month before an insurance certificate
 * lapses" was a line of PHP somebody had to deploy, and the two registers that happened to have one
 * were the two registers a developer had got round to. A company that wanted the same courtesy for
 * its permits, its training records or its vendor accreditations had to ask. §73 says a company can
 * be configured end to end by somebody who is not a developer, and an operations manager knowing
 * which dates matter — and being unable to say so — is exactly the failure that requirement names.
 *
 * ONE PAGE, NOT A WIZARD. Everything is on the index: the registers that offer dates, the watches
 * already set, and the form to add another. A watch is four decisions — which date, how far ahead,
 * whether to keep nagging once it has passed, and who hears — and spreading four decisions across
 * three screens is how a setup task becomes a project.
 *
 * GATE 2 ONLY. Every tile, button and row here is hidden from somebody without `expiry_watch.manage`
 * as a courtesy; the route middleware and ExpiryWatchPolicy are what actually stop them (§6, §84).
 * The service is the third gate and the one that matters most, because it is the only one that can
 * tell a watch naming this company's roles from a watch naming somebody else's.
 */
class ExpiryWatchController extends Controller
{
    public function __construct(
        private readonly ExpiryWatchService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ExpiryWatch::class);

        $user = $request->user();

        $watches = ExpiryWatch::query()
            ->orderBy('entity_type')
            ->orderBy('name')
            ->get();

        return view('configuration.expiry-watches.index', [
            'watches' => $watches,
            'entities' => ExpiryCatalogue::watchableEntities(),
            'dateFields' => $this->dateFields(),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            // Deactivated accounts are left out because a watch is a forward-looking instruction:
            // naming somebody who has left is a reminder that will never be read, and the audience
            // list is the one place this screen can say so before it happens rather than after.
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'defaultLeadDays' => ExpiryWatch::DEFAULT_LEAD_DAYS,
            'maxLeadDays' => ExpiryWatch::MAX_LEAD_DAYS,
            'canManage' => $user->can('create', ExpiryWatch::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ExpiryWatch::class);

        try {
            $this->service->create($this->payload($request), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('configuration.expiry-watches.index')
            ->with('status', 'That date is now being watched.');
    }

    public function update(Request $request, ExpiryWatch $expiryWatch): RedirectResponse
    {
        $this->authorize('update', $expiryWatch);

        try {
            $this->service->update($expiryWatch, $this->payload($request), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('configuration.expiry-watches.index')
            ->with('status', 'The reminders for “'.$expiryWatch->name.'” have been updated.');
    }

    /**
     * Silence a watch, or start it up again.
     *
     * Separate from `update` so that stopping the e-mails is one click during the week somebody is
     * migrating a register, rather than a trip through a form where the other six fields are also
     * in play and one of them might get changed by accident.
     */
    public function toggle(Request $request, ExpiryWatch $expiryWatch): RedirectResponse
    {
        $this->authorize('update', $expiryWatch);

        $this->service->setActive($expiryWatch, ! $expiryWatch->is_active, $request->user());

        return back()->with('status', $expiryWatch->is_active
            ? '“'.$expiryWatch->name.'” is being watched again.'
            : '“'.$expiryWatch->name.'” has been switched off. Nothing is deleted.');
    }

    public function destroy(Request $request, ExpiryWatch $expiryWatch): RedirectResponse
    {
        $this->authorize('delete', $expiryWatch);

        $name = $expiryWatch->name;

        $this->service->delete($expiryWatch, $request->user());

        return redirect()
            ->route('configuration.expiry-watches.index')
            ->with('status', '“'.$name.'” is no longer watched.');
    }

    /**
     * What the form is allowed to say.
     *
     * Deliberately NOT `$request->all()`: `organization_id`, `id` and anything else somebody adds
     * to the payload by hand would otherwise reach a `fill()` (§74, §84). The service validates
     * every one of these again — this is about what may be spoken about, not whether it is true.
     *
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'entity_type' => (string) $request->input('entity_type', ''),
            'date_field' => (string) $request->input('date_field', ''),
            'name' => (string) $request->input('name', ''),
            'lead_days' => $this->leadDays($request),
            'warn_when_overdue' => $request->boolean('warn_when_overdue'),
            'notify_role_ids' => (array) $request->input('notify_role_ids', []),
            'notify_user_ids' => (array) $request->input('notify_user_ids', []),
            'notify_owner' => $request->boolean('notify_owner'),
            'notify_followers' => $request->boolean('notify_followers'),
        ];
    }

    /**
     * The milestones, typed as "30, 7, 1" rather than picked from a list.
     *
     * A free-text box because the useful answer is different for every kind of date: a visa needs
     * three months, a gas cylinder test needs a fortnight, and a list of checkboxes long enough to
     * hold both is unusable for either. The service is what turns the text into numbers it trusts.
     *
     * @return array<int,int>
     */
    private function leadDays(Request $request): array
    {
        $raw = $request->input('lead_days', '');

        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_numeric'));
        }

        $days = [];

        foreach (preg_split('/[^0-9]+/', (string) $raw) ?: [] as $piece) {
            if ($piece !== '') {
                $days[] = (int) $piece;
            }
        }

        return $days;
    }

    /**
     * Every register's dates, in one array, so the browser can swap the second select box.
     *
     * Handed over as data rather than fetched over a second request: the whole map is a few dozen
     * short strings, it changes only when the platform is upgraded, and an endpoint would be one
     * more place to get authorization wrong for no benefit.
     *
     * @return array<string,array<string,string>>
     */
    private function dateFields(): array
    {
        $fields = [];

        foreach (array_keys(ExpiryCatalogue::watchableEntities()) as $entityType) {
            $fields[$entityType] = ExpiryCatalogue::dateFieldsFor($entityType);
        }

        return $fields;
    }
}
