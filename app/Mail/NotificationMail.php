<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email copy of a notification (docs/10 §3).
 *
 * Carries strings, not models. Two reasons, and the second is the important one:
 *
 *   - A queued mailable serialises its constructor arguments. Passing a Memo would re-resolve it
 *     from the database when the worker picks the job up, through a global scope with no tenant
 *     bound — which fails closed, and the job dies rather than sending.
 *
 *   - Whatever this object holds is what gets sent. The decision about how much to say was made in
 *     NotificationDispatcher, where the recipient's authorization was in hand; by the time a job is
 *     on the queue that context is gone. Handing this class a model would invite a template to
 *     reach for a field nobody checked, hours later, on a different machine (§68).
 */
class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $heading,
        public readonly ?string $bodyText,
        public readonly ?string $actionUrl,
        public readonly ?string $actionLabel,
        public readonly string $organizationName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notification');
    }
}
