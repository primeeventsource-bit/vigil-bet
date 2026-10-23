<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Models\PushSubscription as PushSubscriptionModel;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Real web push, over VAPID.
 *
 * The private key lives in the environment and never in the database. A
 * browser's own keys are stored, but they are useless to anybody who
 * does not also hold the VAPID private key - which is the whole security
 * model of web push and the reason this is safe to keep in Postgres.
 */
class WebPushSender implements PushSender
{
    public function configured(): bool
    {
        return filled(config('alerts.vapid.public_key'))
            && filled(config('alerts.vapid.private_key'))
            && filled(config('alerts.vapid.subject'));
    }

    public function send(PushSubscriptionModel $subscription, array $payload): ?string
    {
        if (! $this->configured()) {
            // Said plainly rather than returning success. A push layer
            // that silently sends nothing is how a feature appears to
            // work for a month.
            return 'not_configured';
        }

        try {
            $client = new WebPush(['VAPID' => [
                'subject' => (string) config('alerts.vapid.subject'),
                'publicKey' => (string) config('alerts.vapid.public_key'),
                'privateKey' => (string) config('alerts.vapid.private_key'),
            ]]);

            $report = $client->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->p256dh,
                        'auth' => $subscription->auth,
                    ],
                ]),
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            if ($report->isSuccess()) {
                return null;
            }

            /*
             * The status code matters: 404 and 410 mean the browser is
             * gone and retrying for ever would be pointless, while a 429
             * or a 500 is worth another go.
             */
            return 'http_'.$report->getResponse()?->getStatusCode().': '.$report->getReason();
        } catch (Throwable $e) {
            return 'exception: '.$e->getMessage();
        }
    }

    public function isGone(string $failure): bool
    {
        return str_contains($failure, 'http_404') || str_contains($failure, 'http_410');
    }
}
