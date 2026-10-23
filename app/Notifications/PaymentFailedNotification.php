<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A payment did not go through, and what happens next.
 *
 * §5 asks for an email at each of the three retries. The useful content
 * of such an email is the date access actually stops, so that is what it
 * leads with: a customer who reads "update your payment details" and
 * nothing else has no idea whether they have an hour or a fortnight.
 */
class PaymentFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $attempt,
        private readonly int $ofAttempts,
        private readonly ?string $accessUntil,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = (string) config('brand.name');
        $last = $this->attempt >= $this->ofAttempts;

        $mail = (new MailMessage)
            ->subject($last
                ? 'Last attempt on your '.$brand.' payment'
                : 'We could not take your '.$brand.' payment')
            ->greeting('Hello')
            ->line($last
                ? 'That was the last of '.$this->ofAttempts.' attempts to take your payment.'
                : 'Attempt '.$this->attempt.' of '.$this->ofAttempts.' to take your payment did not go through.');

        $mail->line($this->accessUntil === null
            ? 'Your account moves to the free plan if the next attempt also fails. Nothing is deleted — your bets, your record and your settings all stay.'
            : 'You keep everything until '.$this->accessUntil.'. After that the account moves to the free plan. Nothing is deleted — your bets, your record and your settings all stay.');

        return $mail
            ->action('Update your card', route('account'))
            ->line('If you meant to cancel, you can ignore this. Nothing further will be charged.')
            ->salutation(config('vigil.responsible_gambling_line'));
    }
}
