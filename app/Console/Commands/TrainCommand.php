<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Exceptions\UnmodelledMarket;
use App\Jobs\Modeling\TrainModel;
use App\Services\Modeling\ModelTrainer;
use Illuminate\Console\Command;

/**
 * Trains a model and stores it as a version.
 *
 * Like edge:backtest, the output refuses to flatter the result: it prints
 * what the model scored against the closing line, what the verdict was, and
 * whether a calibration map survived validation. A stored version is not a
 * claim that the model is any good - the verdict says that, and it says it
 * in the same breath.
 */
class TrainCommand extends Command
{
    protected $signature = 'edge:train
                            {sport : Sport key, or "all"}
                            {--market=h2h : h2h, spreads or totals}
                            {--model=elo : elo or logistic}
                            {--tune : Sweep the K factor on early history and report only on later games}
                            {--burn-in=200 : Games replayed before scoring starts}
                            {--candidate : Store without activating, leaving the current model live}
                            {--label= : A note to remember this run by}
                            {--sync}';

    protected $description = 'Train a model, measure it, and store it as a versioned row';

    public function handle(ModelTrainer $trainer): int
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
            $this->trainOne($trainer, $sport, $market, $model);
        }

        return self::SUCCESS;
    }

    private function trainOne(ModelTrainer $trainer, Sport $sport, Market $market, ModelType $model): void
    {
        $this->newLine();
        $this->line("<comment>{$sport->label()} · {$market->label()} · {$model->label()}</comment>");

        if (! $this->option('sync')) {
            dispatch(new TrainModel(
                $sport,
                $market,
                $model,
                tune: (bool) $this->option('tune'),
                burnIn: (int) $this->option('burn-in'),
                activate: ! $this->option('candidate'),
                label: $this->option('label') ?: null,
                triggeredBy: TriggerSource::Manual,
            ));

            $this->line('  queued');

            return;
        }

        try {
            $result = $trainer->train(
                $sport,
                $market,
                $model,
                tune: (bool) $this->option('tune'),
                burnIn: (int) $this->option('burn-in'),
                activate: ! $this->option('candidate'),
                label: $this->option('label') ?: null,
            );
        } catch (UnmodelledMarket $e) {
            // Not a crash and not a failure of this run. The app is saying
            // it has no honest way to answer, which is the answer.
            $this->line('  <fg=yellow>nothing stored</>');
            $this->line('  '.$e->getMessage());

            return;
        }

        if ($result['refusal'] !== null) {
            $this->line('  <fg=yellow>nothing stored</>');
            $this->line('  '.$result['refusal']);

            return;
        }

        $version = $result['version'];
        $summary = $result['summary'];
        $verdict = $result['verdict'];

        $this->line(sprintf('  stored %s as <options=bold>%s</>', $version->describe(), $version->status->value));
        $this->line(sprintf('  trained on %d games through %s',
            $version->training_rows,
            $version->trained_through_at->toDateString()));
        $this->line(sprintf('  scored %d out of sample: log loss %.4f   calibration error %.4f   auc %.3f',
            $summary->predictions, $summary->logLoss, $summary->calibrationError, $summary->auc));

        $this->reportCalibration($version->calibration ?? []);

        $colour = match ($verdict->verdict->value) {
            'proven' => 'green',
            'negative' => 'red',
            default => 'yellow',
        };

        $this->newLine();
        $this->line("  <fg={$colour}>".mb_strtoupper($verdict->verdict->label()).'</>');
        $this->line('  '.$verdict->reason);
    }

    /** @param  array<string, mixed>  $calibration */
    private function reportCalibration(array $calibration): void
    {
        $method = $calibration['method'] ?? 'none';

        if ($method === 'none') {
            $this->line('  calibration: none stored');
            $this->line('  '.($calibration['reason'] ?? ''));

            return;
        }

        $errors = $calibration['calibration_error'] ?? [];

        $this->line(sprintf(
            '  calibration: %s, fitted on %d and validated on %d unseen games (error %.4f to %.4f)',
            $method,
            $calibration['fitted_on'] ?? 0,
            $calibration['validated_on'] ?? 0,
            $errors['raw'] ?? 0.0,
            $errors[$method] ?? 0.0,
        ));
    }
}
