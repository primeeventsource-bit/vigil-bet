<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Alerts
|--------------------------------------------------------------------------
|
| Email and web push, for new picks and for line moves on watched games.
|
| Both channels default to off per customer and both are unconfigured by
| default here. That combination is deliberate: nothing is sent until
| somebody asks for it and somebody has set up the means to send it, and
| a channel that is not configured says so rather than failing quietly.
|
*/

return [

    /*
     * Web push identity. Generate a pair with `php artisan vigil:vapid`
     * and put them in the environment - the private key must never be
     * committed and never reaches the database.
     *
     * The subject is a mailto: or https: URL that push services use to
     * contact whoever is sending. A real address, because a blocked
     * sender with no contact route stays blocked.
     */
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', env('BRAND_SUPPORT_EMAIL')
            ? 'mailto:'.env('BRAND_SUPPORT_EMAIL')
            : null),
    ],

    /*
     * How many alerts one customer may be sent in a day, per channel.
     *
     * A ceiling rather than a target. The failure mode for a product like
     * this is not a missed notification - it is eleven of them in an
     * afternoon, which gets the application uninstalled and the sending
     * domain blocked.
     */
    'daily_cap' => [
        'email' => (int) env('ALERTS_DAILY_EMAIL_CAP', 10),
        'push' => (int) env('ALERTS_DAILY_PUSH_CAP', 20),
    ],

    /*
     * An alert older than this is never delivered. A notification about
     * a game that has already started is worse than silence, and a queue
     * that was stuck overnight must not empty itself into somebody's
     * phone in the morning.
     */
    'max_age_minutes' => (int) env('ALERTS_MAX_AGE_MINUTES', 180),
];
