<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Alert;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * An alert, by email.
 *
 * The subject and body come from the dispatcher, so the email and the
 * push notification say the same thing - a customer with both channels on
 * should not receive two differently worded accounts of one event.
 *
 * Every figure comes from the payload stored when the alert was raised.
 * Nothing is looked up at send time: an email built from a live read
 * could quote a price that has since moved, which is the one thing a
 * price alert must never do.
 */
class AlertNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Alert $alert) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dispatcher = app(AlertDispatcher::class);
        $payload = $this->alert->payload;

        $mail = (new MailMessage)
            ->subject($dispatcher->title($this->alert))
            ->greeting('Hello')
            ->line($dispatcher->body($this->alert));

        if ($this->alert->type === Alert::PICK_POSTED) {
            /*
             * The gate, in the email. A customer forwarding this to a
             * friend should be forwarding the caveat too, and a pick
             * quoted without it reads like a tip.
             */
            $mail->line('This comes from a market that has beaten the closing line over a measured sample. '
                .'It is not a prediction that it will win.');
        }

        if (isset($payload['kickoff'])) {
            $mail->line('Kickoff: '.Carbon::parse($payload['kickoff'])
                ->toDayDateTimeString().' UTC.');
        }

        return $mail
            ->action('Open '.config('brand.name'), $payload['url'] ?? route('today'))
            ->line('You can change or stop these in Alerts.')
            ->salutation(config('vigil.responsible_gambling_line'));
    }
}
