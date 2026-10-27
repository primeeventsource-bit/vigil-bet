@php
    use App\Support\Clock;
@endphp

<x-layouts.app title="Projected props">
    <header>
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Projected props</h1>
        <p class="mt-2 max-w-3xl text-sm text-[var(--vg-slate)]">
            What the model expects a player to do, from the box scores on file. The Props page
            shows what the books are posting; this shows what the model thinks of it.
        </p>
    </header>

    {{--
        The calibration, before the projections rather than after.

        Everywhere else in this product a model's probability is refused
        publication until its confidence has been measured. Props have
        the same machinery, so the measurement goes on the page - a
        reader who has to go looking for it will not, and a probability
        with nothing behind it is the exact shape of a tip.
    --}}
    <section class="panel mt-5 p-5">
        <h2 class="text-sm font-bold">How these have actually done</h2>

        @if (($overall['graded'] ?? 0) === 0)
            <p class="mt-1 max-w-3xl text-xs text-[var(--vg-muted)]">
                Nothing has been graded yet, so there is no measurement to show and no claim
                being made about accuracy. The numbers below are the model's expectation and
                nothing more &mdash; read them as a starting point for your own work, not as a
                record of anything.
            </p>
        @else
            <p class="mt-1 max-w-3xl text-xs text-[var(--vg-muted)]">
                Measured against what actually happened, for projections that have settled.
                Calibration error is the gap between stated confidence and observed rate; a
                model that is directionally right and systematically overconfident shows up
                here and nowhere else.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-stat-tile label="Graded" :value="(string) $overall['graded']"
                             sample="settled and compared" />

                <x-stat-tile label="Calibration error"
                             :value="$overall['calibration_error'] === null ? null : number_format($overall['calibration_error'] * 100, 2).'%'"
                             sample="stated confidence vs observed" />

                <x-stat-tile label="Mean predicted"
                             :value="$overall['mean_predicted'] === null ? null : number_format($overall['mean_predicted'] * 100, 1).'%'"
                             :sample="$overall['observed_rate'] === null ? 'no observed rate' : 'observed '.number_format($overall['observed_rate'] * 100, 1).'%'" />

                <x-stat-tile label="Beats the market"
                             :value="$overall['beats_market'] === null ? null : ($overall['beats_market'] ? 'Yes' : 'No')"
                             :tone="($overall['beats_market'] ?? false) ? 'edge' : 'ink'"
                             :sample="'on '.($overall['with_fair_price'] ?? 0).' with a fair price'" />
            </div>
        @endif
    </section>

    @if ($measured !== [])
        <section class="panel mt-5 overflow-hidden">
            <h2 class="px-5 pt-5 text-sm font-bold">By market</h2>

            <div class="mt-3 w-full overflow-x-auto">
                <table class="w-full min-w-[620px] text-left text-sm">
                    <caption class="sr-only">Projection accuracy by market</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Market</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Graded</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Calibration error</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Beats market</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($measured as $row)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-2.5 font-[family-name:var(--font-mono)] text-xs">
                                    {{ str_replace('|', ' · ', (string) $row['market']) }}
                                </td>
                                <td class="px-3 py-2.5 text-right text-xs">{{ $row['graded'] }}</td>
                                <td class="px-3 py-2.5 text-right font-[family-name:var(--font-mono)] text-xs">
                                    {{ $row['calibration_error'] === null ? '—' : number_format($row['calibration_error'] * 100, 2).'%' }}
                                </td>
                                <td class="px-5 py-2.5 text-right text-xs">
                                    @if ($row['beats_market'] === null)
                                        <span class="text-[var(--vg-muted)]">not measured</span>
                                    @elseif ($row['beats_market'])
                                        <span class="badge badge-edge">Yes</span>
                                    @else
                                        <span class="text-[var(--vg-muted)]">No</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($sports !== [])
        <form method="GET" action="{{ route('prop-projections') }}" class="mt-5 flex flex-wrap items-end gap-3">
            <label class="block text-sm">
                <span class="text-[var(--vg-slate)]">League</span>
                <select name="sport" class="mt-1 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
                    <option value="">All</option>
                    @foreach ($sports as $key)
                        <option value="{{ $key }}" @selected($sport === $key)>{{ strtoupper($key) }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
                Filter
            </button>
        </form>
    @endif

    @if ($projections->isEmpty())
        <section class="panel mt-5 p-8 text-center">
            <p class="font-semibold">No projections ahead.</p>
            <p class="mt-1 mx-auto max-w-lg text-sm text-[var(--vg-muted)]">
                Projections are produced for upcoming games from players with at least
                {{ $minimumGames }} games on file. Below that a distribution is a guess with
                error bars, and the error bars are the only honest part.
            </p>
        </section>
    @else
        <section class="panel mt-5 overflow-hidden">
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <caption class="sr-only">Upcoming prop projections</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Player</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Market</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Line</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Projected</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Leans</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Games</th>
                            <th scope="col" class="px-5 py-2.5 font-medium">Starts</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($projections as $projection)
                            @php($preference = $projection->preference())
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3 font-medium">{{ $projection->player_name }}</td>
                                <td class="px-3 py-3 font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-slate)]">
                                    {{ $projection->market_key }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] text-xs">
                                    {{ number_format((float) $projection->line, 1) }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] text-xs">
                                    {{ number_format((float) $projection->projected_mean, 1) }}
                                    {{--
                                        The spread travels with the mean. A projection of 24.1
                                        with a standard deviation of 9 and one with a standard
                                        deviation of 2 are different statements, and only one
                                        of them is worth acting on.
                                    --}}
                                    <span class="block text-[10px] text-[var(--vg-muted)]">
                                        &plusmn;{{ number_format((float) $projection->projected_sd, 1) }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    {{-- A word as well as a number. --}}
                                    <span class="font-semibold text-[var(--vg-paper)]">{{ ucfirst($preference['side']) }}</span>
                                    <span class="block font-[family-name:var(--font-mono)] text-[10px] text-[var(--vg-slate)]">
                                        {{ number_format($preference['probability'] * 100, 1) }}%
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
                                    {{ $projection->games }}
                                </td>
                                <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ Clock::format($projection->event?->starts_at, 'D g:i A') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">{{ $projections->links() }}</div>
        </section>
    @endif

    <p class="mt-4 max-w-3xl text-[11px] text-[var(--vg-muted)]">
        A projection is what the model expects, not what will happen. It is not a pick, it has
        not been through the verdict gate that governs the published board, and the calibration
        above is the only evidence that any of it means anything. Players with fewer than
        {{ $minimumGames }} games on file are left out.
    </p>
</x-layouts.app>
