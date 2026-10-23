<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Brand
|--------------------------------------------------------------------------
|
| One place for the name, the tagline and the palette, so a rebrand is a
| config change rather than a search and replace through Blade. Nothing in
| resources/ may hardcode the product name or a hex value.
|
| The acceptance check this exists to satisfy: changing BRAND_NAME renames
| the product everywhere visible. There is a test for it.
|
| The palette is the Dark Terminal direction: a near-black navy field, a
| neon green that only ever means a real edge, and an electric blue for
| data and links. See docs/BRAND.md.
|
*/

return [

    'name' => env('BRAND_NAME', 'Vigil'),
    'short_name' => env('BRAND_SHORT_NAME', 'VG'),
    'tagline' => env('BRAND_TAGLINE', 'AI-Driven Edge'),

    /*
    | What the product actually does, in one sentence, for meta
    | descriptions and the OG card. Kept out of the tagline because a
    | tagline is a mood and this has to be literally true.
    */
    'description' => env(
        'BRAND_DESCRIPTION',
        'Model-driven sports betting analytics: real odds, measured edges, and an honest public record.',
    ),

    'logo' => [
        'mark' => env('BRAND_MARK', 'images/brand/vigil-mark.svg'),
        'wordmark' => env('BRAND_WORDMARK', 'images/brand/vigil-wordmark.svg'),
        'favicon' => env('BRAND_FAVICON', 'images/brand/vigil-favicon.svg'),
        'touch' => env('BRAND_TOUCH', 'images/brand/vigil-icon-180.png'),
        'og' => env('BRAND_OG', 'images/brand/vigil-og.svg'),
    ],

    /*
    | Every one of these is emitted as a CSS custom property by the layout,
    | so a colour changes in one place and the whole product follows.
    |
    | Colour is never the only signal. Every edge, pass, win and loss also
    | carries a label or an icon, because roughly one man in twelve cannot
    | tell --vg-green from --vg-loss, and because a screenshot in greyscale
    | should still be readable.
    */
    'colors' => [
        'night' => '#07131B',
        'navy' => '#0B1320',
        'slate_dark' => '#1E293B',
        'line' => '#24364A',

        'green' => '#00E676',
        'blue' => '#3882F6',

        'paper' => '#F8FAFC',
        'slate' => '#94A3B8',

        // Semantic. Green is reserved for a real edge; it never decorates.
        'edge' => '#00E676',
        'loss' => '#F2726F',
        'warning' => '#F5B83D',

        // Light mode, which is supported and is not the default.
        'light_bg' => '#F7F6F2',
        'light_text' => '#0B1320',
    ],

    /*
    | Contact and compliance details that have to appear in the footer and
    | in every marketing email. CAN-SPAM requires a real postal address on
    | commercial mail, so the mailer refuses to send without one rather
    | than quietly shipping a blank line.
    */
    'company' => [
        'legal_name' => env('BRAND_LEGAL_NAME', 'Vigil Analytics'),
        'support_email' => env('BRAND_SUPPORT_EMAIL', 'support@example.com'),
        'postal_address' => env('BRAND_POSTAL_ADDRESS'),
    ],

];
