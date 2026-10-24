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
    /*
    |--------------------------------------------------------------------------
    | The palette
    |--------------------------------------------------------------------------
    |
    | A trading terminal, not a casino. The field is matte black, the
    | panels are charcoal, and type is white over silver; colour appears
    | only where it carries meaning. Every pair below was measured rather
    | than chosen by eye - the lowest ink-on-surface contrast in either
    | mode is 4.67:1, above the 4.5 bar for body text.
    |
    | The surfaces sit close together on purpose: 1.12:1 between the page
    | and a panel. A panel should read as raised by a hair, not as a slab
    | dropped on the page, and the border is what actually separates them.
    |
    | These names are historical - `night`, `navy`, `slate_dark` - and are
    | left alone because they are the contract with every template and the
    | CSS token map. What each one means is below.
    |
    */
    'colors' => [
        // The page itself. Stealth Black's canvas.
        'night' => '#0A0A0A',
        // Panels. The surface the .panel class paints with.
        'navy' => '#111315',
        /*
         * The elevated surface, for a card sitting on a panel.
         *
         * Lighter than the panel rather than darker, which is the one
         * place this palette differs from the old one in direction and
         * not just in value: Stealth Black separates depth by lifting
         * a surface toward the light, so a tile on a panel reads as
         * nearer rather than as a hole cut into it.
         */
        'slate_dark' => '#1E1F22',
        // Borders, rules and the seams between panels.
        'line' => '#2D2F32',

        'green' => '#5FD39A',
        'blue' => '#6AA8E0',

        // Type. White for headings and primary text, silver beneath
        // it, and a third step for captions and table rules.
        'paper' => '#FFFFFF',
        'slate' => '#A7A7AB',
        'muted' => '#74777C',

        /*
         * Charts are drawn in white and silver, which is both the look and
         * a correction: they used to be drawn in `edge`, the green below,
         * so every bankroll line and every gauge was painted in the colour
         * this file reserves for a proven edge. Series now have their own
         * tokens and the semantic green went back to meaning one thing.
         */
        /*
         * Decoration: the hero glow, the engineering grid, the rising
         * trace behind the marketing hero. Silver, because none of it
         * means anything and the one colour that does must not also be
         * the wallpaper.
         */
        'decor' => '#A7A7AB',

        'chart_line' => '#FFFFFF',
        'chart_fill' => '#A7A7AB',
        'chart_grid' => '#2D2F32',

        /*
         * Semantic, and restrained to suit the field around them. Green is
         * reserved for a real edge; it never decorates. Every one of these
         * is paired with a word in the markup, so the product still reads
         * in greyscale and to the roughly one man in twelve who cannot
         * separate the green from the red.
         */
        'edge' => '#5FD39A',
        'loss' => '#E8736F',
        'warning' => '#D9A441',

        /*
         * Light mode, which is supported and is not the default. The same
         * terminal inverted: near-white page, white panels, the matte
         * black above as ink. The semantic hues are darkened, because a
         * colour legible on black is not legible on white.
         */
        'light_bg' => '#F7F7F8',
        'light_panel' => '#FFFFFF',
        'light_line' => '#E2E3E6',
        'light_text' => '#101113',
        'light_muted' => '#5E6269',
        'light_edge' => '#1A7F4B',
        'light_loss' => '#B4403C',
        'light_warning' => '#8A6212',
        'light_blue' => '#1F6FB8',
        'light_decor' => '#5E6269',
        'light_chart_line' => '#101113',
        'light_chart_fill' => '#5E6269',
        'light_chart_grid' => '#E2E3E6',
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
