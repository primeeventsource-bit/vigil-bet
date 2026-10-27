<?php

declare(strict_types=1);

// The record page is behind the verdict gate now, so a test that
// reads it has to put a market through first.
beforeEach(fn () => provenMarket());

use Illuminate\Support\Facades\File;

/*
 * The acceptance check from the spec: changing BRAND_NAME renames the
 * product everywhere visible. That is only true if nothing in resources/
 * ever writes the name or a hex value by hand, so this tests both the
 * behaviour and the rule that makes it possible.
 */

it('renames the product everywhere when the brand name changes', function (string $route) {
    config()->set('brand.name', 'Nightwatch');
    // The legal entity is a separate fact with its own variable - a
    // company does not rename itself because a product did - so it is set
    // here too, otherwise this test is really testing that one config
    // value leaks into another.
    config()->set('brand.company.legal_name', 'Nightwatch Analytics');

    $response = $this->get(route($route))->assertOk();

    /*
     * Visible copy, not markup. Asset filenames keep the old name on
     * purpose: they are pointed at by BRAND_MARK and friends, and a
     * rebrand should not require renaming files on disk before the
     * product reads correctly.
     */
    $text = strip_tags((string) $response->getContent());

    expect($text)->toContain('Nightwatch')
        ->and($text)->not->toContain('Vigil');
})->with(['home', 'pricing', 'record', 'login', 'register', 'legal.terms', 'legal.disclaimer']);

it('never hardcodes the product name in a template', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains(File::get($file->getPathname()), 'Vigil'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values();

    expect($offenders->all())->toBe([]);
});

it('never hardcodes a hex colour in a template', function () {
    // The palette crosses from PHP into CSS in exactly one place, the
    // head partial, which writes it from config('brand.colors').
    $offenders = collect(File::allFiles(resource_path('views')))
        ->reject(fn ($file) => $file->getRelativePathname() === 'partials'.DIRECTORY_SEPARATOR.'head.blade.php')
        ->filter(fn ($file) => preg_match('/#[0-9a-fA-F]{6}\b/', File::get($file->getPathname())) === 1)
        ->map(fn ($file) => $file->getRelativePathname())
        ->values();

    expect($offenders->all())->toBe([]);
});

it('writes the whole palette into the page from config', function () {
    $response = $this->get(route('home'))->assertOk();

    foreach (config('brand.colors') as $token => $hex) {
        $response->assertSee('--vg-'.str_replace('_', '-', $token).': '.$hex, escape: false);
    }
});

it('carries the tagline from config', function () {
    config()->set('brand.tagline', 'Keeps watch');

    $this->get(route('home'))->assertSee('Keeps watch');
});

it('paints the favicon in the palette the rest of the product reads', function () {
    /*
     * The sweep above only walks resources/views, and the favicon lives
     * in public/ - which is how it drifted. It kept the pre-Stealth-Black
     * #00E676 and #07131B through the whole repalette, so the browser tab
     * was the one surface still showing the old brand.
     *
     * It cannot use a custom property, because an SVG loaded as an icon
     * is its own document and inherits nothing. So the hexes stay typed
     * in, and this is what makes them answerable to config.
     */
    $svg = File::get(public_path(config('brand.logo.favicon')));

    expect($svg)->toContain('fill="'.config('brand.colors.edge').'"')
        ->and($svg)->toContain('fill="'.config('brand.colors.navy').'"');

    // And nothing else: a third colour would be a value with no owner.
    preg_match_all('/#[0-9A-Fa-f]{6}\b/', $svg, $found);

    expect(array_unique($found[0]))->toEqualCanonicalizing([
        config('brand.colors.edge'),
        config('brand.colors.navy'),
    ]);
});
