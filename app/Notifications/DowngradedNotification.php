<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dunning has run out and the account is on the free plan.
 *
 * Says what was kept, in plain terms, because §5 keeps the data and a
 * customer has no way of knowing that unless they are told. The tone is
 * deliberately not a sales pitch: somebody whose card has just failed
 * three times is being told what happened, not sold to.
 */
class DowngradedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $fromTier) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = (string) config('brand.name');
        $name = str_replace(':brand', $brand, (string) config('plans.tiers.'.$this->fromTier.'.name', $this->fromTier));

        return (new MailMessage)
            ->subject('Your '.$brand.' account is on the free plan')
            ->greeting('Hello')
            ->line('We could not take payment for '.$name.', so the account has moved to the free plan.')
            ->line('Nothing has been deleted. Your logged bets, your record and your settings are all where you left them, and they come back the moment you subscribe again.')
            ->line('On the free plan you keep today\'s odds board, one '.$brand.' Read a day, the bet tracker and the parlay grader.')
            ->action('Start again', route('upgrade'))
            ->salutation(config('vigil.responsible_gambling_line'));
    }
}
