@props(['read' => null, 'locked' => false])

@if ($read)
    <div class="mt-4 rounded-lg border border-[var(--vg-line)] bg-[var(--vg-night)] p-4">
        <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-semibold">{{ config('brand.name') }} Read</p>

            {{--
                A customer is owed the difference between the model's words
                and the template's. Said plainly rather than hidden: an
                explanation that quietly changed character is worse than
                one that says which it is.
            --}}
            <span @class(['badge', 'badge-edge' => $read->fromModel(), 'badge-pass' => ! $read->fromModel()])>
                {{ $read->fromModel() ? 'AI' : 'Plain' }}
            </span>
        </div>

        <p class="mt-2 text-sm leading-relaxed text-[var(--vg-slate)]">{{ $read->body }}</p>

        <p class="mt-3 text-[11px] text-[var(--vg-slate)]">
            Written from figures the engine recorded. Every number above appears in the data behind it.
        </p>
    </div>
@elseif ($locked)
    {{--
        The allowance has run out. Said outright, because a customer who
        cannot tell a used-up allowance from a missing explanation will
        reasonably conclude the product is broken.
    --}}
    <div class="mt-4 rounded-lg border border-dashed border-[var(--vg-line)] p-4 text-center">
        <p class="text-sm text-[var(--vg-slate)]">
            You have used today's {{ config('brand.name') }} Read.
        </p>
        <p class="mt-1 text-xs text-[var(--vg-slate)]">
            <a class="text-[var(--vg-blue)] underline"
               href="{{ route('upgrade', ['feature' => 'card.daily']) }}">A Read on every pick</a>
            comes with a paid plan. The one you have already opened today stays open.
        </p>
    </div>
@endif
