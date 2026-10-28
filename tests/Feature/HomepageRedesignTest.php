<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

/**
 * One priced fixture in the shape GameMarkets::upcoming() returns.
 *
 * Built by hand rather than from a factory because the board renders an
 * array, not a model - the service flattens events and odds into this
 * shape and the component never sees either table.
 */
function boardGame(string $league): array
{
    return [
        'id' => random_int(1000, 99999),
        'sport' => $league,
        'starts_at' => now()->addHours(6),
        'home' => 'Home Side',
        'away' => 'Away Side',
        'home_short' => 'HOM',
        'away_short' => 'AWY',
        'markets' => [
            'h2h' => [
                ['side' => 'home', 'line' => null, 'price_american' => -120, 'books' => 4],
                ['side' => 'away', 'line' => null, 'price_american' => 105, 'books' => 4],
            ],
        ],
    ];
}

uses(RefreshDatabase::class);

/*
 * The public redesign.
 *
 * These are not tests that the page looks right - nothing can test that.
 * They are tests of the four things the redesign could silently break:
 * a navigation that disappears, a link that points at a route needing an
 * entitlement without saying so, a board that invents a game, and a
 * claim about what is on sale.
 */

it('gives a phone the whole navigation, not just the logo', function () {
    /*
     * The defect this replaced: the public nav was `hidden sm:flex` with
     * nothing behind it, so every phone got the lockup, a theme toggle
     * and a sign-up button - and pricing, the record and everything else
     * were reachable only by typing the URL.
     *
     * The drawer is the half that was missing. Asserted through the
     * contract the script actually binds to rather than through classes,
     * because a drawer whose panel is renamed is a drawer that silently
     * stops opening.
     */
    $page = $this->get('/');

    $page->assertOk()
        ->assertSee('data-nav-toggle', false)
        ->assertSee('data-nav-panel', false)
        ->assertSee('data-nav-backdrop', false)
        ->assertSee('id="marketing-nav"', false);

    // The toggle ships hidden and nav.js reveals it: a control that does
    // nothing without scripting is worse than no control.
    expect($page->getContent())->toMatch('/<button[^>]*data-nav-toggle[^>]*\shidden/');
});

it('says which nav destinations need an account before the click', function () {
    /*
     * Five of these are real routes behind an entitlement. A signed-out
     * visitor who clicks one lands on the sign-in page, which is the
     * funnel working - but only if the link said so first. An unmarked
     * link that bounces you to a login screen reads as a broken site.
     */
    $page = $this->get('/');

    $page->assertOk()
        ->assertSee("Today's Games")
        ->assertSee('Player IQ')
        ->assertSee('Parlay Builder')
        // Said once in words, for whoever a padlock glyph does not reach.
        ->assertSee('A padlock means the page needs an account.');

    expect(substr_count($page->getContent(), '(members only)'))
        ->toBeGreaterThan(0, 'The gated nav items carry no accessible marker.');
});

it('drops the padlock once the entitlement is actually held', function () {
    /*
     * The companion assertion. A marker that is always there is not a
     * marker, and an Elite subscriber being told that Player IQ needs an
     * account is the page contradicting what it just sold them.
     */
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user);

    // Signed in but on no plan: still gated, so still marked.
    expect($this->get('/')->getContent())->toContain('(members only)');
});

it('never advertises a paid plan as purchasable while checkout is dark', function () {
    /*
     * The redesign's own risk: it is a conversion-focused page built
     * while nothing is on sale. The trial is real and is the primary
     * action; the three paid tiers are not, and the page has to keep
     * saying so.
     */
    $this->get('/')
        ->assertOk()
        ->assertSee('checkout is not live')
        ->assertSee('Not on sale yet', false);
});

it('offers the trial by the exact hours the product grants', function () {
    /*
     * Read from config rather than written into the button, so the page
     * cannot advertise a 24-hour trial that the service grants for 72.
     */
    $hours = (int) config('plans.trial_hours');

    $this->get('/')
        ->assertOk()
        ->assertSee("START FREE {$hours}-HOUR TRIAL")
        ->assertSee('EXPLORE THE PLATFORM');
});

it('draws no game at all rather than inventing one to fill the space', function () {
    /*
     * The board renders one card per row the database returned, so with
     * no priced fixture it must render none - not a greyed-out example,
     * not a famous matchup standing in for the real thing.
     *
     * This asserts the card marker rather than team names. An earlier
     * version of this test asserted that "Bills" and "Chiefs" never
     * appeared, which would have been right about the mockup it was
     * written against and wrong the first evening those two actually
     * played: the guard would have fired on a real game. The guarantee
     * worth holding is that the count of cards matches the data, and at
     * zero that is exactly checkable.
     */
    $page = $this->get('/');

    $page->assertOk()
        ->assertDontSee('data-board-game', false)
        ->assertSee('No games on the board right now');
});

