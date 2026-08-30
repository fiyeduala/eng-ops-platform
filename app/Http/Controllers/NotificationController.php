<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Notification\NotificationDispatcher;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Support\NotificationCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * A person's own notifications (docs/10 §7).
 *
 * No `permission:` middleware anywhere in this controller, and that is deliberate rather than an
 * omission. There is no `notification.view` permission because there is nothing for an
 * administrator to grant or withhold: every action here addresses `$request->user()` and only
 * `$request->user()`, exactly as ProfileController does. Inventing a permission would mean
 * inventing one that could also be granted at `department` scope, and a department-scoped
 * `notification.view` is a licence to read other people's post.
 *
 * The interesting part of this file is that the list is filtered TWICE.
 *
 * NotificationDispatcher already checked, at send time, that the recipient could see the record
 * (§68). But access is revocable: a membership lapses, a project is reclassified, somebody is moved
 * to another department. A notification written on Tuesday is a durable copy of something the
 * record said, and by Thursday the record may have stopped saying it to this person. So the index
 * asks the policy again, per row, at read time — and a row that no longer passes is removed rather
 * than greyed out, because "there is something here you may not see" is itself a disclosure.
 *
 * Following a link does the same check a third time, at the moment of the click, which is the only
 * check that is actually load-bearing: the other two are about not showing stale things.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $query = Notification::query()
            ->forUser($user)
            ->with(['causer:id,name', 'project:id,name,number', 'subject'])
            ->ofModule($request->query('module') ? (string) $request->query('module') : null)
            ->search((string) $request->query('q', ''));

        if ($request->query('show') === 'unread') {
            $query->unread();
        }

        $page = $query->orderByDesc('id')->paginate(25)->withQueryString();

        // The read-time re-check. Grouped by subject type and id first, so a page showing eight
        // notifications about the same task asks its policy once rather than eight times — the
        // policy is not free, and the bell is on every page in the application.
        $verdicts = [];
        $withheld = 0;

        $visible = collect($page->items())->filter(function (Notification $notification) use ($user, &$verdicts, &$withheld) {
            $subject = $notification->subject;

            if ($subject === null) {
                // Either the notification was never about a record (an account notice), or the
                // record has since been deleted. Both are safe to show: there is nothing left to
                // leak, and hiding the second would make deletions look like access failures.
                return true;
            }

            $cacheKey = $notification->subject_type.':'.$notification->subject_id;

            $verdicts[$cacheKey] ??= Gate::forUser($user)->allows('view', $subject);

            if (! $verdicts[$cacheKey]) {
                $withheld++;
            }

            return $verdicts[$cacheKey];
        });

        return view('notifications.index', [
            'notifications' => $page,
            'visible' => $visible,
            'withheld' => $withheld,
            'unreadCount' => $this->dispatcher->unreadCountFor($user),
            'modules' => $this->moduleOptions(),
            'filters' => [
                'module' => $request->query('module'),
                'show' => $request->query('show'),
                'q' => $request->query('q'),
            ],
        ]);
    }

    /**
     * Follow a notification through to the record it is about.
     *
     * This exists rather than linking straight at `action_url` because it is the one place the
     * three things that have to happen together can happen together: the record is re-authorized at
     * the moment of the click, the notification is marked read only if that succeeded, and the
     * redirect target is one this application wrote rather than one that arrived in a request.
     *
     * A refusal here is a 403 on the notification, not a redirect to a page that will 403 — the
     * difference matters when somebody forwards a link.
     */
    public function open(Request $request, Notification $notification): RedirectResponse
    {
        $this->authorize('view', $notification);

        $subject = $notification->subject;

        if ($subject !== null && ! Gate::forUser($request->user())->allows('view', $subject)) {
            abort(403, 'This notification refers to something you can no longer open.');
        }

        $this->markAsRead($notification);

        $target = (string) $notification->action_url;

        // Only ever a path this application generated. `//evil.example` is a protocol-relative URL
        // that most browsers follow off-site, so "starts with a slash" is not on its own enough.
        $safe = str_starts_with($target, '/') && ! str_starts_with($target, '//');

        return $safe
            ? redirect()->to($target)
            : redirect()->route('notifications.index');
    }

    public function markRead(Request $request, Notification $notification): RedirectResponse
    {
        $this->authorize('update', $notification);

        $this->markAsRead($notification);

        return back()->with('status', 'Marked as read.');
    }

    /**
     * Clear the bell.
     *
     * A bulk UPDATE rather than a loop, and scoped to `user_id` in the query itself. The tenant
     * scope applies on top through the model, so this cannot reach a row belonging to another
     * company even if a user id repeated across installations.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $user = $request->user();

        $count = Notification::query()->forUser($user)->unread()->update(['read_at' => now()]);

        NotificationDispatcher::forgetUnreadCount((int) $user->getKey());

        return back()->with('status', $count === 0
            ? 'There was nothing unread.'
            : $count.' '.Str::plural('notification', $count).' marked as read.');
    }

    // ------------------------------------------------------------------ preferences

    public function preferences(Request $request): View
    {
        $user = $request->user();

        $stored = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy('notification_key');

        $rows = collect(NotificationCatalogue::definitions())->map(function (array $definition) use ($stored) {
            $mix = NotificationPreference::resolve($stored->get($definition['key']), $definition['key']);

            return $definition + ['current' => $mix];
        });

        return view('notifications.preferences', [
            'groups' => $rows->groupBy('group'),
        ]);
    }

    /**
     * Save the channel mix.
     *
     * Two things this refuses to do, both of them the reason it is not a generic settings save:
     *
     *   - It only ever writes rows for `$request->user()`. There is no user_id in the form and one
     *     placed there by hand is ignored, so this cannot be turned into "reconfigure somebody
     *     else's notifications" (§84).
     *   - It only ever writes keys the catalogue declares. An unknown key posted here would
     *     otherwise leave a permanent row nothing reads, and a preferences table slowly filling
     *     with junk is how a lookup that is supposed to be exact stops being exact.
     *
     * A mandatory notification's in-app channel is forced back on in
     * NotificationPreference::resolve() rather than here, so it holds however the row was written.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'preferences' => ['array'],
            'preferences.*.in_app' => ['nullable', 'boolean'],
            'preferences.*.email' => ['nullable', 'boolean'],
        ]);

        $submitted = $validated['preferences'] ?? [];

        DB::transaction(function () use ($submitted, $user) {
            foreach (NotificationCatalogue::definitions() as $definition) {
                $key = $definition['key'];
                $row = $submitted[$key] ?? [];

                NotificationPreference::query()->updateOrCreate(
                    ['user_id' => $user->getKey(), 'notification_key' => $key],
                    [
                        'organization_id' => $user->organization_id,
                        'in_app' => (bool) ($row['in_app'] ?? false),
                        'email' => (bool) ($row['email'] ?? false),

                        // Neither is offered in the interface yet — no web-push transport, no job
                        // to assemble a digest — and a toggle with nothing behind it is worse than
                        // no toggle. The columns stay so the choice survives when the channels
                        // land (docs/10 §3, §4).
                        'push' => false,
                        'digest' => false,
                    ],
                );
            }
        });

        return redirect()->route('notifications.preferences')
            ->with('status', 'Your notification settings have been saved.');
    }

    // ------------------------------------------------------------------ helpers

    private function markAsRead(Notification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();

            NotificationDispatcher::forgetUnreadCount((int) $notification->user_id);
        }
    }

    /** @return array<string,string> */
    private function moduleOptions(): array
    {
        return collect(NotificationCatalogue::definitions())
            ->pluck('module')
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $module) => [
                $module => ucfirst(str_replace('_', ' ', $module)),
            ])
            ->all();
    }
}
