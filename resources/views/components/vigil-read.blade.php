@props(['read'])

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
@endif
