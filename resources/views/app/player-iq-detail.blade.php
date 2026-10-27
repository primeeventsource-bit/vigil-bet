<x-layouts.app :title="$rating->player_name">
    <nav class="text-xs">
        <a href="{{ route('player-iq') }}" class="text-[var(--vg-slate)] underline hover:text-[var(--vg-paper)]">
            &larr; Player IQ
        </a>
    </nav>

    <header class="mt-3">
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">{{ $rating->player_name }}</h1>
        <p class="mt-1 font-[family-name:var(--font-mono)] text-xs uppercase tracking-wide text-[var(--vg-slate)]">
            {{ $rating->sport }} &middot; {{ $rating->peer_group }}
            @if ($rating->team)
                &middot; {{ $rating->team->display_name }}
            @endif
        </p>
    </header>

    <section class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-tile label="Rating" :value="(string) $rating->overall"
                     :sample="'percentile among '.$peerCount.' '.$rating->peer_group" />

        <x-stat-tile label="Games measured" :value="(string) $rating->games"
                     sample="the sample behind the number" />

        <x-stat-tile label="Measured from"
                     :value="\App\Support\Clock::format($rating->measured_from, 'j M Y') ?? '—'"
                     :sample="'to '.(\App\Support\Clock::format($rating->measured_to, 'j M Y') ?? '—')" />

        <x-stat-tile label="Recalculated"
                     :value="\App\Support\Clock::format($rating->as_of, 'j M, H:i') ?? '—'"
                     :sample="$rating->isStale() ? 'older than two days' : 'current'" />
    </section>

    @if ($rating->isStale())
        {{--
            Said plainly rather than hidden. A rating is a function of
            the games on file when it ran, so a stale one is not wrong -
            it is answering an older question, and the reader has to
            know which.
        --}}
        <div class="panel mt-4 border-l-2 border-[var(--vg-warning)] p-4">
            <p class="text-sm font-semibold">This rating is not current.</p>
            <p class="mt-1 text-xs text-[var(--vg-slate)]">
                It was last recalculated {{ $rating->as_of?->diffForHumans() }} and does not
                include anything since.
            </p>
        </div>
    @endif

    <section class="panel mt-5 p-5">
        <h2 class="font-[family-name:var(--font-display)] font-semibold">What it is built from</h2>
        <p class="mt-1 text-xs text-[var(--vg-muted)]">
            Each component is a percentile of its own, on the same scale. The vocabulary
            differs by league, because flattening four sports into shared headings would
            mean inventing categories that fit none of them.
        </p>

        @if ($rating->sortedComponents() === [])
            <p class="mt-4 text-sm text-[var(--vg-muted)]">No component breakdown was stored for this rating.</p>
        @else
            <ul class="mt-4 space-y-3">
                @foreach ($rating->sortedComponents() as $label => $score)
                    <li>
                        <div class="flex items-baseline justify-between text-xs">
                            <span class="text-[var(--vg-slate)]">{{ ucfirst(str_replace('_', ' ', (string) $label)) }}</span>
                            <span class="font-[family-name:var(--font-mono)] font-bold text-[var(--vg-paper)]">{{ (int) $score }}</span>
                        </div>
                        {{-- Silver, not green. The green means a proven edge and this is not one. --}}
                        <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-[var(--vg-line)]">
                            <div class="h-full rounded-full bg-[var(--vg-chart-fill)]"
                                 style="width: {{ max(0, min(100, (int) $score)) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="panel mt-5 overflow-hidden">
        <div class="px-5 pt-5">
            <h2 class="font-[family-name:var(--font-display)] font-semibold">
                Top rated {{ $rating->peer_group }}
            </h2>
            <p class="mt-1 text-xs text-[var(--vg-muted)]">
                The only comparison this rating makes. Measuring a player against a different
                position would be a different claim, and not a useful one.
            </p>
        </div>

        <div class="mt-4 w-full overflow-x-auto">
            <table class="w-full min-w-[520px] text-left text-sm">
                <caption class="sr-only">Highest rated players in this position</caption>
                <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr class="border-b border-[var(--vg-line)]">
                        <th scope="col" class="px-5 py-2.5 font-medium">Player</th>
                        <th scope="col" class="px-3 py-2.5 font-medium">Team</th>
                        <th scope="col" class="px-3 py-2.5 text-right font-medium">Games</th>
                        <th scope="col" class="px-5 py-2.5 text-right font-medium">Rating</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($peers as $peer)
                        <tr @class([
                            'border-b border-[var(--vg-line)] last:border-0',
                            'bg-[var(--vg-slate-dark)]' => $peer->is($rating),
                        ])>
                            <td class="px-5 py-3">
                                <a class="underline hover:text-[var(--vg-paper)]"
                                   href="{{ route('player-iq.show', ['sport' => $peer->sport, 'player' => $peer->player_external_id]) }}">
                                    {{ $peer->player_name }}
                                </a>
                            </td>
                            <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                {{ $peer->team?->abbreviation ?? '—' }}
                            </td>
                            <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
                                {{ $peer->games }}
                            </td>
                            <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] text-sm font-bold">
                                {{ $peer->overall }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="px-5 py-4 text-[11px] text-[var(--vg-muted)]">
            Players with fewer than {{ $minimumGames }} games on file are not rated.
        </p>
    </section>
</x-layouts.app>
