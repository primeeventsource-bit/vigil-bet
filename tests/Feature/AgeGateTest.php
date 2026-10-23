<?php

declare(strict_types=1);

use App\Models\AgeAttestation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function signupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Sam Reader',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'birth_date' => now()->subYears(30)->toDateString(),
        'age_attestation' => '1',
        'terms' => '1',
    ], $overrides);
}

it('creates an account for somebody old enough', function () {
    $this->post(route('register'), signupPayload())
        ->assertRedirect(route('verification.notice'));

    expect(User::where('email', 'sam@example.com')->exists())->toBeTrue();
});

it('turns away somebody under the minimum age', function () {
    $this->post(route('register'), signupPayload([
        'birth_date' => now()->subYears(18)->toDateString(),
    ]))->assertSessionHasErrors('birth_date');

    expect(User::count())->toBe(0);
});

it('refuses somebody one day short of the birthday', function () {
    // The conservative boundary. A gate that rounded the other way would
    // admit people up to a year under the limit.
    $this->post(route('register'), signupPayload([
        'birth_date' => now()->subYears(config('vigil.minimum_age'))->addDay()->toDateString(),
    ]))->assertSessionHasErrors('birth_date');

    expect(User::count())->toBe(0);
});

it('admits somebody exactly on their birthday', function () {
    $this->post(route('register'), signupPayload([
        'birth_date' => now()->subYears(config('vigil.minimum_age'))->toDateString(),
    ]))->assertSessionHasNoErrors();

    expect(User::count())->toBe(1);
});

it('stores the birth year and never the date of birth', function () {
    $this->post(route('register'), signupPayload([
        'birth_date' => '1990-07-14',
    ]));

    $attestation = AgeAttestation::firstOrFail();

    expect($attestation->birth_year)->toBe(1990)
        ->and($attestation->passed)->toBeTrue()
        ->and($attestation->attested)->toBeTrue()
        ->and($attestation->minimum_age)->toBe(config('vigil.minimum_age'));

    // The month and the day must not survive anywhere on the row. A full
    // date of birth is identity-document material we have no use for.
    $columns = array_keys((array) DB::table('age_attestations')->first());

    expect($columns)->not->toContain('birth_date')
        ->and($columns)->not->toContain('date_of_birth');

    foreach ((array) DB::table('age_attestations')->first() as $value) {
        expect((string) $value)->not->toContain('07-14');
    }
});

it('records a refusal so the gate leaves evidence it ran', function () {
    $this->post(route('register'), signupPayload([
        'birth_date' => now()->subYears(16)->toDateString(),
    ]));

    $refusal = AgeAttestation::firstOrFail();

    expect($refusal->passed)->toBeFalse()
        ->and($refusal->user_id)->toBeNull()
        ->and(User::count())->toBe(0);
});

it('needs the attestation box as well as the arithmetic', function () {
    $this->post(route('register'), signupPayload(['age_attestation' => null]))
        ->assertSessionHasErrors('age_attestation');

    expect(User::count())->toBe(0);
});

it('needs the terms accepted', function () {
    $this->post(route('register'), signupPayload(['terms' => null]))
        ->assertSessionHasErrors('terms');

    expect(User::count())->toBe(0);
});

it('shows the minimum age on the form rather than hardcoding it', function () {
    config()->set('vigil.minimum_age', 25);

    $this->get(route('register'))
        ->assertSee('25+ only')
        ->assertSee('at least 25 years old');
});
