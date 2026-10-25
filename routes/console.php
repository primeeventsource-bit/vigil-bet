<?php

declare(strict_types=1);

use App\Enums\Sport;
use App\Jobs\Bets\RecordBetClv;
use App\Jobs\Bets\SettleBets;
use App\Jobs\EdgeLab\SyncBoard;
use App\Jobs\EdgeLab\SyncEvents;
use App\Jobs\EdgeLab\SyncModels;
use App\Jobs\Ingest\SyncInjuries;
use App\Jobs\Ingest\SyncProbableStarters;
use App\Jobs\Ingest\SyncResults;
use App\Jobs\Ingest\SyncSchedules;
use App\Jobs\Ingest\SyncWeather;
use App\Jobs\Modeling\PredictUpcoming;
use App\Jobs\Modeling\TrainModel;
use App\Jobs\Odds\MarkClosingLines;
use App\Jobs\Odds\PullOddsForSport;
use App\Jobs\Odds\PullPropsForSport;
use App\Jobs\Ops\ReapStaleJobRuns;
use App\Jobs\Value\ScanCrossBook;
use App\Jobs\Value\ScanValue;
use App\Services\Odds\PullCadence;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Everything queues a job rather than working in the scheduler process, so
| a slow or dead Edge Lab cannot block the tick, and every run lands in
| sync_runs whether it succeeds or fails.
|
| The cadences follow what each feed upstream actually does. Pulling more
| often than the engine rescans spends Edge Lab's rate limit to re-read
| rows that have not changed, and the digest means those writes would
| produce nothing anyway.
|
*/

// Edge Lab rescans the board hourly at :45. This runs after it, not with
// it, so a sync is reading a finished scan rather than one in progress.
Schedule::job(new SyncBoard)
    ->hourlyAt(55)
    ->name('edgelab-sync-board')
    ->withoutOverlapping();

// The schedule barely moves. Twice a day is enough to catch a postponement.
Schedule::job(new SyncEvents)
    ->twiceDaily(7, 19)
    ->name('edgelab-sync-events')
    ->withoutOverlapping();

/*
 * Verdicts change only when a model is retrained, which is weekly. Pulled
 * daily anyway: this feed decides what Vigil is allowed to sell, and being
 * a day late withdrawing a verdict is worse than the cost of the request.
 */
Schedule::job(new SyncModels)
    ->dailyAt('05:30')
    ->name('edgelab-sync-models')
    ->withoutOverlapping();

/*
 * Dunning's deadline. Daily, early, before anybody is likely to be
 * looking: a customer should not watch a tier disappear mid-session.
 *
 * The retries themselves are the processor's; this only closes out the
 * ones whose window has run out, which has to be checked on a clock
 * because a processor that gives up quietly sends nothing to react to.
 */
Schedule::command('vigil:dunning')
    ->dailyAt('06:10')
    ->name('billing-dunning')
    ->withoutOverlapping();

/*
 * Grading. Hourly, because a game finishing at 22:30 should be settled
 * before the customer looks in the morning - and because the cost of
 * running it on nothing is a single query.
 *
 * It only ever settles from a mirrored final score, so running it before
 * Edge Lab has the result is harmless: those bets stay pending.
 */
Schedule::command('vigil:settle-bets')
    ->hourlyAt(5)
    ->name('settle-bets')
    ->withoutOverlapping();

/*
 * The public record. Publishing runs just after the board sync, so a
 * pick is published from a board that was scanned minutes ago rather
 * than an hour ago - and settling runs in the same pass, because a
 * result that arrives at 23:00 should be on the record before anybody
 * looks in the morning.
 *
 * Safe to re-run: one pick per board entry and one result per pick, both
 * enforced in the database. A record that can be padded by running a
 * command twice is not a record.
 */
Schedule::command('vigil:publish-picks')
    ->hourlyAt(58)
    ->name('publish-picks')
    ->withoutOverlapping();

/*
 * The shadow record, just after the real publisher.
 *
 * Same cadence and the same clock, so a shadow row and a published row
 * see the same board - the experiment is only worth anything if it is
 * recording the selections the engine actually made at the time, rather
 * than a later board that already knows more.
 */
