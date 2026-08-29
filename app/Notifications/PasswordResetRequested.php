<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The reset link (§68).
 *
 * Carries no information about the account beyond the recipient's own name and the company they
 * are resetting against — no role, no permissions, no colleagues, nothing that would be
 * interesting to whoever ends up reading a forwarded mailbox. The signed URL is the entire payload.
 *
 * Not queued: a person is staring at a "check your email" screen right now, and on cPanel the
 * queue worker is a cron tick that may be a minute away.
 */
class PasswordResetRequested extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly int $ttlMinutes,
    ) {}

    /** @return array<int,string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $url = URL::temporarySignedRoute(
            'password.reset',
            now()->addMinutes($this->ttlMinutes),
            ['user' => $notifiable->getKey(), 'token' => $this->token],
        );

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A password reset was requested for your account at '
                .($notifiable->organization?->name ?? 'your organization').'.')
            ->action('Choose a new password', $url)
            ->line('This link expires in '.$this->ttlMinutes.' minutes and can be used once.')
            ->line('If you did not request this, you can ignore this email — your password has not changed.');
    }
}
