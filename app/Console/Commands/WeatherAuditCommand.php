<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sport;
use App\Services\Modeling\WeatherAudit;
use Illuminate\Console\Command;

/**
 * Asks whether weather is worth modelling before anything is built on it.
 *
 * The printout is ordered to put the only question with money in it last
 * and loudest: not whether wind affects scoring, which it plainly does, but
 * whether it affects scoring in a way the closing total has not already
 * absorbed.
 */
class WeatherAuditCommand extends Command
{
    protected $signature = 'edge:weather:audit
                            {sport=nfl : Sport key}
                            {--lead=1 : Which forecast run, in days before kickoff}';

    protected $description = 'Measure whether weather carries signal the market has not priced';

    public function handle(WeatherAudit $audit): int
    {
        $sport = Sport::tryFrom(mb_strtolower((string) $this->argument('sport')));

        if ($sport === null) {
            $this->error('Unknown sport. Known: '.implode(', ', Sport::values()));

            return self::FAILURE;
        }

        $result = $audit->run($sport, max(1, (int) $this->option('lead')));

        $this->newLine();
        $this->line(sprintf(
            '<comment>Weather audit</comment> · %s · forecast %d day%s out · %d games',
            mb_strtoupper($result['sport']),
            $result['lead_days'],
            $result['lead_days'] === 1 ? '' : 's',
            $result['games'],
        ));

        if ($result['games'] === 0 || $result['variables'] === []) {
            $this->newLine();

            foreach ($result['notes'] as $note) {
                $this->line('  '.$note);
            }

            return self::SUCCESS;
        }

        foreach ($result['variables'] as $field => $measures) {
            $this->report($field, $measures);
        }

        foreach ($result['notes'] as $note) {
            $this->newLine();
            $this->line('  <fg=gray>'.$note.'</>');
        }

        $this->verdict($result['variables']);

        return self::SUCCESS;
    }

    /** @param  array<string, array{n: int, r: float, slope: float, t: float}>  $measures */
    private function report(string $field, array $measures): void
    {
        $unit = match ($field) {
            'wind_mph' => 'per 10 mph',
            'temp_f' => 'per 10°F',
            default => 'per inch',
        };

        $scale = $field === 'precip_in' ? 1.0 : 10.0;

        $this->newLine();
        $this->line(sprintf('<options=bold>%s</>', $this->label($field)));

        foreach ([
            'actual_total' => 'points actually scored',
            'closing_total' => 'the closing total (is it priced in?)',
            'residual' => 'points MINUS the closing total',
        ] as $key => $what) {
            if (! isset($measures[$key])) {
                continue;
            }

            $m = $measures[$key];

            $this->line(sprintf(
                '  %-38s n=%4d  r=%+.3f  t=%+5.2f  %+.2f pts %s',
                $what,
                $m['n'],
                $m['r'],
                $m['t'],
                $m['slope'] * $scale,
                $unit,
            ));
        }
    }

    /**
     * The conclusion, stated rather than left to be inferred from a table
     * of coefficients.
     *
     * @param  array<string, array<string, array{n: int, r: float, slope: float, t: float}>>  $variables
     */
    private function verdict(array $variables): void
    {
        $this->newLine();
        $this->line('<options=bold>What this means</>');

        $anything = false;

        foreach ($variables as $field => $measures) {
            if (! isset($measures['residual'])) {
                continue;
            }

            $residual = $measures['residual'];
            $convincing = abs($residual['t']) >= 2.0;

            $anything = $anything || $convincing;

            $this->line(sprintf(
                '  %-16s %s',
                $this->label($field),
                $convincing
                    ? sprintf('leaves something: t=%+.2f on %d games. Worth a model.', $residual['t'], $residual['n'])
                    : sprintf(
                        'priced in: t=%+.2f on %d games. %s',
                        $residual['t'],
                        $residual['n'],
                        $this->sampleNeeded($residual),
                    ),
            ));
        }

        $this->newLine();

        if (! $anything) {
            $this->line('  <fg=gray>No weather variable predicts the total beyond what the closing line</>');
            $this->line('  <fg=gray>already says. Weather moves games; the market knows. A weather</>');
            $this->line('  <fg=gray>feature would fit the noise, and the backtest would flatter it.</>');

            return;
        }

        $this->line('  <fg=gray>Something survives the closing line. That is the case for building</>');
        $this->line('  <fg=gray>a totals model on it - and only a totals model: the moneyline is</>');
        $this->line('  <fg=gray>barely touched by weather, which is where the feature model lives.</>');
    }

    /**
     * How many games it would take to resolve a leftover this small.
     *
     * The number that turns "not significant" into a decision. A residual
     * whose point estimate would need twenty seasons to prove is not
     * something to wait for; one that needs another season is. Derived from
     * holding the observed correlation fixed and solving the t statistic
     * for n, which is optimistic - it assumes the effect is real and
     * exactly this size - and so a floor on the cost rather than an
     * estimate of it.
     *
     * @param  array{n: int, r: float, slope: float, t: float}  $residual
     */
    private function sampleNeeded(array $residual): string
    {
        if (abs($residual['r']) < 1e-6) {
            return 'No effect to resolve at any sample size.';
        }

        $needed = (int) ceil(4.0 / ($residual['r'] * $residual['r']));

        if ($needed <= $residual['n']) {
            return 'Nothing left to bet.';
        }

        return sprintf(
            'Resolving it would need about %s games - %s.',
            number_format($needed),
            $needed > 2500 ? 'decades of them' : 'a few more seasons',
        );
    }

    private function label(string $field): string
    {
        return match ($field) {
            'wind_mph' => 'Wind',
            'temp_f' => 'Temperature',
            'precip_in' => 'Precipitation',
            default => $field,
        };
    }
}
