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

        // --- approvals ------------------------------------------------------------------------
        //
        // `approval.requested` is mandatory and urgent, and both flags are deliberate. An approval
        // sitting unseen in an inbox is the single most expensive failure mode in this module —
        // work stops, deadlines pass, and the person waiting has no way of knowing whether the
        // approver is thinking about it or never heard. Somebody who muted this and then held up a
        // drawing for a fortnight would fairly say the system never told them.
        'approval.requested' => [
            'approval', 'Approvals', 'Something needs your approval',
            'A record reached a stage of an approval route that is assigned to you.',
            'urgent', true, [true, true, false],
        ],
        'approval.decided' => [
            'approval', 'Approvals', 'A decision was made',
            'Somebody approved, rejected or asked for changes on a record you own.',
            'high', true, [true, true, false],
        ],
        'approval.overdue' => [
            'approval', 'Approvals', 'An approval is overdue',
            'An approval assigned to you has passed the time allowed for it.',
            'urgent', false, [true, true, false],
        ],
        // Not mandatory: it goes to administrators, who may reasonably want it in-app only. It IS
        // urgent, because a stalled route is a record that has silently stopped moving.
        'workflow.stalled' => [
            'workflow', 'Approvals', 'An approval route stalled',
            'A stage of an approval route resolved to nobody, so the record has stopped.',
            'urgent', false, [true, true, false],
        ],

        // --- documents ------------------------------------------------------------------------
        'document.approved' => [
            'document', 'Documents', 'A document was approved',
            'A document you own or follow completed its approval route.',
            'high', false, [true, true, false],
        ],
        'document.revision_requested' => [
            'document', 'Documents', 'Changes were requested',
            'A reviewer asked for changes to a document you own.',
            'high', true, [true, true, false],
        ],

        // --- transmittals ---------------------------------------------------------------------
        'transmittal.issued' => [
            'transmittal', 'Documents', 'A transmittal was issued',
            'Documents were formally issued outside the company on a transmittal you are named on.',
            'normal', false, [true, true, false],
        ],

        // --- engineering ------------------------------------------------------------------------
        //
        // All three are the same shape: somebody has been waiting for an answer and the answer has
        // arrived. All three are mandatory, and the test in the class docblock is what makes them
        // so — an engineer who muted "your query was answered" and then built to a superseded
        // assumption for a fortnight would fairly say the system never told them. None is urgent:
        // these arrive during a working day and eight hours does not change the outcome, whereas
        // an approval sitting unseen does.
        'rfi.answered' => [
            'rfi', 'Engineering', 'A query you raised was answered',
            'Somebody answered a technical query or RFI you raised or are following.',
            'high', true, [true, true, false],
        ],
        'submittal.reviewed' => [
            'submittal', 'Engineering', 'A package you submitted came back',
            'A submittal you sent for review has been coded.',
            'high', true, [true, true, false],
        ],
        'change_request.decided' => [
            'change_request', 'Engineering', 'A change request was decided',
            'A change to scope on your project was approved, rejected, deferred or withdrawn.',
            'high', true, [true, true, false],
        ],

        // ------------------------------------------------------------------ HSE
        //
        // THE MOST MANDATORY BLOCK IN THE CATALOGUE, and it is worth saying why in one place rather
        // than five times below. Everywhere else in this application, a person who has decided they
        // do not want to hear about something is entitled to that decision. Here they are not:
        // "somebody was hurt" and "your permit expires in an hour" are not preferences, and a
        // company whose safety alerts can be silenced by the individuals they are aimed at has a
        // notification system, not a safety system.
        //
        // WHAT THEY STILL OBEY IS AUTHORIZATION. Every one of these dispatches with a
        // requiredAbility, so an incident notification reaches only people who could open the
        // incident, and NONE of them carries the injury, the diagnosis or the person's name in its
        // body. The alert says a serious incident was reported on a project and gives a link; what
        // happened to whom is behind `hse_incident.view_confidential` on the record itself (§68).
        'hse_incident.reported' => [
            'hse_incident', 'HSE', 'An incident was reported',
            'A safety incident was logged on a project you are responsible for.',
            'urgent', true, [true, true, true],
        ],
        'hse_incident.escalated' => [
            'hse_incident', 'HSE', 'An incident is reportable to the authorities',
            'An incident has been flagged as statutorily reportable, which usually carries a legal deadline.',
            'urgent', true, [true, true, true],
        ],
        // Not urgent, and deliberately so: an investigation is days of work, and paging somebody at
        // 23:00 to tell them they have been made the lead achieves nothing except training them to
        // ignore the channel that also carries the incident alerts above.
        'investigation.assigned' => [
            'hse_incident', 'HSE', 'You are leading an investigation',
            'You have been made the lead investigator on an incident.',
            'high', true, [true, true, false],
        ],
        'corrective_action.assigned' => [
            'corrective_action', 'HSE', 'A corrective action was assigned to you',
            'Somebody has given you an action arising from an incident, inspection, observation or NCR.',
            'high', true, [true, true, false],
        ],
        // The reminder that makes the register mean anything. An action nobody is reminded of is a
        // row in a table; this is what turns it into something that gets done.
        'corrective_action.overdue' => [
            'corrective_action', 'HSE', 'A corrective action is overdue',
            'An action assigned to you has passed its due date without being completed.',
            'high', true, [true, true, false],
        ],
        'corrective_action.verified' => [
            'corrective_action', 'HSE', 'Your corrective action was verified',
            'Somebody independently confirmed an action you completed, and recorded whether it worked.',
            'normal', false, [true, false, false],
        ],
        // Urgent and un-mutable, because it is the one alert in the module with a hard clock behind
        // it. Work continuing past `valid_to` is itself the finding — see Permit's docblock.
        'permit.expiring' => [
            'permit', 'HSE', 'A permit to work is about to expire',
            'A permit you requested or accepted reaches the end of its validity shortly.',
            'urgent', true, [true, true, true],
        ],
        'permit.issued' => [
            'permit', 'HSE', 'Your permit to work was issued',
            'A permit you requested has been authorised and is waiting for you to accept it.',
            'high', true, [true, true, false],
        ],
        'permit.suspended' => [
            'permit', 'HSE', 'A permit to work was suspended',
            'Work under a permit you requested, issued or accepted has been stopped.',
            'urgent', true, [true, true, true],
        ],
        'hse_training.expiring' => [
            'hse_training', 'HSE', 'A certificate is about to expire',
            'A training certificate or competence card is approaching its expiry date.',
            'normal', false, [true, true, false],
        ],

        // ------------------------------------------------------------------ quality
        'quality_inspection.requested' => [
            'quality_inspection', 'Quality', 'An inspection was requested',
            'Somebody has asked for a hold point or witness point to be inspected.',
            'high', true, [true, true, false],
        ],
        'quality_inspection.completed' => [
            'quality_inspection', 'Quality', 'An inspection you requested was carried out',
            'The inspection you requested has been attended and a result recorded.',
            'high', true, [true, true, false],
        ],
        'ncr.issued' => [
            'ncr', 'Quality', 'A non-conformance was raised',
            'Work you are responsible for has been recorded as not meeting the specification.',
            'high', true, [true, true, false],
        ],
        'ncr.dispositioned' => [
            'ncr', 'Quality', 'A non-conformance was dispositioned',
            'An engineering decision was recorded on an NCR you raised or responded to.',
            'high', true, [true, true, false],
        ],

        // --- procurement --------------------------------------------------------------------
        // WHAT IS WAITING ON SOMEBODY IS MANDATORY; what merely happened is not. An approver who
        // muted "a requisition is waiting for you" and then held up a site for a fortnight would
        // fairly say the system never told them. An enquiry going out to suppliers is news.
        //
        // NO PRICE IS EVER IN A BODY. A requisition's estimate, a quotation's total and an order's
        // value are all on records with their own confidentiality, and the bell reaches people the
        // subject line has to be safe for even though the dispatcher filters on `view` (§68).
        'purchase_request.submitted' => [
            'purchase_request', 'Procurement', 'A requisition is waiting for you',
            'Somebody submitted a purchase requisition that needs a decision.',
            'high', true, [true, true, false],
        ],
        'purchase_request.decided' => [
            'purchase_request', 'Procurement', 'Your requisition was answered',
            'A requisition you raised was approved, returned or rejected.',
            'high', true, [true, true, false],
        ],
        'rfq.issued' => [
            'rfq', 'Procurement', 'An enquiry went out',
            'A request for quotation was issued to suppliers on work you follow.',
            'normal', false, [true, false, false],
        ],
        'rfq.awarded' => [
            'rfq', 'Procurement', 'An enquiry was awarded',
            'A supplier was chosen on an enquiry you raised or are following.',
            'high', true, [true, true, false],
        ],
        'purchase_order.submitted' => [
            'purchase_order', 'Procurement', 'An order is waiting for your approval',
            'A purchase order was submitted and needs to be approved before it can be issued.',
            'high', true, [true, true, false],
        ],
        'purchase_order.decided' => [
            'purchase_order', 'Procurement', 'An order you raised was answered',
            'A purchase order you raised was approved, returned or rejected.',
            'high', true, [true, true, false],
        ],
        'purchase_order.issued' => [
            'purchase_order', 'Procurement', 'An order reached the supplier',
            'A purchase order was issued. The commitment is now live.',
            'normal', false, [true, false, false],
        ],
        'goods_receipt.received' => [
            'goods_receipt', 'Procurement', 'Goods arrived against your order',
            'A delivery was booked in against an order you raised or approved.',
            'normal', false, [true, false, false],
        ],
        'goods_receipt.disputed' => [
            'goods_receipt', 'Procurement', 'A delivery is disputed',
            'Goods arrived wrong, short or damaged and the supplier has to be taken up on it.',
            'high', true, [true, true, false],
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