Schedule::command('vigil:shadow')
    ->hourlyAt(59)
    ->name('shadow-record')
    ->withoutOverlapping();
/*
 * Alerts. Just after publishing, because the thing most worth being told
 * about is a pick that has only just gone on the record - and a
 * notification that arrives an hour after the price was available is
 * worth less than no notification at all.
 *
 * Safe to re-run: an alert is unique per subject and a delivery is
 * unique per customer and channel. The failure this guards against is
 * not a missing notification; it is the same push eleven times.
 */
Schedule::command('vigil:alerts')
    ->hourlyAt(2)
    ->name('alerts')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| The engine
|--------------------------------------------------------------------------
|
| Ingestion, pricing and training. Everything below used to run in a
| second deployment and reach this one over HTTP; it now runs here,
| against the same database, which is why the sync jobs above no longer
| leave the building.
|
| The odds tick runs every minute but each sport decides for itself
| whether it is actually due - see PullCadence. That is what keeps a
| monthly API quota from being spent on games that are days away.
|
*/

foreach (Sport::enabled() as $sport) {
    Schedule::job(new PullOddsForSport($sport))
        ->everyMinute()
        ->name("odds-pull-{$sport->value}")
        ->withoutOverlapping()
        /*
         * Due-checked here rather than inside the job. The job still
         * checks for itself - two processes deciding the same thing a
         * second apart can disagree - but the common answer is no, and
         * queueing eight thousand jobs a day to hear it put every pull a
         * quarter of an hour behind, in the window the closing line
         * comes from.
         */
        ->when(fn (): bool => app(PullCadence::class)->isDue($sport));

    /*
     * Player props, on their own looser clock. One call per game rather
     * than one per league, so the tick is frequent and PullCadence decides
     * per sport whether anything is actually due - the same arrangement as
     * the board above, with a cadence an order of magnitude cheaper.
     *
     * A sport with no prop markets configured, or props switched off,
     * records a skip and spends nothing.
     */
    Schedule::job(new PullPropsForSport($sport))
        ->everyFiveMinutes()
        ->name("odds-props-{$sport->value}")
        ->withoutOverlapping()
        ->when(fn (): bool => app(PullCadence::class)->isDueForProps($sport));

    Schedule::job(new SyncSchedules($sport))
        ->twiceDaily(6, 18)
        ->name("schedules-{$sport->value}")
        ->withoutOverlapping();

    Schedule::job(new SyncResults($sport))
        ->hourly()
        ->name("results-{$sport->value}")
        ->withoutOverlapping();

    // Availability moves on practice reports and warmups, not
    // continuously, and one request covers a whole league. What matters is
    // having a stamped record of what was known before kickoff.
    Schedule::job(new SyncInjuries($sport))
        ->cron('0 */4 * * *')
        ->name("injuries-{$sport->value}")
        ->withoutOverlapping();

    // Price the next few days with the active model. A prediction is only
    // worth writing while the game is still ahead of it, so this runs often
    // rather than waiting for a nightly pass; one prediction per game per
    // model version means a repeat pass over the same slate writes nothing.
    Schedule::job(new PredictUpcoming($sport))
        ->hourlyAt(40)
        ->name("predict-{$sport->value}")
        ->withoutOverlapping();
}

/*
 * Probable starting pitchers. MLB only: the NHL publishes nothing
 * equivalent for starting goalies, so there is no second half to run.
 */
Schedule::job(new SyncProbableStarters)
    ->cron('15 */4 * * *')
    ->name('probable-starters')
    ->withoutOverlapping();

/*
 * Forecasts for kickoff at outdoor venues. Every run writes a new row: a
 * forecast three days out and one three hours out are different facts, and
 * the gap between them is the error a model would have to live with.
 */
Schedule::job(new SyncWeather)
    ->cron('30 */6 * * *')
    ->name('weather')
    ->withoutOverlapping();

// Freeze closing lines shortly after games start.
Schedule::job(new MarkClosingLines)
    ->hourly()
    ->name('mark-closing-lines')
    ->withoutOverlapping();

