<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\VlModel;
use App\Services\EdgeLab\Mirror;
use App\Services\Learning\LearningCenter;

/*
 * An admin: the Learning Center moved under /admin at step 11, which is
 * where §11 puts it. It was behind the ordinary login before only
 * because an admin area did not exist yet.
 */
function learner(): User
{
    return User::forceCreate([
        'name' => 'Ops', 'email' => 'ops@example.com',
        'password' => 'correct-horse-battery-staple', 'email_verified_at' => now(),
        'is_admin' => true,
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_confirmed_at' => now(),
    ]);
}

/** A model version as Edge Lab actually sends one. */
function modelVersion(int $id, array $overrides = []): VlModel
{
    $row = modelRow($id, $overrides['verdict'] ?? 'insufficient_data', $overrides);

    app(Mirror::class)->write(VlModel::class, $row, [
        'edge_lab_id' => $id,
        'sport' => $row['sport'],
        'market' => $row['market'],
        'model_type' => $row['model_type'],
        'version' => $row['version'],
        'status' => $row['status'],
        'verdict' => $row['verdict'],
        'verdict_reason' => $row['verdict_reason'],
        'allows_picks' => $row['verdict'] === 'proven',
        'predictions' => $row['predictions'],
        'log_loss_improvement' => $row['log_loss_improvement'],
        'gate' => $row['gate'],
        'trained_at' => now()->subDays(30 - $id),
        'observed_at' => now(),
    ]);

    return VlModel::query()->latest('id')->firstOrFail();
}

it('reports a market as improving when its versions close the gap', function () {
    // Still losing to the closing line, but by less each time. A verdict
    // alone would say "negative" twice and teach nobody anything.
    modelVersion(1, ['version' => 1, 'verdict' => 'negative', 'log_loss_improvement' => -0.051]);
    modelVersion(2, ['version' => 2, 'verdict' => 'negative', 'log_loss_improvement' => -0.034]);

    $trajectory = app(LearningCenter::class)->trajectories()->first();

    expect($trajectory['direction'])->toBe('improving')
        ->and($trajectory['current']['verdict'])->toBe('negative')
        ->and($trajectory['points'])->toHaveCount(2);
});

it('reports a market as regressing when a later version does worse', function () {
    modelVersion(1, ['version' => 1, 'verdict' => 'unproven', 'log_loss_improvement' => 0.074]);
    modelVersion(2, ['version' => 2, 'verdict' => 'negative', 'log_loss_improvement' => -0.028]);

    expect(app(LearningCenter::class)->trajectories()->first()['direction'])->toBe('regressing');
});

it('gives no direction when there is only one measurement', function () {
    modelVersion(1, ['version' => 1, 'log_loss_improvement' => -0.02]);

    // Saying "flat" would imply it had been measured twice and had not moved.
    expect(app(LearningCenter::class)->trajectories()->first()['direction'])->toBeNull();
});

it('gives no direction when nothing was measured against a closing line', function () {
    modelVersion(1, ['version' => 1, 'log_loss_improvement' => null]);
    modelVersion(2, ['version' => 2, 'log_loss_improvement' => null]);

    expect(app(LearningCenter::class)->trajectories()->first()['direction'])->toBeNull();
});

it('counts predictions once per market, not once per version', function () {
    // Four versions of one market are four passes over the same games.
    modelVersion(1, ['version' => 1, 'predictions' => 415]);
    modelVersion(2, ['version' => 2, 'predictions' => 415]);

    expect(app(LearningCenter::class)->headline()['predictions_measured'])->toBe(415);
});

it('says plainly that nothing is proven', function () {
    modelVersion(1, ['verdict' => 'negative']);

    $this->actingAs(learner())->get(route('admin.learning'))
        ->assertOk()
        ->assertSee('Nothing proven')
        ->assertSee('Markets proven')
        ->assertSee('0 of 1');
});

it('shows the gate each version was measured against', function () {
    modelVersion(1);

    $this->actingAs(learner())->get(route('admin.learning'))
        ->assertOk()
        ->assertSee('The gate')
        // Read from the stored gate, not restated in the template.
        ->assertSee('300')
        ->assertSee('1.64');
});

it('offers no control it cannot actually perform', function () {
    modelVersion(1);

    // Edge Lab's API is read-only by construction, so a pause or rollback
    // button here would either do nothing or need a write endpoint the
    // integration was built without.
    $this->actingAs(learner())->get(route('admin.learning'))
        ->assertOk()
        ->assertSee('Controls live in the engine')
        ->assertDontSee('Pause predictions')
        ->assertDontSee('Roll back');
});

it('keeps the learning center behind a login', function () {
    $this->get(route('admin.learning'))->assertRedirect(route('login'));

    // And a signed-in customer gets a 404 rather than a 403: probing
    // for /admin should look the same as asking for /nonsense.
    $customer = User::forceCreate([
        'name' => 'Customer', 'email' => 'customer@example.com',
        'password' => 'correct-horse-battery-staple', 'email_verified_at' => now(),
    ]);

    $this->actingAs($customer)->get(route('admin.learning'))->assertNotFound();
});
