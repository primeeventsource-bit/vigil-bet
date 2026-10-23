<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vigil
|--------------------------------------------------------------------------
|
| Product rules that are not branding and not billing. Everything here is
| a compliance decision rather than a preference, which is why it lives in
| config instead of being written into a form.
|
*/

return [

    /*
    | The age gate.
    |
    | 21 is the floor in most US states that permit sports betting at all.
    | We store the attested result and the birth *year*, never the full
    | date: the year is enough to show the gate was applied and to re-check
    | it if the threshold moves, and a full date of birth is identity
    | document material we have no use for and no business holding.
    */
    'minimum_age' => (int) env('VIGIL_MINIMUM_AGE', 21),

    /*
    | The line that has to appear on every pick and in every footer. Not
    | optional, and not a string a template is allowed to invent.
    */
    'responsible_gambling_line' => env(
        'VIGIL_RG_LINE',
        'If you or someone you know has a gambling problem, call 1-800-GAMBLER.',
    ),

    'helpline' => [
        'phone' => env('VIGIL_HELPLINE_PHONE', '1-800-GAMBLER'),
        'url' => env('VIGIL_HELPLINE_URL', 'https://www.1800gambler.net/'),
    ],

    /*
    | Self-exclusion periods, in days. A user can lock themselves out for
    | one of these and cannot undo it early - that is the entire point of
    | the feature, and an escape hatch would make it decorative.
    */
    'self_exclusion_days' => [7, 30, 90],

];
