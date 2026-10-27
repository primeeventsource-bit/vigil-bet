<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Resend
|--------------------------------------------------------------------------
|
| Published to turn one thing off.
|
| The package registers POST /resend/webhook for inbound delivery and
| bounce events. Nothing here consumes those, and the route ships without
| authentication and outside CSRF - and its signature check only attaches
| when RESEND_WEBHOOK_SECRET is set, which it is not. So installing the
| package quietly added an unauthenticated write endpoint that would
| accept anything from anyone. AccessControlTest caught it in the same
| commit, which is exactly what that test is for.
|
| Default false rather than an environment variable set to false: an
| endpoint that appears because a variable was forgotten on one
| environment is the same bug with a longer fuse. Anybody who later wants
| Resend's events turns this on deliberately, and sets a webhook secret
| in the same breath.
|
*/

return [

    'api_key' => env('RESEND_API_KEY'),

    'routes' => env('RESEND_ROUTES', false),

    'domain' => env('RESEND_DOMAIN'),

    'path' => env('RESEND_PATH', 'resend'),

    'webhook' => [
        'secret' => env('RESEND_WEBHOOK_SECRET'),
        'tolerance' => env('RESEND_WEBHOOK_TOLERANCE', 300),
    ],

    'middleware' => [],

];
