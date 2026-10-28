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
    | What the name stands for.
    |
    | Lives here rather than in a template for the same reason the name
    | does: it is spelled by the initials of the product's name, so a
    | rename makes it nonsense and whoever renames it needs to find this
    | in one place rather than in whichever views happened to use it.
    |
    | Set BRAND_EXPANSION to an empty string to drop it from the site
    | entirely - a backronym is the kind of thing a brand changes its
    | mind about, and that should not need a deploy of new markup.
    */
    'expansion' => env('BRAND_EXPANSION', 'Virtual Intelligence for Gambling Insights & Logic'),

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
        /*
         * Black, silver and money green.
         *
         * Six brand colours, each with one job. The three greys are not
         * interchangeable shades of the same idea - they are three
         * distances from the reader, and swapping them is how a caption
         * ends up louder than a heading.
         *
         * Every value here was checked against the two blacks before it
         * was written down. Deep Emerald is the one that cannot carry
         * text: 3.02 on Primary Black and 2.70 on Carbon, both under
         * the 4.5 that small type needs. It is a gradient and a surface,
         * never a word, and `emerald` exists as its own token so that
         * stays true by construction rather than by memory.
         */

        // Primary Black. The canvas.
        'night' => '#080A09',

        // Carbon Black. Panels - the surface .panel paints with.
        'navy' => '#151917',

        /*
         * The elevated surface, for a card sitting on a panel.
         *
         * Lighter than the panel rather than darker, because depth here
         * reads by lifting a surface toward the light: a tile on a panel
         * should look nearer than it, not like a hole cut into it.
         *
         * Derived rather than given - the brand has two blacks and this
         * is the step between Carbon and the first border.
         */
        'slate_dark' => '#1C211E',

        /*
         * Borders, rules and the seams between panels. Brushed silver at
         * the darkness a hairline needs: 1.47 against the canvas, which
         * is visible as an edge and invisible as a shape.
         */
        'line' => '#2A302C',

        /*
         * Money Green, and the one colour in this file that means
         * something. It marks a proven edge, a primary action and an
         * active page - and nothing else. The moment it decorates, it
         * stops being a signal and the thing it was signalling has
         * nowhere left to be said.
         *
         * 8.94 on the canvas, so it carries text as well as a button.
         */
        'green' => '#24C76A',
        'edge' => '#24C76A',

        /*
         * Deep Emerald. Gradients and premium surfaces only.
         *
         * Its own token because it fails text contrast on both blacks,
         * and a colour that cannot be read needs a name that says so
         * rather than sharing one with something that can.
         */
        'emerald' => '#116B3B',

        /*
         * Links, which used to be the electric blue this palette
         * removes.
         *
         * Platinum rather than green: green is reserved for an edge and
         * an action, and if every inline link were green the one green
         * thing that mattered on a page would be the twelfth. Links are
         * underlined as well as coloured, so they are findable without
         * relying on the colour at all.
         */
        'link' => '#E7EBE8',

        /*
         * Type, in three steps away from the reader.
         *
         * White for headings and anything that must be read first.
         * Platinum for body and secondary text, at 16.49 on the canvas -
         * the most readable thing in the palette after white itself.
         * Muted Gray for captions and sample sizes, at 6.10, which
         * clears AA while sitting clearly behind the two above it.
         */
        'paper' => '#FFFFFF',
        'slate' => '#E7EBE8',
        'muted' => '#87918B',

        /*
         * Brushed Silver: icons, rules, the hero grid, chart fills.
         * Decoration and data ink, never a semantic state. The one
         * colour that means something is above, and the wallpaper must
         * not be it.
         */
        'decor' => '#BFC5C2',

        'chart_line' => '#E7EBE8',
        'chart_fill' => '#BFC5C2',
        'chart_grid' => '#2A302C',

        /*
         * Semantic, and restrained to suit the field around them. Both
         * clear AA on the canvas - 5.28 and 9.24 - and both are paired
         * with a word in the markup, so the product still reads in
         * greyscale and to the roughly one man in twelve who cannot
         * separate the green from the red.
         */
        'loss' => '#EF4444',
        'warning' => '#F59E0B',

        /*
         * Light mode, which is supported and is not the default. The
         * same terminal inverted: near-white page, white panels, the
         * matte black above as ink. The semantic hues are darkened,
         * because a colour legible on black is not legible on white -
         * Money Green at #24C76A is 1.9 on white and unreadable.
         */
        'light_bg' => '#F4F6F5',
        'light_panel' => '#FFFFFF',
        'light_line' => '#DCE1DE',
        'light_text' => '#080A09',
        'light_muted' => '#5C645F',
        /*
         * The dimmest readable text on a light page.
         *
         * `muted` is a tier below `slate` in the dark palette - paper,
         * slate, decor, muted, brightest to dimmest - and the light map
         * had no value for it at all, so it kept the dark #87918B and
         * sat on white at 3.25:1. Every timestamp, every book count,
         * every step label, below AA on every light page, since light
         * mode began working.
         *
         * It cannot reuse `light_muted` above, which despite the name is
         * what `slate` resolves to here: that would collapse two tiers
         * into one and lose the hierarchy the dark palette has.
         *
         * 5.25:1 on a panel and 4.84:1 on the page background, and still
         * visibly dimmer than slate's 6.10. Checked by ThemeTest.
         */
        'light_dim' => '#666E69',
        'light_edge' => '#0F7A43',
        'light_loss' => '#B4403C',
        'light_warning' => '#8A6212',
        'light_emerald' => '#116B3B',
        'light_link' => '#0F5C33',
        'light_decor' => '#5C645F',
        'light_chart_line' => '#080A09',
        'light_chart_fill' => '#5C645F',
        'light_chart_grid' => '#DCE1DE',
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