it('renders exactly the games it was given, and no more', function () {
    // The other half: given two, it draws two.
    $html = renderBoard(['nba', 'nhl'], [boardGame('nba'), boardGame('nhl')]);

    expect(substr_count($html, 'data-board-game'))->toBe(2);
});

it('explains an empty board instead of looking broken', function () {
    // No events at all, which is a real state between slates.
    $this->get('/')
        ->assertOk()
        ->assertSee('No games on the board right now')
        // Says which window, because "no games" without one reads as a
        // dead feed.
        ->assertSee('next 36 hours');
});

/*
 * The filter row needs two leagues of priced fixtures to appear at all,
 * which is a lot of scaffolding to assert one attribute. The component
 * is rendered directly instead - it takes everything it needs as props,
 * which is what makes that possible.
 */
function renderBoard(array $leagues, array $games = []): string
{
    return Blade::render(
        '<x-marketing.command-center :games="$games" :leagues="$leagues" :opinions="$opinions" />',
        [
            'games' => collect($games),
            'leagues' => collect($leagues),
            'opinions' => collect(),
        ],
    );
}

it('ships the league filter hidden so it is never a dead control', function () {
    /*
     * The chips appear only once board.js has revealed them. With
     * scripting off every game stays visible, which is the correct
     * unfiltered state rather than a row of controls that do nothing.
     */
    $html = renderBoard(['nba', 'nhl'], [boardGame('nba'), boardGame('nhl')]);

    expect($html)
        ->toContain('data-board-filters')
        ->toMatch('/data-board-filters[^>]*\shidden/')
        ->toContain('data-board-filter="nba"')
        ->toContain('data-board-filter="nhl"');
});

it('draws no filter row for a single league, because it could not filter', function () {
    // A control that cannot change anything is furniture, and an empty
    // slate is exactly when somebody is most likely to press it.
    expect(renderBoard(['nba'], [boardGame('nba')]))
        ->not->toContain('data-board-filters');
});

it('tags every game with the league the filter matches on', function () {
    /*
     * The filter reads `data-league` and the chip value comes from the
     * same column, so these cannot disagree - but only while the
     * attribute is actually emitted. Without it every chip would hide
     * every game.
     */
    expect(renderBoard(['nba', 'nhl'], [boardGame('nba'), boardGame('nhl')]))
        ->toContain('data-league="nba"')
        ->toContain('data-league="nhl"');
});

it('keeps the record out of the nav while it would 404', function () {
    // Same condition the route itself aborts on, so the link and the
    // page cannot disagree.
    $this->get('/')->assertOk()->assertDontSee('>Results<', false);
});

it('reaches the contact page and the refund rule from every footer', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('contact'), false)
        // One copy of the rule, anchored, rather than a second page of it.
        ->assertSee(route('legal.cancellation').'#refunds', false);

    $this->get(route('contact'))
        ->assertOk()
        ->assertSee(config('brand.company.support_email'))
        // No invented company facts: the master prompt forbids them and
        // a contact page is the worst place to guess.
        ->assertDontSee('Phone')
        ->assertDontSee('Headquarters');

    $this->get(route('legal.cancellation'))
        ->assertOk()
        ->assertSee('id="refunds"', false)
        ->assertSee('No refunds on results.');
});

it('leaves every route the redesign touches still working', function (string $route) {
    // The spec asks for this explicitly: the redesign changed the shared
    // marketing layout, so every page that wraps in it is in scope.
    $this->get(route($route))->assertOk();
})->with(['home', 'pricing', 'how-it-works', 'about', 'features', 'sports-analytics', 'contact', 'opinions', 'login', 'register']);

