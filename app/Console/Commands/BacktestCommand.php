<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Services\Modeling\BacktestService;
use App\Services\Modeling\TrainingSetBuilder;
use Illuminate\Console\Command;

/**
 * Runs the walk-forward backtest and prints the verdict in plain words.
 *
 * The output is deliberately blunt. If a market is not proven it says so and
 * says why, because the entire point of this command is to be the thing that
 * stops an unproven model reaching the board.
 */
class BacktestCommand extends Command
{
    protected $signature = 'edge:backtest
                            {sport : Sport key, or "all"}
                            {--market=h2h : h2h, spreads or totals}
                            {--tune : Sweep the K factor on early history and report only on later games}
                            {--burn-in=200 : Games replayed before scoring starts}
                            {--model=elo : elo or logistic}';

    protected $description = 'Walk-forward backtest a sport and market against the closing line';

    public function handle(BacktestService $service, TrainingSetBuilder $builder): int
    {
        $market = Market::tryFrom((string) $this->option('market'));

        if ($market === null) {
            $this->error('Unknown market. Known: '.implode(', ', Market::values()));

            return self::FAILURE;
        }

        $model = ModelType::tryFrom(mb_strtolower((string) $this->option('model')));

        if ($model === null) {
            $this->error('Unknown model. Known: '.implode(', ', ModelType::values()));

            return self::FAILURE;
        }

        $sports = mb_strtolower((string) $this->argument('sport')) === 'all'
            ? Sport::enabled()
            : array_values(array_filter([Sport::tryFrom(mb_strtolower((string) $this->argument('sport')))]));

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        foreach ($sports as $sport) {
            $this->runFor($service, $builder, $sport, $market, $model);
        }

        return self::SUCCESS;
    }

    private function runFor(
        BacktestService $service,
        TrainingSetBuilder $builder,
        Sport $sport,
        Market $market,
        ModelType $model,
    ): void {
        $available = $builder->count($sport);

        $this->newLine();
        $this->line("<comment>{$sport->label()} · {$market->label()} · {$model->label()}</comment>");

        if ($available === 0) {
            $this->line('  no completed games in the database yet - run edge:backfill first');

            return;
        }

        $this->line("  {$available} completed games");

        $result = $service->run(
            $sport,
            $market,
            tune: (bool) $this->option('tune'),
            burnIn: (int) $this->option('burn-in'),
            model: $model,
        );

        $summary = $result['summary'];
        $verdict = $result['verdict'];

        if ($summary->predictions === 0) {
            $this->line('  not enough games to score anything past the burn-in');

            return;
        }

        $this->line(sprintf('  scored %d predictions, %d with a closing line', $summary->predictions, $summary->withMarket));
        $this->line(sprintf('  log loss %.4f   brier %.4f   calibration error %.4f   auc %.3f',
            $summary->logLoss, $summary->brier, $summary->calibrationError, $summary->auc));

        if ($summary->marketLogLoss !== null) {
            $this->line(sprintf('  market log loss %.4f   improvement %+.4f   t-stat %+.2f',
                $summary->marketLogLoss,
                $summary->logLossImprovement(),
                $summary->improvementTStat() ?? 0.0));
            $this->line(sprintf('  market calibration %.4f   model gives up %+.4f',
                $summary->marketCalibrationError ?? 0.0,
                -($summary->calibrationImprovement() ?? 0.0)));
            $this->line(sprintf('  home-side bias %+.2f%%   flat ROI %+.2f%%',
                ($summary->meanHomeBias ?? 0) * 100,
                ($summary->flatRoi ?? 0) * 100));
        } else {
            $this->line('  <fg=yellow>no closing lines stored for this sport, so there is no benchmark</>');
        }

        if (($result['params']['coefficients'] ?? []) !== []) {
            $this->newLine();
            $this->line(sprintf('  fitted on %d games, refitted %d times. What it learned:',
                $result['params']['training_games'] ?? 0,
                $result['params']['refits'] ?? 0));

            // Biggest influence first, sign ignored, because a strong
            // negative weight is just as much of a finding as a positive one.
            $coefficients = $result['params']['coefficients'];
            uasort($coefficients, fn ($a, $b) => abs($b) <=> abs($a));

            foreach ($coefficients as $name => $weight) {
                $this->line(sprintf('    %-18s %+.4f', $name, $weight));
            }

            $this->newLine();
        }

        if ($result['params']['tuned'] ?? false) {
            $this->line(sprintf('  K tuned to %.1f on %d early games, scored only on later ones',
                $result['params']['k_factor'], $result['params']['tuning_games'] ?? 0));
        }

        $colour = match ($verdict->verdict->value) {
            'proven' => 'green',
            'negative' => 'red',
            default => 'yellow',
        };

        $this->newLine();
        $this->line("  <fg={$colour}>".mb_strtoupper($verdict->verdict->label()).'</>');
        $this->line('  '.$verdict->reason);
    }
}
