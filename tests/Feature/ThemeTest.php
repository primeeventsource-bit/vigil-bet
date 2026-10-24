<?php

declare(strict_types=1);

use App\Models\User;

/*
|--------------------------------------------------------------------------
| The dark/light switch
|--------------------------------------------------------------------------
|
| The palette itself is covered by BrandingTest, which asserts that every
| token reaches the page from config and that no template hardcodes a hex.
| What is left to prove is the switch: that it is reachable, that it does
| not flash the wrong theme on load, and that light mode is built from
| tokens rather than from colours typed into the stylesheet.
|
*/

it('offers the switch before sign-in as well as after', function () {
    $this->get(route('home'))->assertOk()->assertSee('data-theme-toggle', escape: false);

    $user = User::forceCreate([
        'name' => 'Sam',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get(route('today'))->assertOk()
        ->assertSee('data-theme-toggle', escape: false);
});

it('applies a stored theme before the first paint', function () {
    /*
     * The script has to be inline and above the stylesheet. Setting the
     * attribute from the bundle instead leaves a visible flash of the
     * wrong theme on every load, because a deferred module runs after the
     * browser has already painted.
     */
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain("localStorage.getItem('vigil.theme')")
        ->and($html)->toContain("setAttribute('data-theme'");

    /*
     * The ordering is asserted against the template rather than the
     * rendered page, because the suite renders without Vite and so the
     * stylesheet link is never emitted. The template is where the
     * invariant actually lives: the script has to sit above @vite.
     */
    $head = (string) file_get_contents(resource_path('views/partials/head.blade.php'));

    $script = mb_strpos($head, "localStorage.getItem('vigil.theme')");
    $styles = mb_strpos($head, '@vite');

    expect($script)->not->toBeFalse()
        ->and($styles)->not->toBeFalse()
        ->and($script)->toBeLessThan($styles);
});

it('survives a browser that refuses local storage', function () {
    // A private window with site data blocked throws on read rather than
    // returning null, and a colour preference is not worth a blank page.
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('catch (e) {}');
});

it('builds light mode from tokens rather than from hardcoded colour', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // One literal remains by necessity: the checkmark inside a data-URI,
    // which cannot reference a custom property. Everything else is a token.
    preg_match_all('/#[0-9a-fA-F]{6}\b/', $css, $matches);

    expect($matches[0])->toBe([]);

    expect($css)->toContain('--vg-navy: var(--vg-light-panel)')
        ->and($css)->toContain('--vg-slate: var(--vg-light-muted)')
        // The system preference is honoured until somebody pins a mode,
        // and an explicit dark choice beats a system set to light.
        ->and($css)->toContain('@media (prefers-color-scheme: light)')
        ->and($css)->toContain("html:not([data-theme='dark'])");
});

it('keeps a light value for every colour the dark theme uses', function () {
    /*
     * A token that exists in dark and not in light is a colour that
     * silently keeps its dark value on a white page - which is how a
     * light mode ends up with one unreadable element nobody notices.
     */
    $colors = config('brand.colors');

    $needsLight = ['edge', 'loss', 'warning', 'blue', 'decor', 'chart_line', 'chart_fill', 'chart_grid'];

    foreach ($needsLight as $token) {
        expect($colors)->toHaveKey($token)
            ->and($colors)->toHaveKey('light_'.$token);
    }
});

it('keeps the theme overrides out of every cascade layer', function () {
    /*
     * The regression this exists for: light mode was dead code.
     *
     * The palette is written as a :root block in an inline <style> in the
     * head, so config/brand.php stays the only place colour crosses from
     * PHP into CSS. That block is unlayered. Everything in app.css sits
     * inside @layer, and in the cascade an unlayered declaration beats a
     * layered one whatever its specificity - so :root won over
     * html[data-theme='light'] and the light values never applied.
     *
     * Nothing noticed for the whole life of the product, because no
     * switch existed to reveal it. The first time one did, light mode
     * turned out never to have worked. A specificity test would not have
     * caught it; only the layer position does.
     */
    $css = (string) file_get_contents(resource_path('css/app.css'));

    $needle = "html[data-theme='light']";
    $at = mb_strpos($css, $needle);

    expect($at)->not->toBeFalse();

    // Walk the file to the selector, tracking how deep inside @layer we
    // are. Anything above zero means the override is outranked.
    $depth = 0;
    $layerDepth = 0;
    $inLayer = [];

    for ($i = 0; $i < $at; $i++) {
        if ($css[$i] === '{') {
            $open = mb_substr($css, max(0, $i - 80), min(80, $i));
            $isLayer = (bool) preg_match('/@layer[^;{}]*$/', $open);
            $inLayer[++$depth] = $isLayer;

            if ($isLayer) {
                $layerDepth++;
            }
        }

        if ($css[$i] === '}') {
            if (($inLayer[$depth] ?? false) === true) {
                $layerDepth--;
            }
            unset($inLayer[$depth]);
            $depth--;
        }
    }

    expect($layerDepth)->toBe(0);
});