// Grade bets whose games have finished. Offset past the top of the hour so
// the results pull above has usually landed before this reads it; a bet that
// misses one pass is picked up by the next, since neither job uses a window.
Schedule::job(new SettleBets)
    ->hourlyAt(20)
    /*
     * Named for the engine's own bets, not shared with the customer bet
     * grader at :05. Both were called `settle-bets`, which is two
     * different jobs under one name in `schedule:list` - and a mutex
     * collision waiting for one of them to be rewritten as the other
     * kind, since a callback event keys its overlap lock on exactly this
     * string.
     */
    ->name('settle-engine-bets')
    ->withoutOverlapping();

// Sweep the board. Runs after the odds pull and the prediction pass, since
// it compares one against the other and is only as fresh as its prices.
Schedule::job(new ScanValue)
    ->hourlyAt(45)
    ->name('scan-value')
    ->withoutOverlapping();

// Stamp closing line value. Runs after the closing lines it reads are frozen.
Schedule::job(new RecordBetClv)
    ->hourlyAt(25)
    ->name('record-bet-clv')
    ->withoutOverlapping();

/*
 * Look across books for arbitrages and middles. Runs on the odds cadence
 * rather than the scan cadence because it reads nothing but prices: there
 * is no model in it, and a disagreement between two books is worth
 * nothing once it is an hour old.
 */
Schedule::job(new ScanCrossBook)
    ->everyFiveMinutes()
    ->name('scan-crossbook')
    ->withoutOverlapping();

/*
 * Close out runs whose worker never came back.
 *
 * Runs last in this file because it reads what everything above it wrote.
 * A row stuck on "running" is the one failure the Jobs screen cannot see
 * through - it reads as work in progress - so something has to notice that
 * nothing came back.
 */
Schedule::job(new ReapStaleJobRuns)
    ->everyFifteenMinutes()
    ->name('reap-stale-runs')
    ->withoutOverlapping();

/*
 * Retrain weekly, so a verdict can change its mind.
 *
 * Every other job here keeps the data current and nothing kept the models
 * current. A market that reports "230 of 300 graded predictions needed"
 * is waiting on games that arrive daily, and without this it would go on
 * reporting that number for as long as the app ran: the closing lines
 * would accumulate, the evidence would arrive, and nobody would look at
 * it again.
 *
 * Weekly rather than nightly. A rating moves a little with each game and
 * a verdict moves hardly at all, so a nightly retrain would spend an hour
 * of CPU to write a new version row that says the same thing, and would
 * bury the runs that mattered under six that did not.
 *
 * Sunday at 4am: after Saturday's college slate has settled and graded,
 * and before anything is priced on Sunday.
 */
foreach (Sport::enabled() as $sport) {
    Schedule::job(new TrainModel($sport))
        ->weeklyOn(0, '04:00')
        ->name("retrain-{$sport->value}")
        ->withoutOverlapping();
}

/*
 * Box scores for games that have just finished.
 *
 * Kept deliberately modest: the per-game sources cost one request per
 * game, and this runs to catch up with last night rather than to
 * backfill history. A deep backfill is `ingest:box-scores <sport>
 * --limit=` run by hand, which is the right shape for something that
 * makes thousands of requests.
 *
 * Nightly rather than hourly because a box score is final once the game
 * is, and asking again an hour later only costs requests. Late enough to
 * be after the last whistle on the west coast, and the command asks only
 * for games it has nothing for, so a run with nothing to do is one
 * query.
 */
Schedule::command('ingest:box-scores all --limit=120')
    ->dailyAt('09:30')
    ->name('box-scores')
    ->withoutOverlapping();

/*
 * ESPN ids for events ingested from another source.
 *
 * A box score cannot attach to an event until that event carries an id
 * the box score source recognises, so every sport whose schedule comes
 * from one place and whose box scores come from ESPN needs this run for
 * it. A new fixture arrives without an ESPN id every week.
 *
 * Derived from the source roles rather than named, because naming them
 * went wrong: this said `nfl` and "only the NFL needs this today", which
 * was true when written. Then MLB's team_stats role moved to ESPN and
 * nothing here changed - 2,241 of 3,435 finished MLB games had no ESPN
 * id, so the nightly box score run had nothing to ask about and the hole
 * showed up as no error anywhere, just absent stats.
 *
 * Before the box scores, so a game linked this morning has its stats
 * pulled in the same cycle rather than the next one.
 */