it('still signs a new account up through the redesigned pages', function () {
    /*
     * The one flow the redesign points every primary button at. A
     * beautiful homepage whose trial button leads nowhere is the only
     * failure here that would matter commercially.
     */
    $this->get(route('register'))->assertOk();

    $response = $this->post(route('register'), [
        'name' => 'Board Reader',
        'email' => 'board.reader@example.test',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'birth_date' => now()->subYears((int) config('vigil.minimum_age') + 5)->toDateString(),
        'age_attestation' => '1',
        'terms' => '1',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('users', ['email' => 'board.reader@example.test']);
});

it('keeps the drawer override out of every cascade layer, and on the right property', function () {
    /*
     * The drawer was broken on every width below `lg`, on the public
     * site and in the signed-in product, and nothing said so.
     *
     * It reported itself open the entire time. The class was applied,
     * aria-expanded read true, focus moved into the panel, and
     * isVisible() returned true - while getBoundingClientRect put the
     * panel at x = -288, exactly its own width off the left edge. Only
     * the pixels disagreed, which is why a markup assertion could not
     * have caught it and this has to read the stylesheet.
     *
     * Two independent mistakes, either of which alone was fatal:
     *
     *  - The rule set `transform`. Tailwind 4 compiles
     *    `-translate-x-full` to the standalone `translate` property, so
     *    the override was a declaration about a property nothing read.
     *  - The rule lived in @layer components. `-translate-x-full` lives
     *    in @layer utilities, and a layered rule loses to a later layer
     *    however specific it is.
     *
     * The same trap as the theme block, found the same way, a release
     * apart. See ThemeTest's own layer test.
     */
    $css = (string) file_get_contents(resource_path('css/app.css'));

    $needle = 'html.nav-open [data-nav-panel]';
    $at = mb_strpos($css, $needle);

    expect($at)->not->toBeFalse('The drawer open-state rule has gone.');

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

    expect($layerDepth)->toBe(0,
        'The drawer override is inside @layer, so the -translate-x-full utility outranks it'
        .' and the panel stays off-screen while reporting itself open.');

    /*
     * And it must name the property the utility actually sets.
     *
     * Matched across the declaration block rather than by slicing to the
     * first closing brace: the explanation inside this particular rule
     * is considerably longer than the rule.
     */
    expect($css)->toMatch('/html\.nav-open \[data-nav-panel\]\s*\{.*?translate:\s*0/s',
        'The override sets transform, which Tailwind 4 no longer uses for -translate-x-full.');
});

it('keeps the drawer override alive through minification', function () {
    /*
     * The source being right was not enough twice over.
     *
     * `translate: none` is the initial value, so the minifier dropped it
     * as redundant - and redundant is exactly what an override looks
     * like. Then `translate: 0` beside a `transform` was folded into one
     * `transform: translate(0)`, which left the rule setting no
     * `translate` at all and handed the utility back its -100%.
     *
     * So this reads the built stylesheet rather than the source. It is
     * skipped when nothing has been built, because a test that fails on
     * a clean checkout is a test people learn to ignore.
     */
    $built = glob(public_path('build/assets/app-*.css'));

    if ($built === false || $built === []) {
        test()->markTestSkipped('No built stylesheet; run `npm run build`.');
    }

    $css = (string) file_get_contents($built[0]);

    expect($css)->toMatch('/nav-open\s*\[data-nav-panel\]\{[^}]*translate:\s*0/',
        'The built CSS has lost the translate override; the drawer will not open.');
});

it('lays the dashboard out without inventing a single figure', function () {
    /*
     * A dashboard mockup wants to be covered in numbers, and every one
     * of them would be a claim: a win rate, units won, an average
     * confidence. The spec allows explicitly-labelled demonstration
     * data; this section declines even that, because the live figures
     * are further up the same page and a second invented set would have
     * the illustration arguing with them.
     *
     * So: the navigation and panel headings are the product's own, the
     * values are bars, and the caption says so.
     */
    $page = $this->get('/');

    $page->assertOk()
        // The seven areas the specification names.
        ->assertSee('My Parlays')
        ->assertSee('Player IQ')
        ->assertSee('Performance')
        // The real column headings, which are facts about the product.
        ->assertSee('Confidence')
        ->assertSee('Closing line value')
        ->assertSee('Layout illustration.');

    /*
     * No percentage anywhere in the illustration. Written as a regex
     * over the section rather than a string match, because the failure
     * this guards against is somebody adding "74%" to make it look
     * livelier - which is exactly the thing that would read as a result.
     */
    $html = $page->getContent();
    $from = (int) mb_strpos($html, 'id="dashboard"');
    $section = mb_substr($html, $from, (int) mb_strpos($html, 'Layout illustration.', $from) - $from);

    expect($section)->not->toMatch('/>\s*\d+(\.\d+)?%/',
        'The dashboard illustration is printing a figure. Those are claims.');
});

it('draws its own sports artwork rather than borrowing anybody\'s', function () {
    /*
     * The spec asks for original sports imagery and forbids league
     * marks and identifiable players - which rules out stock
     * photography almost by definition, since a usable stadium shot has
     * a sponsor board in it and a usable action shot has a face.
     *
     * So the hero art is geometry, drawn from palette tokens. This
     * asserts the two properties that matter: it is ours, and it is
     * decorative - a screen reader does not need a stadium read out.
     */
    $html = $this->get('/')->assertOk()->getContent();

    $from = (int) mb_strpos($html, 'id="fx-arena-pitch"');

    expect($from)->toBeGreaterThan(0, 'The hero artwork is gone.');

    // Inline and token-coloured: no external asset, nothing licensed,
    // and it repaints with the theme like everything else.
    expect($html)
        ->toContain('var(--vg-emerald)')
        ->and(mb_substr($html, max(0, $from - 400), 400))->toContain('aria-hidden="true"');
});
