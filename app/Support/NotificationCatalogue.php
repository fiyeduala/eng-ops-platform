<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Every kind of notification the system can send (docs/10 §5).
 *
 * A key rather than a class name is the unit of configuration, for the same reason permissions are
 * strings: a person's preference row has to survive a refactor. `task.assigned` means the same
 * thing to a preferences screen written today and to a listener rewritten in Phase 6.
 *
 * Only keys with a PRODUCER are listed. A preferences screen offering a toggle for something that
 * never fires is a lie told in the interface, and the reader has no way to tell it from a feature
 * that is quietly broken. Later phases add their rows as they add their listeners.
 *
 * Two flags carry weight:
 *
 *   MANDATORY — the notification itself cannot be switched off, only its channel mix (docs/10 §4).
 *   The test is not "is it important" but "would silence mislead": being handed work, being called
 *   to a meeting, being sent a directive. A person who muted `task.assigned` and then missed a job
 *   would reasonably say the system never told them, and they would be right.
 *
 *   URGENCY — `urgent` ignores quiet hours and sorts to the top of the bell. Reserved for things
 *   where a delay of eight hours changes the outcome; used sparingly, because a system where
 *   everything is urgent has no way left to say that something is.
 *
 * Nothing here decides WHO is told — that is the Audience — and nothing here decides whether they
 * may be told, which is NotificationDispatcher's authorization filter and the only real boundary.
 */
final class NotificationCatalogue
{
    /**
     * key => [module, group label, label, description, urgency, mandatory, [in_app, email, push]]
     *
     * The default channel mix is what a person who never opens the preferences screen gets. Email
     * defaults on for the things somebody has to act on and off for the broadcast ones, because the
     * fastest way to make people stop reading their inbox is to fill it with announcements.
     */
    private const KEYS = [
        // --- work ---------------------------------------------------------------------------
        'task.assigned' => [
            'task', 'Work', 'Work assigned to you',
            'Somebody put a task on you, or handed you one that was on somebody else.',
            'high', true, [true, true, false],
        ],

        // --- projects -----------------------------------------------------------------------
        'project.member_added' => [
            'project', 'Projects', 'Added to a project',
            'You were brought onto a project team. This changes what you can see.',
            'normal', false, [true, true, false],
        ],

        // --- memos --------------------------------------------------------------------------
        'memo.issued' => [
            'memo', 'Communication', 'A memo addressed to you',
            'An internal memo was issued with you on the distribution.',
            'high', true, [true, true, false],
        ],

        // --- announcements ------------------------------------------------------------------
        'announcement.published' => [
            'announcement', 'Communication', 'Announcements',
            'A general announcement was published to your part of the company.',
            'normal', false, [true, false, false],
        ],

        // --- meetings -----------------------------------------------------------------------
        'meeting.invited' => [
            'meeting', 'Meetings', 'Meeting invitations',
            'You were invited to a meeting, or your part in one changed.',
            'high', true, [true, true, false],
        ],
        'meeting.rescheduled' => [
            'meeting', 'Meetings', 'Meeting moved',
            'A meeting you are down for was moved to a different time or place.',
            'high', true, [true, true, false],
        ],
        'meeting.cancelled' => [
            'meeting', 'Meetings', 'Meeting cancelled',
            'A meeting you are down for is no longer happening.',
            'urgent', true, [true, true, false],
        ],
        'meeting.minutes_published' => [
            'meeting', 'Meetings', 'Minutes circulated',
            'The minutes of a meeting you were part of have been circulated.',
            'normal', false, [true, true, false],
        ],
    ];

    /**
     * @return array<int,array{
     *     key:string, module:string, group:string, label:string, description:string,
     *     urgency:string, mandatory:bool, defaults:array{in_app:bool,email:bool,push:bool}
     * }>
     */
    public static function definitions(): array
    {
        $out = [];

        foreach (self::KEYS as $key => [$module, $group, $label, $description, $urgency, $mandatory, $channels]) {
            $out[] = [
                'key' => $key,
                'module' => $module,
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'urgency' => $urgency,
                'mandatory' => $mandatory,
                'defaults' => [
                    'in_app' => $channels[0],
                    'email' => $channels[1],
                    'push' => $channels[2],
                ],
            ];
        }

        return $out;
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::KEYS);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::KEYS);
    }

    /**
     * @return array{
     *     key:string, module:string, group:string, label:string, description:string,
     *     urgency:string, mandatory:bool, defaults:array{in_app:bool,email:bool,push:bool}
     * }|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * An unknown key resolves to a normal, non-mandatory, in-app-only notice rather than throwing.
     *
     * A listener that names a key nobody added to this file is a bug, but it is a bug about
     * delivery, not about safety — and taking the request down for it would turn a missing
     * catalogue row into a failed save of the record the notification was about.
     */
    public static function urgencyOf(string $key): string
    {
        return self::find($key)['urgency'] ?? 'normal';
    }

    public static function isMandatory(string $key): bool
    {
        return self::find($key)['mandatory'] ?? false;
    }

    /** @return array{in_app:bool,email:bool,push:bool} */
    public static function defaultChannels(string $key): array
    {
        return self::find($key)['defaults'] ?? ['in_app' => true, 'email' => false, 'push' => false];
    }

    public static function labelFor(string $key): string
    {
        return self::find($key)['label'] ?? ucfirst(str_replace(['.', '_'], ' ', $key));
    }

    /**
     * Grouped for the preferences screen, in the order the constant declares them.
     *
     * @return Collection<string,Collection<int,array<string,mixed>>>
     */
    public static function grouped(): Collection
    {
        return collect(self::definitions())->groupBy('group');
    }

    /** @return array<int,string> the urgency levels, least to most */
    public static function urgencies(): array
    {
        return ['normal', 'high', 'urgent'];
    }
}
