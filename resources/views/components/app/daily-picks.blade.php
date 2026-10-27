@props(['opinions', 'record', 'standing' => null])

{{--
    VIGIL'S DAILY PICKS — the page this product exists to show.

    Built from `opinions`: selections published before kickoff, from the
    markets whose stated probabilities have been measured to land close
    to the truth. Every row is a claim that can be checked later, and
    every one of them is checked, on the Results page.

    Three columns of the reference table are deliberately not here.
    Edge and EV are the engine's own arithmetic, and a confidence figure
    beside two more decimals invites being read as a precision the model
    does not have - the brief asked for the analytics to come off the
    page and those are the analytics. What stays is the thing a customer
    is actually being offered: the game, the pick, the price, and how
    sure we say we are.

    The accuracy strip sits above the table rather than below it. A
    picks card without its record is a claim with nowhere to check it,
    and putting it underneath would make it the thing nobody scrolls to.
--}}
<section class="panel overflow-hidden">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--vg-line)] px-5 py-4">
        <div>
            <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold uppercase tracking-wide">
                {{ config('brand.name') }}'s daily picks
            </h2>
            <p class="mt-1 text-xs text-[var(--vg-slate)]">
                Published before kickoff · our own opinion, not a proven edge
            </p>
        </div>

        @if (\App\Models\VlModel::anyMarketProven())
            <a href="{{ route('opinions') }}"
               class="text-xs text-[var(--vg-slate)] underline transition hover:text-[var(--vg-paper)]">
                See how they have done →
            </a>
        @endif
    </header>

    {{--
        What the record says so far, in the same row as the picks. Null
        until something is graded, and shown as a dash: a win rate of 0%
        and a win rate that does not exist look identical and are not the
        same claim.
    --}}
    <dl class="grid grid-cols-2 gap-px border-b border-[var(--vg-line)] bg-[var(--vg-line)] sm:grid-cols-4">
        @foreach ([
            ['Live now', number_format($opinions->count()), 'not yet started'],
            ['Published', number_format($record['published']), 'all time'],
            [
                'Win rate',
                $record['win_rate'] === null ? '—' : number_format($record['win_rate'] * 100, 1).'%',
                $record['graded'] > 0 ? 'n = '.number_format($record['graded']) : 'nothing graded yet',
            ],
            [
                'Units',
                $record['units'] === null ? '—' : ($record['units'] > 0 ? '+' : '').number_format($record['units'], 2),
                $record['graded'] > 0 ? 'at one unit a pick' : 'nothing graded yet',
            ],
        ] as [$label, $value, $sample])
            <div class="bg-[var(--vg-navy)] px-5 py-3">
                <dt class="text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">{{ $label }}</dt>
                <dd class="mt-1 font-[family-name:var(--font-mono)] text-xl font-bold" data-numeric>{{ $value }}</dd>
                <dd class="mt-0.5 text-[11px] text-[var(--vg-muted)]">{{ $sample }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($opinions->isEmpty())
        {{--
            A real state and not a failure. Nothing qualified, or
            everything that did has already kicked off. §4 and the asset
            notes both say the product must be able to publish no pick
            rather than manufacture one to fill a page.
        --}}
        <div class="px-5 py-12 text-center">
            <p class="font-[family-name:var(--font-display)] font-semibold">No picks right now.</p>
            <p class="mx-auto mt-2 max-w-md text-sm text-[var(--vg-slate)]">
                Nothing on today's board met the engine's requirements, or everything that did has
                already started. We would rather show you none than invent one.
            </p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">
                    {{ config('brand.name') }}'s published opinions for games that have not started.
                </caption>
                <thead class="border-b border-[var(--vg-line)] text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">
                    <tr>
                        <th scope="col" class="px-5 py-2.5">Game</th>
                        <th scope="col" class="px-5 py-2.5">Pick</th>
                        <th scope="col" class="px-5 py-2.5 text-right">Odds</th>
                        <th scope="col" class="px-5 py-2.5">Confidence</th>
                        <th scope="col" class="px-5 py-2.5 text-right">Kickoff</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($opinions as $opinion)
                        <tr class="border-b border-[var(--vg-line)] transition last:border-0 hover:bg-[var(--vg-slate-dark)]">
                            <td class="px-5 py-3.5">
                                <span class="text-[11px] uppercase tracking-wide text-[var(--vg-slate)]">
                                    {{ strtoupper((string) $opinion->sport) }}
                                </span>
                                <span class="mt-0.5 block text-xs text-[var(--vg-slate)]">{{ $opinion->matchup }}</span>
                            </td>

                            <td class="px-5 py-3.5 font-semibold">
                                {{ $opinion->selection }}

                                @php($now = $standing?->get($opinion->id))

                                {{--
                                    The published number never changes; this is
                                    what the engine says today. Shown only when it
                                    has moved or the engine has stopped backing
                                    the selection, because a line saying nothing
                                    changed is noise on every other row.
                                --}}
                                @if ($now && (! $now['held'] || $now['drifted']))
                                    <span class="mt-1 block text-xs font-normal text-[var(--vg-amber)]">
                                        @if (! $now['held'])
                                            We no longer hold this
                                        @else
                                            Our view has moved
                                        @endif

                                        @if ($now['current'] !== null)
                                            &middot; now {{ round($now['current'] * 100) }}%
                                        @endif
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3.5 text-right font-[family-name:var(--font-mono)]" data-numeric>
                                {{ $opinion->price_american > 0 ? '+' : '' }}{{ $opinion->price_american }}
                            </td>

                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="w-10 shrink-0 font-[family-name:var(--font-mono)] text-sm font-bold" data-numeric>
                                        {{ $opinion->confidencePercent() }}%
                                    </span>

                                    {{--
                                        The bar is the same number again, not a
                                        second one. aria-hidden because a screen
                                        reader has already been told the figure
                                        and would otherwise hear it twice.
                                    --}}
                                    <span class="h-1.5 w-20 overflow-hidden rounded-full bg-[var(--vg-slate-dark)]" aria-hidden="true">
                                        <span class="block h-full rounded-full bg-[var(--vg-paper)]"
                                              style="width: {{ $opinion->confidencePercent() }}%"></span>
                                    </span>
                                </div>
                            </td>

                            <td class="px-5 py-3.5 text-right text-xs text-[var(--vg-slate)]" data-numeric>
                                <x-local-time :at="$opinion->kickoff_at" format="H:i" />
                            </td>
                        </tr>

                        {{--
                            Who the model expects to win, which is a different
                            sentence from what it would bet. A favourite priced
                            too short is still the honest answer to what is
                            likely to happen, and saying nothing about it reads
                            as having no view of the game at all.
                        --}}
                        @if ($now && $now['likely'])
                            <tr class="border-b border-[var(--vg-line)] last:border-0">
                                <td colspan="5" class="px-5 pb-3.5 pt-0 text-xs text-[var(--vg-slate)]">
                                    <span class="font-semibold uppercase tracking-wide">Likely winner</span>
                                    &middot; {{ $now['likely']['selection'] }}
                                    {{ round($now['likely']['prob'] * 100) }}%

                                    @if ($now['likely']['price'] !== null)
                                        at {{ $now['likely']['price'] > 0 ? '+' : '' }}{{ $now['likely']['price'] }}
                                    @endif

                                    @unless ($now['likely']['backable'])
                                        &middot; <span class="text-[var(--vg-slate)]">not a pick: the price is shorter than our number</span>
                                    @endunless
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <footer class="border-t border-[var(--vg-line)] px-5 py-3">
        <p class="text-[11px] leading-relaxed text-[var(--vg-muted)]">
            These are {{ config('brand.name') }}'s own model-based opinions. No market here has beaten
            the closing line, every one of these is graded in public, and nothing on this page is a
            guarantee.
        </p>
    </footer>
</section>
