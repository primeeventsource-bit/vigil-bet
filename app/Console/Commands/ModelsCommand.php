<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ModelVersion;
use App\Services\Modeling\ModelTrainer;
use Illuminate\Console\Command;

/**
 * Lists stored model versions, and promotes one if asked.
 *
 * This is the counterpart to `edge:train --candidate`: a version stored
 * without being activated needs a way to go live later, and until the model
 * lab page exists this is it.
 */
class ModelsCommand extends Command
{
    protected $signature = 'edge:models
                            {--activate= : Promote this version id and retire the one it replaces}
                            {--all : Include retired versions}';

    protected $description = 'List stored model versions and activate one';

    public function handle(ModelTrainer $trainer): int
    {
        if ($id = $this->option('activate')) {
            return $this->activate($trainer, (int) $id);
        }

        $versions = ModelVersion::query()
            ->when(! $this->option('all'), fn ($q) => $q->whereNot('status', 'retired'))
            ->orderBy('sport')
            ->orderBy('market')
            ->orderByDesc('version')
            ->get();

        if ($versions->isEmpty()) {
            $this->line('No model versions stored yet. Run edge:train once there is history to train on.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'model', 'status', 'trained through', 'games', 'log loss', 'verdict', 'calibration'],
            $versions->map(fn (ModelVersion $v) => [
                $v->id,
                $v->describe(),
                $v->status->value,
                $v->trained_through_at?->toDateString(),
                $v->training_rows,
                $this->number($v->holdout_metrics['log_loss'] ?? null),
                $v->holdout_metrics['verdict'] ?? '-',
                $v->calibration['method'] ?? 'none',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function activate(ModelTrainer $trainer, int $id): int
    {
        $version = ModelVersion::find($id);

        if ($version === null) {
            $this->error("No model version with id {$id}.");

            return self::FAILURE;
        }

        $trainer->activate($version);

        $this->info("Activated {$version->describe()}. Predictions will now be attributed to it.");

        return self::SUCCESS;
    }

    private function number(mixed $value): string
    {
        return $value === null ? '-' : sprintf('%.4f', (float) $value);
    }
}
