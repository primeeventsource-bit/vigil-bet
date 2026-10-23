<?php

declare(strict_types=1);

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
