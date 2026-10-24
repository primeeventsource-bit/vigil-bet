<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Enums\Sport;
use App\Models\BankrollLedgerEntry;
use App\Models\BankrollSetting;
use App\Models\Book;
use App\Models\Event;
use App\Models\JobRun;
use App\Models\OddsSnapshot;
use App\Models\Team;
use App\Models\UnmatchedName;
use App\Services\Ingestion\ProvidesHistoricalLines;
use App\Services\Ingestion\SourceRegistry;
use App\Services\Odds\PullCadence;
use App\Services\Odds\TheOddsApiClient;
use App\Services\Ops\ApiMeter;
use App\Services\Teams\NameNormalizer;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A plain status readout: what is configured, what is stubbed, what is
 * failing, and how much API budget is left.
 */
class DoctorCommand extends Command
{
    protected $signature = 'edge:doctor';

    protected $description = 'Report data source availability, API budget, ingestion state and recent job failures';

    public function handle(SourceRegistry $registry, ApiMeter $meter, TheOddsApiClient $odds, PullCadence $cadence): int
    {
        $this->components->info('Edge Lab status');

        $this->line('<comment>Odds API</comment>');
        if ($odds->isConfigured()) {
            $remaining = $meter->remainingQuota(TheOddsApiClient::PROVIDER);
            $observed = $meter->observedQuota(TheOddsApiClient::PROVIDER);
            $configured = (int) config('edge.odds_api.monthly_quota');

            $this->line('  key: set');
            // Credits, not calls. A props call costs a credit per market
            // per region and a historical call costs ten, so counting
            // calls would read as a tenth of the truth on a backfill.
            $this->line(sprintf(
                '  credits used this month: %s (across %s call(s))',
                number_format($meter->creditsThisMonth(TheOddsApiClient::PROVIDER)),
                number_format($meter->requestsThisMonth(TheOddsApiClient::PROVIDER)),
            ));
            $this->line('  credits remaining (last reported): '
                .($remaining === null ? 'unknown, no successful call yet' : number_format($remaining)));
            $this->line('  spendable now, after the reserve: '.number_format($meter->spendableCredits(
                TheOddsApiClient::PROVIDER,
                $configured,
                (int) config('edge.odds_api.quota_reserve_pct'),
            )));

            // The plan size the provider reports beats the one in .env, so
            // say both when they disagree rather than quietly preferring
            // one. Getting this wrong is what silently disables pulls.
            if ($observed === null) {
                $this->line('  plan size: '.number_format($configured).' from config, unconfirmed until the first call');
            } elseif ($observed === $configured) {
                $this->line('  plan size: '.number_format($observed).', confirmed by the API');
            } else {
                $this->line('  <fg=yellow>plan size: '.number_format($observed).' per the API, but ODDS_API_MONTHLY_QUOTA says '
                    .number_format($configured).'</>');
                $this->line('  <fg=gray>  The API is believed. Set the variable to match so the two agree.</>');
            }

            if (! $odds->hasBudget()) {
                $this->line('  <fg=red>budget guard: pulls are STOPPED, the reserve has been reached</>');
            }
        } else {
            $this->line('  <fg=yellow>key: NOT set - odds pulls are disabled</>');
            $this->line('  <fg=gray>  Set ODDS_API_KEY, then run edge:odds:pull nfl --sync to make one call.</>');
            $this->line('  <fg=gray>  The plan size is read off that response, so the budget guard</>');
            $this->line('  <fg=gray>  stops at your real limit rather than the configured guess.</>');
        }

        $this->newLine();
        $this->line('<comment>Data sources</comment>');

        $rows = [];

        foreach (Sport::cases() as $sport) {
            $source = $registry->forRole($sport, 'schedules');

            $rows[] = [
                $sport->value,
                $sport->isEnabled() ? 'yes' : 'no',
                $source?->key() ?? 'none',
                $source === null ? '-' : $this->sourceState($source),
                $source?->reliability()->value ?? '-',
                $source instanceof ProvidesHistoricalLines ? 'yes' : 'no',
                Event::query()->where('sport', $sport->value)->count(),
            ];
        }

        $this->table(
            ['sport', 'enabled', 'source', 'state', 'reliability', 'line history', 'events'],
            $rows,
        );

        $this->reportNearMissKeys($registry);

        $this->line('<comment>Odds pull cadence</comment>');

        foreach (Sport::enabled() as $sport) {
            $interval = $cadence->intervalMinutes($sport);
            $next = $cadence->nextStartAt($sport);

            $this->line(sprintf(
                '  %-6s %s',
                $sport->value,
                $next === null
                    ? 'no upcoming games'
                    : sprintf('next game %s, pulling every %s min', $next->diffForHumans(), $interval ?? 'n/a'),
            ));
        }

        $this->newLine();
        $this->line('<comment>Player props</comment>');

        $propSports = Sport::withProps();

        if ($propSports === []) {
            $this->line('  <fg=gray>off, or no sport has prop markets configured</>');
        } else {
            foreach ($propSports as $sport) {
                $interval = $cadence->propsIntervalMinutes($sport);

                $this->line(sprintf(
                    '  %-6s %-52s %s',
                    $sport->value,
                    implode(', ', $sport->propMarkets()),
                    $interval === null ? 'nothing inside the horizon' : "every {$interval} min",
                ));
            }

            $this->line('  <fg=gray>Display and line shopping only. No prop row can become a pick.</>');
        }

        $this->newLine();
        $this->line('<comment>Data written</comment>');
        $this->line('  odds snapshots: '.OddsSnapshot::query()->coreMarkets()->count());
        $this->line('  prop snapshots: '.OddsSnapshot::query()->props()->count());
        $this->line('  backfilled from history: '.OddsSnapshot::query()->where('provenance', 'historical_api')->count());
        $this->line('  events: '.Event::query()->count());
        $this->line('  unmatched team names: '.UnmatchedName::query()->count());

        // Two of these have happened, both from treating a label as an
        // identity, and both were found by accident while chasing
        // something else. Counted here so the next one is noticed.
        $duplicateTeams = Team::query()
            ->get(['sport', 'display_name'])
            ->groupBy(fn (Team $team) => $team->sport->value.'|'.NameNormalizer::normalize($team->display_name))
            ->filter(fn ($group) => $group->count() > 1)
            ->count();

        if ($duplicateTeams > 0) {
            $this->line(sprintf(
                '  <fg=yellow>duplicate teams: %d name(s) held by more than one row. Run edge:teams:dedupe.</>',
                $duplicateTeams,
            ));
        }

        $this->newLine();
        $this->line('<comment>Where I can bet</comment>');

        $bettable = Book::query()->bettable()->orderByDesc('is_primary')->orderBy('display_order')->get();

        if ($bettable->isEmpty()) {
            $this->line('  <fg=yellow>No books marked bettable. Nothing can be recommended.</>');
        } else {
            foreach ($bettable as $book) {
                $this->line(sprintf('  %s%s', $book->title, $book->is_primary ? ' (default)' : ''));
            }

            $this->line(sprintf(
                '  %d other book(s) tracked for the fair price but not bettable.',
                Book::query()->tracked()->where('is_bettable', false)->count(),
            ));
        }

        $this->newLine();
        $this->line('<comment>Bankroll and staking</comment>');

        $settings = BankrollSetting::current();

        if ($settings === null) {
            $this->line('  <fg=yellow>No bankroll settings saved. Run php artisan db:seed.</>');
        } else {
            $bankroll = BankrollLedgerEntry::currentBalanceCents() ?: $settings->starting_bankroll_cents;
            $hasPctCap = $settings->hasPercentageCap();
            $pct = (float) $settings->max_stake_pct;
            $pctCap = $hasPctCap ? Money::pctOf($bankroll, $pct) : null;

            $this->line('  bankroll:    '.Money::format($bankroll));
            $this->line('  unit:        '.Money::format($settings->unit_size_cents));
            $this->line(sprintf(
                '  per bet:     %s to %s, %s',
                Money::format($settings->min_stake_cents),
                $settings->max_stake_cents ? Money::format($settings->max_stake_cents) : 'no ceiling',
                $hasPctCap
                    ? sprintf('and never more than %s (%s%% of bankroll)', Money::format($pctCap), $this->trimPct($pct))
                    : 'percentage cap off',
            ));
            $this->line('  kelly:       '.$settings->kelly_fraction.' of full');

            // With no ceiling of either kind, Kelly and the daily exposure
            // cap are all that size a bet. That is a deliberate setting and
            // not a fault, but it is worth saying out loud in a diagnostic.
            if (! $settings->hasStakeCeiling()) {
                $this->newLine();
                $this->line(sprintf(
                    '  <fg=yellow>No per-bet ceiling at all. A stake is limited only by fractional Kelly and by what the %s%% daily exposure cap has left.</>',
                    $this->trimPct((float) $settings->max_daily_exposure_pct),
                ));
            }

            // The two ways a stake range and a bankroll can disagree. Both
            // are survivable, both are worth knowing before a bet is placed.
            // Neither can arise without a percentage cap to disagree with.
            if ($hasPctCap && $settings->min_stake_cents > $pctCap) {
                $this->newLine();
                $this->line(sprintf(
                    '  <fg=yellow>Every bet will be passed: your %s minimum is above the %s cap this bankroll allows.</>',
                    Money::format($settings->min_stake_cents),
                    Money::format($pctCap),
                ));
                $this->line(sprintf(
                    '  <fg=yellow>A %s minimum needs about %s of bankroll at %s%%.</>',
                    Money::format($settings->min_stake_cents),
                    Money::format((int) ceil($settings->min_stake_cents / ($pct / 100))),
                    $this->trimPct($pct),
                ));
            } elseif ($hasPctCap && $settings->max_stake_cents && $settings->max_stake_cents > $pctCap) {
                $this->line(sprintf(
                    '  <fg=yellow>Your %s ceiling is out of reach: the percentage cap stops you at %s. It needs about %s of bankroll.</>',
                    Money::format($settings->max_stake_cents),
                    Money::format($pctCap),
                    Money::format((int) ceil($settings->max_stake_cents / ($pct / 100))),
                ));
            }
        }
        $failures = JobRun::query()
            ->where('status', JobStatus::Failed)
            ->where('started_at', '>=', now()->subDays(2))
            ->latestFirst()
            ->limit(5)
            ->get();

        $this->newLine();
        $this->line('<comment>Recent failures (48h)</comment>');

        if ($failures->isEmpty()) {
            $this->line('  none');
        } else {
            foreach ($failures as $failure) {
                $this->line(sprintf('  <fg=red>%s</> %s - %s', $failure->started_at?->toDateTimeString(), $failure->job_key, (string) $failure->error_message));
            }
        }

        return self::SUCCESS;
    }

