<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Enums\TriggerSource;
use App\Jobs\Modeling\PredictUpcoming;
use App\Services\Modeling\Forecaster;
use Illuminate\Console\Command;

class PredictCommand extends Command
{
    protected $signature = 'edge:predict
                            {sport=all : Sport key, or "all"}
                            {--hours=72 : How far ahead to price}
                            {--sync}';

    protected $description = 'Price upcoming games with the active model and record the predictions';

    public function handle(Forecaster $forecaster): int
    {
        $sports = mb_strtolower((string) $this->argument('sport')) === 'all'
            ? Sport::enabled()
            : array_values(array_filter([Sport::tryFrom(mb_strtolower((string) $this->argument('sport')))]));

        if ($sports === []) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()).', all');

            return self::FAILURE;
        }

        foreach ($sports as $sport) {
            $this->predictOne($forecaster, $sport);
        }

        return self::SUCCESS;
    }

    private function predictOne(Forecaster $forecaster, Sport $sport): void
    {
        $hours = (int) $this->option('hours');

        $this->newLine();
        $this->line("<comment>{$sport->label()}</comment>");

        if (! $this->option('sync')) {
            dispatch(new PredictUpcoming($sport, $hours, TriggerSource::Manual));
            $this->line('  queued');

            return;
        }

        $result = $forecaster->forecast($sport, $hours);

        if (isset($result['refusal'])) {
            $this->line('  <fg=yellow>'.$result['refusal'].'</>');

            return;
        }

        $this->line("  model {$result['version']}");

        if ($result['caught_up_with'] > 0) {
            $this->line(sprintf('  caught up with %d finished games since it was trained', $result['caught_up_with']));
        }

        $this->line(sprintf('  %d games in the next %dh: %d predicted, %d already done, %d abstained',
            $result['events'], $hours, $result['written'], $result['already_predicted'], $result['abstained']));
    }
}
