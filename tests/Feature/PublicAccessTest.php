<?php

declare(strict_types=1);

use App\Services\Entitlements\Entitlements;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The public site stays public
|--------------------------------------------------------------------------
|
| "No anonymous access" in the trial spec means no anonymous access to
| the product. It does not mean the marketing site, the pricing page or
| the public record go behind a login, and this file is what stops that
| happening by accident on some later afternoon.
|
| The record in particular is the one claim this product makes about
| itself that is worth anything, and the About page says in those words
| that it is open to anybody without an account. A change that quietly
| closed it would make that page a lie before it made anything else
| worse.
|
| This became worth testing the moment `free` stopped being a permissive
| baseline. Before that, a signed-out visitor resolved to a tier with
| real grants and most of these pages would have kept working by
| accident. Now a guest is granted nothing, so if any of them ever grows
| an entitlement check it fails here rather than in production.
|
*/

it('serves every public page to somebody with no account', function (string $route) {
    $this->get(route($route))->assertOk();
})->with([
    'home',
    'pricing',
    'how-it-works',
    'opinions',
    'about',
    'features',
    'sports-analytics',
    'legal.terms',
    'legal.privacy',
    'legal.cancellation',
    'legal.disclaimer',
    'legal.responsible-gambling',
]);

it('resolves a signed-out visitor to a tier that grants nothing', function () {
    $entitlements = app(Entitlements::class);

    expect($entitlements->tier(null))->toBe('guest')
        ->and($entitlements->grantsFor('guest'))->toBe([]);

    // And every feature key refuses, rather than any of them being
    // unanswered. An unanswered ability is not a refusal.
    foreach ($entitlements->allFeatures() as $feature) {
        expect($entitlements->can(null, $feature))->toBeFalse("a guest was granted {$feature}");
    }
});

it('keeps entitlement checks out of the marketing views entirely', function () {
    /*
     * The structural guarantee behind all of the above, and the reason
     * `guest` can grant nothing without breaking a page.
     *
     * Asserted against the files rather than by fetching pages, because
     * a check added to a view that happens to render identically for a
     * guest today would pass a page test and fail the day somebody
     * changed a grant.
     */
    $offenders = collect(File::allFiles(resource_path('views/marketing')))
        ->merge(File::allFiles(resource_path('views/components/marketing')))
        ->filter(function ($file) {
            $contents = File::get($file->getPathname());

            return preg_match('/@can\b|@cannot\b|Gate::(allows|denies|check)/', $contents) === 1;
        })
        ->map(fn ($file) => $file->getFilename())
        ->values();

    expect($offenders->all())->toBe([]);
});

it('shows the picks feed on the homepage without an account', function () {
    // The homepage renders today's published opinions and the public
    // record. Neither asks what tier is reading it, and that is what
    // makes the record a record rather than a sales page.
    $this->get(route('home'))->assertOk()
        ->assertSee('The data speaks.');
});

it('offers the trial to a stranger by sending them to register', function () {
    // "Account registration required" is the first line under the
    // button, so the button has to lead somewhere a stranger can go.
    $this->get(route('pricing'))->assertOk()
        ->assertSee('START FREE '.config('plans.trial_hours').'-HOUR TRIAL', escape: false)
        ->assertSee(route('register'), escape: false);
});

it('states the four promises under the trial button', function () {
    $page = $this->get(route('pricing'))->assertOk()->getContent();

    foreach ([
        'Account registration required.',
        'No credit card required.',
        'Trial expires automatically after '.config('plans.trial_hours').' hours.',
        'No automatic charges.',
    ] as $promise) {
        expect($page)->toContain($promise);
    }
});

it('shows four plans, and does not advertise a state as a plan', function () {
    $page = $this->get(route('pricing'))->assertOk()->getContent();

    // The trial and the three sold tiers.
    foreach (['Free trial', 'Vigil Core', 'Vigil Pro', 'Vigil Elite'] as $name) {
        expect($page)->toContain($name);
    }

    // `guest` and `expired` are states the application resolves people
    // into, not things anybody chooses.
    expect($page)->not->toContain('Not started')
        ->and($page)->not->toContain('Trial ended');
});