foreach (Sport::cases() as $sport) {
    $sources = config("sports.{$sport->value}.sources", []);

    // A sport whose schedule is already ESPN's carries ESPN's ids, and a
    // sport whose box scores come from elsewhere does not need them.
    if (($sources['team_stats'] ?? null) !== 'espn' || ($sources['schedules'] ?? null) === 'espn') {
        continue;
    }

    Schedule::command("ingest:link-espn {$sport->value}")
        ->dailyAt('09:15')
        ->name("link-espn-{$sport->value}")
        ->withoutOverlapping();
}

/*
 * Vigil Reads, including for the passes.
 *
 * --passes is the whole point of running this at all right now. No
 * market is proven, so there are no sellable picks to explain, and
 * without it this command has nothing to do while the only thing a
 * customer can be shown is the analysis on a near-miss.
 *
 * After the scan and the research pulls, so a Read is written against
 * the board and the availability that exist now rather than an hour ago.
 *
 * Reads are stored per (entry, prompt version) and the command skips
 * entries that have one, so a run with nothing new is cheap. The limit
 * is a cost ceiling rather than a target: each Read is an AI call.
 */
Schedule::command('vigil:reads --passes --limit=40')
    ->hourlyAt(12)
    ->name('vigil-reads')
    ->withoutOverlapping();

/*
 * The day's opinions, just before the picks publisher.
 *
 * Two published claims, two cadences on the same clock. This writes the
 * weaker one - the model's probabilities have been measured to land
 * close to the truth, so here is what it thinks - and vigil:publish-picks
 * writes the public record, only ever from a proven market.
 *
 * Earlier than the picks run so that on the day a market does clear the
 * gate, the pick is the newer row and the two cannot disagree about a
 * price within the same minute.
 *
 * Safe to re-run: the database refuses a second opinion on the same
 * selection, so an hourly pass republishes nothing.
 */
Schedule::command('vigil:publish-opinions')
    ->hourlyAt(56)
    ->name('publish-opinions')
    ->withoutOverlapping();

/*
 * Grade yesterday's opinions.
 *
 * Hourly rather than daily because games finish all day, and an opinion
 * that stays ungraded while its game is over makes the accuracy figure
 * quietly stale - the published count moves and the graded count does
 * not.
 *
 * After the results pull, so the final score it needs has usually
 * landed. An opinion with no score yet is left alone rather than
 * guessed at, so running early costs nothing but a pass.
 */
Schedule::command('vigil:settle-opinions')
    ->hourlyAt(20)
    ->name('settle-opinions')
    ->withoutOverlapping();

/*
 * Write down what the prop model thinks, before the games.
 *
 * The only route by which player props could ever become publishable.
 * A model cannot be assessed retrospectively - the thing being tested
 * is whether it knew beforehand - so the record has to accrue while the
 * model is still not good enough to show anybody, and it starts now
 * rather than when somebody remembers.
 *
 * Nothing it writes reaches a customer. The `odds_snapshots` schema
 * forbids a prop becoming a prediction or a pick, and that has not
 * changed.
 *
 * Hourly because lines move and a player priced at 7.5 rebounds this
 * afternoon may be at 8.5 by evening, and those are two different
 * selections both worth having an opinion on. Safe to re-run: the
 * database refuses a second projection on the same selection from the
 * same model, so a repeated pass records nothing.
 */
Schedule::command('edge:props:project --record --limit=0')
    ->hourlyAt(38)
    ->name('record-prop-projections')
    ->withoutOverlapping();

/*
 * Mark them once the box scores land.
 *
 * After the box score pull rather than before it, since that is where
 * the numbers it grades against come from. A projection whose game has
 * no final box score yet is left alone rather than guessed at, so
 * running early costs a pass and nothing else.
 */
Schedule::command('edge:props:settle')
    ->dailyAt('10:15')
    ->name('settle-prop-projections')
    ->withoutOverlapping();
