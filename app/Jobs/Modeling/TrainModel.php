<?php

declare(strict_types=1);

namespace App\Jobs\Modeling;

use App\Enums\Market;
use App\Enums\ModelType;
use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Concerns\TracksJobRun;
use App\Models\JobRun;
use App\Services\Modeling\ModelTrainer;
use App\Services\Ops\JobRunRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Trains one sport and market and stores the result as a model version.
 *
 * Queued because a walk-forward run over five seasons is minutes of work,
 * not milliseconds, and because Laravel Cloud gives this kind of thing a
 * worker rather than a web request. Memory stays flat: the training set is
 * streamed out of the database by TrainingSetBuilder and the replay holds
 * one league of ratings, not one array of seasons.
 *
 * A refusal is a skip, not a failure. "Not enough history to measure
 * anything" is an ordinary state for a sport that has just been added, and
 * it lands on the Jobs screen as a skip with the reason attached rather
 * than as a red error that invites someone to go hunting for a bug.
 */
class TrainModel implements ShouldQueue
{
    use Queueable, TracksJobRun;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly Sport $sport,
        public readonly Market $market = Market::Moneyline,
        public readonly ModelType $model = ModelType::Elo,
        public readonly bool $tune = false,
        public readonly int $burnIn = 200,
        public readonly bool $activate = true,
        public readonly ?string $label = null,
        public readonly TriggerSource $triggeredBy = TriggerSource::Schedule,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("train:{$this->sport->value}:{$this->market->value}:{$this->model->value}"))
                ->expireAfter(3600),
        ];
    }

    public function handle(ModelTrainer $trainer, JobRunRecorder $recorder): void
    {
        $this->tracked(
            "model:train:{$this->market->value}:{$this->model->value}",
            function (JobRun $run) use ($trainer, $recorder): array {
                $result = $trainer->train(
                    $this->sport,
                    $this->market,
                    $this->model,
                    tune: $this->tune,
                    burnIn: $this->burnIn,
                    activate: $this->activate,
                    label: $this->label,
                );

                if ($result['refusal'] !== null) {
                    $recorder->skip($run, $result['refusal']);

                    return [];
                }

                $version = $result['version'];

                return [
                    'records_in' => $result['summary']->predictions,
                    'records_out' => 1,
                    'model_version_id' => $version->id,
                    'version' => $version->describe(),
                    'status' => $version->status->value,
                    'verdict' => $result['verdict']->verdict->value,
                    'calibration' => $version->calibration['method'] ?? 'none',
                ];
            },
            $this->sport,
        );
    }
}
