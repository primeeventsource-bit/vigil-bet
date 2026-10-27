<x-layouts.app title="Player IQ">
    <header>
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">Player IQ</h1>
        <p class="mt-2 max-w-3xl text-sm text-[var(--vg-slate)]">
            A 0&ndash;99 rating for every tracked player, measured against others in the same
            position from the box scores on file.
        </p>
    </header>

    {{--
        Said before the numbers, not after them.

        The scale is borrowed from the sports games and the method is
        not. A page that shows a 94 beside a familiar-looking badge and
        explains the difference in a footnote has already been misread
        by everybody who scrolled.
    --}}
    <section class="panel mt-5 p-5">
        <h2 class="text-sm font-bold">What this number is</h2>
        <p class="mt-1 max-w-3xl text-xs text-[var(--vg-muted)]">
            A percentile, not a scouting grade. A rating of 94 means this player's production
            sits in the 94th percentile of others in his position &mdash; it is not the 94 a
            video game would show, and it is not a prediction. The sample size travels with
            every rating because a 94 over eleven games and a 94 over four hundred are
            different statements about the same player. Anything under
            {{ $minimumGames }} games is left off entirely.
        </p>

        @if ($asOf)
            <p class="mt-2 font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                Recalculated {{ \App\Support\Clock::format($asOf, 'j M, H:i') }}
                &middot; ratings are a statement about the games on file at that moment
            </p>
        @endif
    </section>

    <form method="GET" action="{{ route('player-iq') }}" class="mt-5 flex flex-wrap items-end gap-3">
        <label class="block text-sm">
            <span class="text-[var(--vg-slate)]">Player</span>
            <input name="q" value="{{ $search }}" placeholder="Search a name"
                   class="mt-1 w-56 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] px-3 py-2">
        </label>

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

        @if ($search !== '' || $sport !== '')
            <a href="{{ route('player-iq') }}" class="text-sm text-[var(--vg-slate)] underline">Clear</a>
        @endif
    </form>

    @if ($ratings->isEmpty())
        <section class="panel mt-5 p-8 text-center">
            <p class="font-semibold">No rated players here.</p>
            <p class="mt-1 text-sm text-[var(--vg-muted)]">
                @if ($search !== '' || $sport !== '')
                    Nothing matches that filter. Players with fewer than {{ $minimumGames }}
                    games on file are not rated.
                @else
                    Ratings are computed from box scores. None are on file yet.
                @endif
            </p>
        </section>
    @else
        <section class="panel mt-5 overflow-hidden">
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <caption class="sr-only">Player ratings</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Player</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">League</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Position</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Team</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Games</th>
                            <th scope="col" class="px-5 py-2.5 text-right font-medium">Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ratings as $rating)
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td class="px-5 py-3">
                                    <a class="font-medium underline hover:text-[var(--vg-paper)]"
                                       href="{{ route('player-iq.show', ['sport' => $rating->sport, 'player' => $rating->player_external_id]) }}">
                                        {{ $rating->player_name }}
                                    </a>
                                </td>
                                <td class="px-3 py-3 font-[family-name:var(--font-mono)] text-[11px] uppercase text-[var(--vg-slate)]">
                                    {{ $rating->sport }}
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">{{ $rating->peer_group }}</td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ $rating->team?->abbreviation ?? $rating->team?->display_name ?? '—' }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)]">
                                    {{ $rating->games }}
                                </td>
                                <td class="px-5 py-3 text-right font-[family-name:var(--font-mono)] text-sm font-bold">
                                    {{ $rating->overall }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">{{ $ratings->links() }}</div>
        </section>
    @endif
</x-layouts.app>