    /**
     * What a source's state actually is, rather than whether a key exists.
     *
     * isAvailable() can only check that a key is set, which is all it can
     * cheaply do at call time - and "set" is not "works". A key for the
     * wrong service is set. The last answer the provider gave is already
     * in api_requests, costs nothing to read, and is the difference
     * between a screen that says ready and a sync that 401s.
     */
    private function sourceState(object $source): string
    {
        if (! $source->isAvailable()) {
            return 'missing key';
        }

        $lastStatus = DB::table('api_requests')
            ->where('provider', $source->key())
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->value('http_status');

        return match (true) {
            $lastStatus === null => 'ready',
            in_array((int) $lastStatus, [401, 403], true) => 'key rejected',
            (int) $lastStatus >= 400 => 'last call '.$lastStatus,
            default => 'ready',
        };
    }

    /**
     * Say when a key is in the environment under a name nothing reads.
     *
     * "Missing key" next to a variable the operator has plainly just set
     * is the most confusing thing this screen can print, and the two
     * strings differing by case is invisible from the outside. It has
     * happened once already: cfbd_key went in, CFBD_API_KEY was what the
     * code asked for, and on Linux those are different variables.
     *
     * This only looks at names, never at values, and it is deliberately
     * loose about what counts as similar - a false suggestion costs a line
     * of output, and a missed one costs an afternoon.
     */
    private function reportNearMissKeys(SourceRegistry $registry): void
    {
        $unavailable = collect(Sport::cases())
            ->map(fn (Sport $sport) => $registry->forRole($sport, 'schedules'))
            ->filter(fn ($source) => $source !== null && ! $source->isAvailable())
            ->map(fn ($source) => $source->key())
            ->unique();

        foreach ($unavailable as $sourceKey) {
            $stem = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($sourceKey));

            if ($stem === '') {
                continue;
            }

            // Only names that actually hold something. An empty
            // CFBD_API_KEY= is the variable the code reads sitting unset,
            // which is the ordinary "no key" case and not worth a word.
            // What is worth a word is a nearby name with a value in it.
            $candidates = collect($_ENV + $_SERVER)
                ->filter(fn ($value, $name) => is_string($name)
                    && is_string($value)
                    && trim($value) !== ''
                    && str_contains(mb_strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name)), $stem))
                ->keys();

            if ($candidates->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->line(sprintf(
                '  <fg=yellow>%s says missing key, but the environment has %s.</>',
                $sourceKey,
                $candidates->map(fn ($n) => '`'.$n.'`')->join(', ', ' and '),
            ));
            $this->line('  <fg=yellow>Variable names are case-sensitive here, so that is not the name being read.</>');
        }
    }

    /** 2.00 reads as 2, 2.50 as 2.5. A diagnostic should not be noisy. */
    private function trimPct(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 2), '0'), '.');
    }
}
