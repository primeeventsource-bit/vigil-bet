<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    /*
     * MaxMind, for the approximate location recorded against a trial
     * activation. Optional in the strongest sense: without a key the
     * databases are never downloaded, Geo returns nulls, and every
     * other part of the product behaves identically.
     */
    'maxmind' => [
        /*
         * Two names, because the environment already had one of them.
         *
         * MaxMind call this a licence key and their download endpoint
         * takes it as `license_key`, so that is the name this config
         * prefers. But the production environment was set up with
         * MAXMIND_API_KEY, and a key that exists under a name nothing
         * reads is the quietest kind of broken - the feature simply
         * never works and nothing anywhere says why.
         *
         * edge:doctor already carries a whole routine for diagnosing
         * exactly this, which is a good sign it happens often enough
         * to be worth accepting both spellings rather than insisting
         * on one.
         */
        'license_key' => env('MAXMIND_LICENSE_KEY') ?: env('MAXMIND_API_KEY', ''),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
