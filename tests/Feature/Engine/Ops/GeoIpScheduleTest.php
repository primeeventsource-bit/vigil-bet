<?php

declare(strict_types=1);

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;

/*
 * The GeoLite2 databases do not survive a deploy.
 *
 * storage/app is container-local overlay. A push starts the container
 * from the built image, so the 57 MB `vigil:geoip-update` downloads is
 * gone the moment anything ships, and there is nothing in the deploy
 * itself that can fix that: the deploy command writes to a release
 * container that is discarded, not to the filesystem the application
 * later reads. The scheduler is the only writer whose files the running
 * product can see.
 *
 * So this job's interval is not MaxMind's publish cadence. It is the
 * blast radius of a deploy, and it was once a week - which is how
 * production came to serve the trial page for two days with no location
 * signal at all after a release that had nothing to do with geo.
 *
 * Nothing breaks when it is stale; Geo returns nulls and a trial still
 * activates. That is exactly why this needs a test. A signal that fails
 * silently and harmlessly is a signal nobody notices has been off.
 */
it('restores the geoip databases within an hour of a deploy', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'vigil:geoip-update'));

    expect($event)->not->toBeNull('vigil:geoip-update is not on the schedule at all.');

    /*
     * Read off the cron expression rather than trusting the builder
     * method that produced it, because the thing that matters is how
     * long the gap is, and `weeklyOn()` and `hourlyAt()` are the same
     * shape of call.
     */
    $cron = new CronExpression($event->expression);

    $minutes = (int) round((
        $cron->getNextRunDate('2026-01-01 00:00:00', 1)->getTimestamp()
        - $cron->getNextRunDate('2026-01-01 00:00:00')->getTimestamp()
    ) / 60);

    expect($minutes)->toBeLessThanOrEqual(60,
        'vigil:geoip-update runs every '.$minutes.' minutes ('.$event->expression.').'
        .' storage/app is wiped on every deploy, so this interval is how long'
        .' production runs with no location signal after a release.');
});
