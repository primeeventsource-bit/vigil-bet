@props(['label', 'value', 'sample' => null, 'tone' => 'ink', 'locked' => false, 'href' => null])

{{--
    A headline number. `sample` is not decoration: a rate without its
    sample size is a number pretending to be evidence, and the spec
    forbids showing one. When there is no sample there is no rate - the
    tile shows an em dash and says so.

    `locked` is the other kind of absence: the figure exists and this
    customer's plan does not include it. Said with a lock rather than an
    em dash, because "we have not measured this" and "you cannot see
    this" are different facts and a customer deserves to know which.
--}}
<div class="panel px-5 py-4">
    @if ($locked)
        <p class="flex items-center gap-1.5 font-[family-name:var(--font-mono)] text-2xl font-bold text-[var(--vg-slate)]">
            <x-app.icon name="lock" class="h-4 w-4" aria-hidden="true" />
            <span class="sr-only">Not on your plan</span>
        </p>
    @else
        <p @class([
            'font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums',
            'text-[var(--vg-edge)]' => $tone === 'edge',
            'text-[var(--vg-loss)]' => $tone === 'loss',
            'text-[var(--vg-paper)]' => $tone === 'ink',
        ]) data-numeric>{{ $value ?? '—' }}</p>
    @endif

    <p class="mt-1 text-xs text-[var(--vg-slate)]">{{ $label }}</p>

    @if ($sample !== null)
        <p class="mt-0.5 text-[11px] text-[var(--vg-slate)]" data-numeric>
            @if ($locked && $href)
                <a class="text-[var(--vg-link)] underline" href="{{ $href }}">{{ $sample }}</a>
            @else
                {{ $sample }}
            @endif
        </p>
    @endif
</div>
