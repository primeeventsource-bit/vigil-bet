<?php

declare(strict_types=1);

/*
 * The record page exists only once a market has passed the gate, so a
 * test that asks for it has to earn it first. Everything else here is
 * reachable whatever the engine has or has not proved.
 */
beforeEach(fn () => provenMarket());

it('serves every public page', function (string $route) {
    $this->get(route($route))->assertOk();
})->with(['home', 'pricing', 'how-it-works', 'record', 'login', 'register']);

it('serves every legal page', function (string $route) {
    $this->get(route($route))->assertOk();
})->with(['legal.terms', 'legal.privacy', 'legal.responsible-gambling', 'legal.disclaimer']);

it('refuses a legal page that does not exist', function () {
    $this->get('/legal/whatever')->assertNotFound();
});

it('puts the responsible gambling line on every public page', function (string $route) {
    $this->get(route($route))->assertSee(config('vigil.responsible_gambling_line'));
})->with(['home', 'pricing', 'how-it-works', 'record', 'login', 'register']);

it('lets search engines index the marketing pages', function (string $route) {
    $this->get(route($route))->assertDontSee('noindex', escape: false);
})->with(['home', 'pricing', 'how-it-works', 'record', 'legal.terms']);

/*
 * The application answers on more than one domain, and did so with
 * nothing on the page to say which name was the real one - so each host
 * confirmed itself and every indexable page existed three times over.
 */
it('names one canonical address whichever host asked for the page', function () {
    config()->set('app.url', 'https://pickvigil.com');

    $this->get('/pricing')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://pickvigil.com/pricing">', false);
});

it('points the canonical at the configured host, not the one in the request', function () {
    config()->set('app.url', 'https://pickvigil.com');

    // The whole point. A canonical built from the request host agrees
    // with whatever name was used and resolves nothing.
    $this->get('http://parlayhq.online/pricing')
        ->assertSee('<link rel="canonical" href="https://pickvigil.com/pricing">', false)
        ->assertDontSee('<link rel="canonical" href="http://parlayhq.online/pricing">', false);
});

it('gives the home page a canonical with no empty segment on the end', function () {
    config()->set('app.url', 'https://pickvigil.com');

    $this->get('/')->assertSee('<link rel="canonical" href="https://pickvigil.com/">', false);
});

/*
 * The two have to agree. A canonical naming one address while the card
 * shared into a group chat names another is the same page claiming two
 * homes, which is the problem this set out to fix.
 */
it('shares the same address with anything that links to the page', function () {
    config()->set('app.url', 'https://pickvigil.com');

    $this->get('http://parlayhq.online/how-it-works')
        ->assertSee('<link rel="canonical" href="https://pickvigil.com/how-it-works">', false)
        ->assertSee('<meta property="og:url" content="https://pickvigil.com/how-it-works">', false);
});
