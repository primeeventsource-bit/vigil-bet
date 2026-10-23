@props(['gate'])

<section class="mt-8">
    <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">The gate</h2>
    <p class="mt-1 text-sm text-[var(--vg-slate)]">
        What a model has to clear before any of its picks may be sold. Both conditions, always —
        a sharper model with miscalibrated probabilities is more dangerous than a blunt one,
        because stake sizing reads the probability literally.
    </p>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="panel min-w-0 p-5">
            <h3 class="text-sm font-semibold">Thresholds in force</h3>

            <dl class="mt-3 space-y-2 text-sm">
                @foreach ([
                    'min_predictions' => 'Graded predictions needed before any verdict',
                    'min_log_loss_improvement' => 'Log loss improvement over the closing line',
                    'min_t_stat' => 'Standard errors above zero that improvement must clear',
                    'calibration_tolerance' => 'Calibration it may give up to the market',
                ] as $key => $label)
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-[var(--vg-slate)]">{{ $label }}</dt>
                        <dd class="shrink-0 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>
                            {{ $gate['thresholds'][$key] ?? '—' }}
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-3 text-[11px] leading-relaxed text-[var(--vg-slate)]">
                The t-statistic is why a small improvement does not promote a model. An
                improvement of 0.001 means nothing on its own; one that is three standard errors
                from zero means something. Without that bar, noise proves models.
            </p>
        </div>

        <div class="panel min-w-0 p-5">
            <h3 class="text-sm font-semibold">Where the versions stand</h3>

            <ul class="mt-3 space-y-2.5">
                @foreach ($gate['verdicts'] as $verdict => $count)
                    <li class="flex items-center gap-3 text-sm">
                        <span class="w-32 shrink-0">
                            <span @class([
                                'badge',
                                'badge-edge' => $verdict === 'proven',
                                'badge-warning' => in_array($verdict, ['unproven', 'insufficient_data'], true),
                                'badge-pass' => $verdict === 'negative',
                            ])>{{ str_replace('_', ' ', (string) $verdict) }}</span>
                        </span>

                        <span class="min-w-0 flex-1 text-xs text-[var(--vg-slate)]">
                            {{ match ($verdict) {
                                'proven' => 'Beats the closing line by enough, often enough.',
                                'unproven' => 'Beats it on log loss but is worse calibrated, so not safe to stake.',
                                'negative' => 'Measurably loses to the closing line.',
                                'insufficient_data' => 'Too few graded predictions, or no closing lines to measure against.',
                                default => '',
                            } }}
                        </span>

                        <span class="shrink-0 font-[family-name:var(--font-mono)] tabular-nums" data-numeric>{{ $count }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
