<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Players\PropCalibration;
use App\Services\Players\PropProjectionSettler;
use App\Services\Players\PropProjectionWriter;
use App\Support\BatchMemory;
use Illuminate\Console\Command;

/**
 * Marks the prop model's homework and reports the score.
 *
 * The question this answers is the only one that could ever make player
 * props publishable, and it is a harder question than it looks. A model
 * being well calibrated is not enough: the market is also well
 * calibrated, and on the same games. The bar is the market's log loss on
 * the same selections, which is the bar the game markets are held to,
 * and it is reported here beside the model's rather than instead of it.
 *
 * Nothing in here publishes anything. A model that clears the bar would
 * still need a decision to publish it, and a model that does not has
 * told us something worth knowing for the price of writing numbers down.
 */
class SettlePropProjectionsCommand extends Command
{
    protected $signature = 'edge:props:settle
                            {--sport= : Only report one sport}
                            {--limit=2000 : How many projections to grade this run}
                            {--all-models : Report across every model fingerprint rather than the current one}
                            {--curve : Also print the calibration curve}';

    protected $description = 'Grade recorded prop projections against box scores and report calibration';

    public function handle(
        PropProjectionSettler $settler,
        PropCalibration $calibration,
        PropProjectionWriter $writer,
    ): int {
        // Thousands of projections and a box score for each.
        BatchMemory::raise();

        $graded = $settler->run((int) $this->option('limit'));

        $this->info(sprintf(
            'Graded %d projections. %d waiting on a final box score, %d voided.',
            $graded['settled'],
            $graded['unresolved'],
            $graded['voided'],
        ));

        $this->models($writer);

        /*
         * Scoped to the current model by default. Pooling two parameter
         * sets into one figure produces a number that describes neither
         * and looks like more evidence rather than less.
         */
        $fingerprint = $this->option('all-models') ? null : $writer->fingerprint();
        $sport = $this->option('sport');

        $overall = $calibration->overall($fingerprint, $sport);

        if ($overall['graded'] === 0) {
            $this->newLine();
            $this->warn($this->option('all-models')
                ? 'Nothing graded yet. Record projections with edge:props:project --record, then run this after the games.'
                : 'Nothing graded yet for the current model. Try --all-models to see earlier ones.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            'Across %d graded selections%s:',
            $overall['graded'],
            $this->option('all-models') ? ', every model' : ', current model only',
        ));

        $this->table(
            ['market', 'graded', 'vs market on', 'model loss', 'market loss', 'beats market', 'brier skill', 'cal. error', 'predicted', 'observed'],
            array_map(fn (array $m) => [
                str_replace('player_', '', (string) $m['market']),
                $m['graded'],
                $m['with_fair_price'],
                $this->decimal($m['model_log_loss'], 4),
                $this->decimal($m['market_log_loss'], 4),
                $this->verdict($m['beats_market']),
                $this->decimal($m['brier_skill_vs_market'], 4),
                $this->percent($m['calibration_error']),
                $this->percent($m['mean_predicted']),
                $this->percent($m['observed_rate']),
            ], $calibration->byMarket($fingerprint, $sport)),
        );

        $this->summary($overall);

        if ($this->option('curve')) {
            $this->curve($calibration->curve($fingerprint, $sport));
        }

        return self::SUCCESS;
    }

    /**
     * How much evidence exists per model.
     *
     * Printed before any figure, because a fingerprint with forty
     * graded rows is a hint and one with four thousand is a
     * measurement, and no calibration number says which it came from.
     */
    private function models(PropProjectionWriter $writer): void
    {
        $models = $writer->byModel();

        if ($models === []) {
            return;
        }

        $current = $writer->fingerprint();

        $this->newLine();
        $this->table(
            ['model', 'rows', 'first', 'last'],
            array_map(fn (array $m) => [
                substr((string) $m['model_fingerprint'], 0, 12)
                    .($m['model_fingerprint'] === $current ? '  (current)' : ''),
                $m['rows'],
                substr((string) $m['first'], 0, 16),
                substr((string) $m['last'], 0, 16),
            ], $models),
        );
    }

    /**
     * The one paragraph that matters, said plainly.
     *
     * @param  array<string, mixed>  $overall
     */
    private function summary(array $overall): void
    {
        $this->newLine();

        if ($overall['with_fair_price'] === 0) {
            $this->warn(sprintf(
                'None of the %d graded selections had a two-sided price, so the model cannot be compared to the '
                .'market at all - a single quote cannot have its vig removed. Calibration error is %s.',
                $overall['graded'],
                $this->percent($overall['calibration_error']),
            ));

            return;
        }

        $this->line(sprintf(
            'Model log loss %s against the market\'s %s, over %d of %d selections that had a two-sided price.',
            $this->decimal($overall['model_log_loss'], 4),
            $this->decimal($overall['market_log_loss'], 4),
            $overall['with_fair_price'],
            $overall['graded'],
        ));

        if ($overall['beats_market'] === true) {
            $this->info(
                'The model is ahead of the market on this sample. That is the measurement that would earn props a '
                .'place, and it is not on its own a decision to publish them - check the sample size above first.'
            );

            return;
        }

        $this->warn(
            'The model is behind the market, so nothing here is publishable and the gate stays shut. '
            .'That is the system working rather than failing.'
        );
    }

    /** @param  array<int, array<string, mixed>>  $curve */
    private function curve(array $curve): void
    {
        if ($curve === []) {
            return;
        }

        $this->newLine();
        $this->info('Calibration curve - predicted against what actually happened:');

        $this->table(
            ['bucket', 'n', 'predicted', 'observed', 'gap'],
            array_map(fn (array $bin) => [
                sprintf('%.0f-%.0f%%', $bin['low'] * 100, $bin['high'] * 100),
                $bin['n'],
                $this->percent($bin['mean_predicted']),
                $this->percent($bin['observed_rate']),
                sprintf('%+.1f pts', ($bin['observed_rate'] - $bin['mean_predicted']) * 100),
            ], array_values(array_filter($curve, fn (array $bin) => $bin['n'] > 0))),
        );
    }

    private function verdict(?bool $beats): string
    {
        return match ($beats) {
            true => 'yes',
            false => 'no',
            default => '-',
        };
    }

    private function decimal(?float $value, int $places): string
    {
        return $value === null ? '-' : sprintf('%.'.$places.'f', $value);
    }

    private function percent(?float $value): string
    {
        return $value === null ? '-' : sprintf('%.1f%%', $value * 100);
    }
}
