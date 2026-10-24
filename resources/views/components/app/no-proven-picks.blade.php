@props(['nearMisses', 'reads' => null, 'research' => null])

{{--
    What a customer sees while no market is proven, which is every market.

    §4 calls this a feature rather than an empty state, and it was neither
    until now: a selection, a price, and the engine's own pass reason -
    machinery leaking onto the page - while form, availability and rest
    sat in the database unread.

    So it leads with the research and the Read, and the analytics are
    gone. No edge percentage, no verdict string, no model version, no
    suggested stake, no pass-reason code. None of it helps somebody
    deciding whether to back a team, and all of it invites being read as
    a precision the engine does not have.

    One thing stays, in words rather than jargon: these are not proven
    picks. Without that an educated guess reads as a validated edge,
    which is the one claim §0.7 forbids and the only reason the rest of
    this page can be trusted.
--}}
@php
    $reads = $reads ?? collect();
    $research = $research ?? collect();
@endphp

<div class="p-6">
    <h2 class="font-[family-name:var(--font-display)] text-lg font-semibold">{{ config('brand.name') }}'s opinion today</h2>

    {{--
        Framed as an opinion, which is what it is, and the analytics are
        off the page - no edge percentage, no verdict string, no model
        version, no stake, no confidence figure dressed as precision.

        "No proven picks today" stays, in those words. It is not a
        disclaimer about promises - §0.3 asks for the label because an
        unproven market must appear as analysis rather than a pick, and
        because the brand is a model: an opinion from the product reads as the
        model's considered output, so what the model has been measured to
        do is part of describing the opinion rather than a hedge against
        it. Five tests assert this line.
    --}}
    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-[var(--vg-slate)]">
        <strong class="text-[var(--vg-paper)]">No proven picks today.</strong>
        These are the games {{ config('brand.name') }} came closest to backing, with what is known about both
        sides. Read them as <strong class="text-[var(--vg-paper)]">an opinion</strong>,
        not a pick - the model behind them has not yet beaten the market's own price.
    </p>

    @if ($nearMisses->isEmpty())
        <p class="mt-4 rounded-lg border border-dashed border-[var(--vg-line)] px-4 py-8 text-center text-xs text-[var(--vg-slate)]">
            Nothing has been scanned yet.
        </p>
    @else
        <ul class="mt-5 space-y-4">
            @foreach ($nearMisses as $miss)
                @php
                    $brief = $research->get($miss->id, []);
                    $read = $reads->get($miss->id);
                @endphp

                <li class="rounded-lg border border-[var(--vg-line)] p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            {{--
                                Labelled a pass, every time it is shown. §0.3
                                requires an unproven market to appear labelled
                                and never as a pick, so this is not decoration
                                that the redesign gets to drop.
                            --}}
                            <p class="badge badge-pass">Pass</p>
                            <p class="mt-2 truncate font-[family-name:var(--font-display)] font-semibold">
                                {{ $miss->selection }}
                            </p>
                            <p class="mt-0.5 text-xs uppercase tracking-wide text-[var(--vg-slate)]">
                                {{ strtoupper((string) $miss->sport) }} · {{ $miss->market }}
                            </p>
                            {{--
                                Why the engine declined. This is what the daily
                                card sells - a free account is told there is
                                nothing today and no more - so it stays here
                                rather than being tidied away as jargon.
                            --}}
                            @if ($miss->reason)
                                <p class="mt-1 text-xs text-[var(--vg-slate)]">{{ $miss->reason }}</p>
                            @endif
                        </div>

                        @if ($miss->best_price_american)
                            {{-- The price is the one number a customer needs: it is
                                 what they would actually be offered. --}}
                            <span class="shrink-0 font-[family-name:var(--font-mono)] text-sm tabular-nums" data-numeric>
                                {{ $miss->best_price_american > 0 ? '+' : '' }}{{ $miss->best_price_american }}
                            </span>
                        @endif
                    </div>

                    @if ($read !== null)
                        {{-- The Read already weaves the research together and says
                             in its own words that the number has not seen it. --}}
                        <p class="mt-3 text-sm leading-relaxed text-[var(--vg-paper)]">{{ $read->body }}</p>
                    @endif

                    @if ($brief !== [])
                        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-3">
                            @foreach ([
                                'Last 5' => $brief['home_form_record'] ?? null,
                                'Scoring' => isset($brief['home_form_points_for'])
                                    ? $brief['home_form_points_for'].' for, '.($brief['home_form_points_against'] ?? '?').' against'
                                    : null,
                                'Rest' => isset($brief['home_rest_days']) ? $brief['home_rest_days'].' days' : null,
                            ] as $label => $value)
                                @if ($value !== null)
                                    <div>
                                        <dt class="text-[var(--vg-slate)]">{{ $label }}</dt>
                                        <dd class="mt-0.5 font-medium">{{ $value }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>

                        {{-- Availability is the research most likely to change a
                             mind, so it gets its own line rather than a cell. --}}
                        @foreach (['home' => 'Home', 'away' => 'Away'] as $side => $sideLabel)
                            @if (($brief[$side.'_unavailable'] ?? null) !== null)
                                <p class="mt-2 text-xs text-[var(--vg-slate)]">
                                    <span class="text-[var(--vg-paper)]">{{ $sideLabel }} out:</span>
                                    {{ $brief[$side.'_unavailable'] }}
                                    @if (($brief[$side.'_questionable_count'] ?? 0) > 0)
                                        · {{ $brief[$side.'_questionable_count'] }} questionable
                                    @endif
                                </p>
                            @endif
                        @endforeach
                    @endif

                    @if ($read === null && $brief === [])
                        {{-- Said plainly rather than dressed up. A card with no
                             research behind it should look like one. --}}
                        <p class="mt-3 text-xs text-[var(--vg-slate)]">
                            We have no form or availability data for this game yet.
                        </p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
