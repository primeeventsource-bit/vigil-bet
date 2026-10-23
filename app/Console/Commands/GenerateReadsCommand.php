<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VlBoardEntry;
use App\Services\Ai\ReadWriter;
use Illuminate\Console\Command;

class GenerateReadsCommand extends Command
{
    protected $signature = 'vigil:reads
                            {--limit=25 : How many entries to explain in one run}
                            {--passes : Explain passes too, not just sellable picks}
                            {--bulk : Use the cheaper bulk model}';

    protected $description = 'Write Vigil Reads for board entries that do not have one yet';

    public function handle(ReadWriter $writer): int
    {
        if (! $writer->isConfigured()) {
            $this->warn('ANTHROPIC_API_KEY is not set.');
            $this->line('<fg=gray>Reads will still be written, from the template rather than the model.</>');
        }

        $version = (int) config('ai.prompt_version');

        /*
         * Picks first, because those are the ones a customer reads. With
         * nothing proven that set is empty, and --passes explains the
         * near misses instead - which is the product's actual output
         * right now and worth explaining well.
         */
        $entries = VlBoardEntry::query()
            ->current()
            ->when(! $this->option('passes'), fn ($q) => $q->sellablePicks())
            ->when($this->option('passes'), fn ($q) => $q->nearMisses())
            ->whereNotExists(function ($q) use ($version): void {
                $q->selectRaw('1')->from('vigil_reads')
                    ->whereColumn('vigil_reads.subject_id', 'vl_board_entries.id')
                    ->where('vigil_reads.subject_type', 'board_entry')
                    ->where('vigil_reads.prompt_version', $version);
            })
            ->limit((int) $this->option('limit'))
            ->get();

        if ($entries->isEmpty()) {
            $this->info('Nothing to explain. Every entry in scope already has a Read at version '.$version.'.');

            return self::SUCCESS;
        }

        $fromModel = 0;

        foreach ($entries as $entry) {
            $read = $writer->forBoardEntry($entry, (bool) $this->option('bulk'));
            $fromModel += $read->fromModel() ? 1 : 0;

            $this->line(sprintf(
                '  %-28s %s',
                mb_substr((string) $entry->selection, 0, 28),
                $read->fromModel() ? 'model' : 'template ('.$read->rejected_reason.')',
            ));
        }

        $this->newLine();
        $this->info(sprintf('%d Read(s) written, %d from the model.', $entries->count(), $fromModel));

        return self::SUCCESS;
    }
}
