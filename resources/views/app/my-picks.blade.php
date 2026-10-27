@php
    use App\Support\Clock;
@endphp

<x-layouts.app title="My Picks">
    <header>
        <h1 class="font-[family-name:var(--font-display)] text-2xl font-bold">My Picks</h1>
        <p class="mt-2 max-w-3xl text-sm text-[var(--vg-slate)]">
            Published opinions you have kept. Nothing here is staked and nothing here claims a
            profit &mdash; that is
            <a href="{{ route('bets') }}" class="underline hover:text-[var(--vg-paper)]">My Bets</a>.
        </p>
    </header>

    @if ($saved->isEmpty())
        <section class="panel mt-5 p-8 text-center">
            <p class="font-semibold">You have not saved anything yet.</p>
            <p class="mt-1 mx-auto max-w-lg text-sm text-[var(--vg-muted)]">
                Save an opinion from the dashboard or the record and it appears here, with its
                result once the game has been graded.
            </p>
            <a href="{{ route('today') }}"
               class="mt-4 inline-block rounded-lg border border-[var(--vg-line)] px-4 py-2 text-sm font-semibold">
                Go to the dashboard
            </a>
        </section>
    @else
        @if ($awaiting > 0)
            <p class="mt-4 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-muted)]">
                {{ $awaiting }} of these have not been graded yet.
            </p>
        @endif

        <section class="panel mt-4 overflow-hidden">
            <div class="w-full overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <caption class="sr-only">Opinions you have saved</caption>
                    <thead class="text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                        <tr class="border-b border-[var(--vg-line)]">
                            <th scope="col" class="px-5 py-2.5 font-medium">Selection</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Game</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Price</th>
                            <th scope="col" class="px-3 py-2.5 text-right font-medium">Confidence</th>
                            <th scope="col" class="px-3 py-2.5 font-medium">Result</th>
                            <th scope="col" class="px-5 py-2.5 font-medium">Saved</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($saved as $pick)
                            @php($opinion = $pick->opinion)
                            @continue($opinion === null)

                            <tr class="border-b border-[var(--vg-line)] align-top last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium">{{ $opinion->selection }}</span>
                                    <span class="block font-[family-name:var(--font-mono)] text-[11px] uppercase text-[var(--vg-slate)]">
                                        {{ $opinion->sport }} &middot; {{ $opinion->market }}
                                    </span>
                                    @if ($pick->note)
                                        {{-- Theirs, and shown to nobody else. --}}
                                        <span class="mt-1 block text-[11px] text-[var(--vg-muted)]">{{ $pick->note }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ $opinion->matchup ?? '—' }}
                                    <span class="block text-[10px] text-[var(--vg-muted)]">
                                        {{ Clock::format($opinion->event?->starts_at, 'D j M, g:i A') ?? '' }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] text-xs">
                                    {{ $opinion->price_american > 0 ? '+' : '' }}{{ $opinion->price_american }}
                                </td>
                                <td class="px-3 py-3 text-right font-[family-name:var(--font-mono)] text-xs">
                                    {{ $opinion->confidencePercent() }}%
                                </td>
                                <td class="px-3 py-3 text-xs">
                                    {{--
                                        The opinion's own graded result, read through the
                                        relation. A saved pick holds no copy of it, so this
                                        can never disagree with the public record.
                                    --}}
                                    @if ($opinion->result === null)
                                        <span class="text-[var(--vg-muted)]">Awaiting</span>
                                    @else
                                        {{--
                                            Rendered here rather than through x-app.bet-status,
                                            which takes a bet. An opinion result is not a bet -
                                            it has no stake and no return - and passing one
                                            through a component built for money would be the
                                            first step towards this page reporting a profit.
                                        --}}
                                        <span @class([
                                            'badge',
                                            'badge-edge' => $opinion->result->status === 'won',
                                            'badge-loss' => $opinion->result->status === 'lost',
                                        ])>{{ ucfirst($opinion->result->status) }}</span>

                                        @if ($opinion->result->units !== null)
                                            <span class="block font-[family-name:var(--font-mono)] text-[10px] text-[var(--vg-muted)]">
                                                {{ number_format($opinion->result->units, 2) }}u
                                            </span>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-xs text-[var(--vg-slate)]">
                                    {{ $pick->created_at?->diffForHumans() }}

                                    <form method="POST" action="{{ route('my-picks.remove', $opinion) }}" class="mt-1">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="text-[11px] text-[var(--vg-muted)] underline hover:text-[var(--vg-paper)]">
                                            Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4">{{ $saved->links() }}</div>
        </section>
    @endif

    <p class="mt-4 text-[11px] text-[var(--vg-muted)]">
        Saving a pick records nothing but your interest. To record a bet, use My Bets.
    </p>
</x-layouts.app>
