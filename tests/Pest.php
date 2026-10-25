<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Feature tests render real Blade views and the layouts call @vite. The
 * compiled manifest is a build artifact that is not committed, so tests
 * depending on it would fail on a clean checkout. Assets are a build
 * concern: CI proves the front end compiles in its own step, and the suite
 * stays runnable straight after a clone.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

require_once __DIR__.'/Fixtures/edgelab.php';
require_once __DIR__.'/Fixtures/payments.php';
require_once __DIR__.'/Fixtures/ai.php';
require_once __DIR__.'/Fixtures/bets.php';
require_once __DIR__.'/Fixtures/parlays.php';
require_once __DIR__.'/Fixtures/live.php';
require_once __DIR__.'/Fixtures/teams.php';
require_once __DIR__.'/Fixtures/internal.php';
require_once __DIR__.'/Fixtures/shadow.php';
require_once __DIR__.'/Fixtures/opinions.php';
require_once __DIR__.'/Fixtures/nflverse.php';
