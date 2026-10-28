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
        // Light is reached one way: by asking for it. The attribute is
        // the whole of the switch.
        ->and($css)->toContain("html[data-theme='light']");
});

it('is dark for a visitor who has chosen nothing', function () {
    /*
     * The regression this exists for: the stylesheet followed
     * prefers-color-scheme, so anybody whose machine was set to light met
     * a white page. The design this product was drawn from has no white
     * page in it, and a first impression decided by an operating system
     * setting is not a first impression anybody chose.
     */
    $css = (string) file_get_contents(resource_path('css/app.css'));

    /*
     * The rule, not the word. The comment above the light block explains
     * why the media query went, and a test that forbade the phrase
     * outright would forbid the explanation with it.
     */
    expect($css)->not->toMatch('/@media\s*\(\s*prefers-color-scheme/');

    // And nothing writes the attribute unless the stored value is light.
    $head = (string) file_get_contents(resource_path('views/partials/head.blade.php'));

    expect($head)->toContain("localStorage.getItem('vigil.theme') === 'light'");
});

it('offers two modes rather than a third that does the same as one of them', function () {
    /*
     * "System" was a real choice while the media query existed. With dark
     * as the default it would mean exactly what dark means, and a control
     * offering both is lying about one of them.
     */
    $js = (string) file_get_contents(resource_path('js/theme.js'));
    $toggle = (string) file_get_contents(resource_path('views/components/theme-toggle.blade.php'));

    expect($js)->toContain("const ORDER = ['dark', 'light']")
        ->and($js)->not->toContain("'system'")
        ->and($toggle)->not->toContain('data-theme-icon="system"');
});

it('keeps a light value for every colour the dark theme uses', function () {
    /*
     * A token that exists in dark and not in light is a colour that
     * silently keeps its dark value on a white page - which is how a
     * light mode ends up with one unreadable element nobody notices.
     */
    $colors = config('brand.colors');

    // `blue` became `link` when the palette went black, silver and
    // money green - the electric blue is gone entirely, and `emerald`
    // arrived as its own token because it cannot carry text.
    $needsLight = ['edge', 'loss', 'warning', 'link', 'emerald', 'decor', 'chart_line', 'chart_fill', 'chart_grid'];

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

it('paints the paginator from tokens rather than from the operating system', function () {
    /*
     * Laravel's shipped Tailwind paginator is hardcoded bg-white and
     * text-gray-800, with its dark variants behind `dark:` - which
     * compiles to @media (prefers-color-scheme: dark).
     *
     * That agreed with the page while the whole stylesheet followed the
     * same media query. With dark as the default it would put white
     * buttons on a black page for everybody whose machine is set to
     * light, on all eight pages that paginate, the public record among
     * them. So both views are overridden.
     */
    foreach (['tailwind', 'simple-tailwind'] as $view) {
        $path = resource_path('views/vendor/pagination/'.$view.'.blade.php');

        expect(file_exists($path))->toBeTrue("pagination/{$view} is not overridden");

        $markup = (string) file_get_contents($path);

        /*
         * The classes, not the prose. These files have to explain in a
         * comment what they are overriding and why, and that comment
         * necessarily names the utilities - a test reading the whole
         * file would forbid its own reason for existing.
         */
        preg_match_all('/class="([^"]*)"/', $markup, $found);
        $classes = implode(' ', $found[1]);

        expect($classes)->not->toContain('dark:')
            ->and($classes)->not->toContain('bg-white')
            ->and($classes)->toContain('var(--vg-line)');
    }
});

it('renders those overrides on a page that actually paginates', function () {
    // The override only counts if Laravel resolves it, which depends on
    // the file sitting at the name the framework asks for.
    $html = $this->get(route('opinions'))->assertOk()->getContent();

    expect($html)->not->toContain('dark:bg-gray-800');
});

it('assigns a light value for every token that carries text', function () {
    /*
     * The companion to the test above, and the gap it left.
     *
     * That one checks config/brand.php holds a `light_*` key for each
     * colour. This checks the light map in app.css actually *assigns*
     * it - which is a different question, and the one that went wrong:
     * `light_muted` existed and was even used, as the source for
     * `--vg-slate`, while `--vg-muted` itself was never reassigned at
     * all. So it kept its dark #87918B and sat on a white panel at
     * 3.25:1, below AA, on every light page since light mode began
     * working.
     *
     * A key existing is not the same as a key being wired up. Only the
     * second one is what a reader sees.
     */
    $css = (string) file_get_contents(resource_path('css/app.css'));

    $start = mb_strpos($css, "html[data-theme='light'] {");
    expect($start)->not->toBeFalse();

    $map = mb_substr($css, $start, (int) mb_strpos($css, "\n}", $start) - $start);

    /*
     * Every token that is ever used as a text colour. Backgrounds and
     * decoration are not here: `emerald` carries no text by design, and
     * is checked for a light value by the test above rather than for an
     * assignment here.
     */
    $carriesText = ['paper', 'slate', 'muted', 'decor', 'green', 'edge', 'link', 'loss', 'warning'];

    $missing = array_values(array_filter(
        $carriesText,
        fn (string $token): bool => ! str_contains($map, '--vg-'.$token.':'),
    ));

    expect($missing)->toBe([],
        'The light map never reassigns: '.implode(', ', $missing)
        .'. Those tokens keep their dark value on a white page.');
});
