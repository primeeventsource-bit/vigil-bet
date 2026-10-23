@props(['label', 'value', 'sample' => null, 'tone' => 'ink'])

{{--
    A headline number. `sample` is not decoration: a rate without its
    sample size is a number pretending to be evidence, and the spec
    forbids showing one. When there is no sample there is no rate - the
    tile shows an em dash and says so.
--}}
<div class="panel px-5 py-4">
    <p @class([
        'font-[family-name:var(--font-mono)] text-2xl font-bold tabular-nums',
        'text-[var(--vg-edge)]' => $tone === 'edge',
        'text-[var(--vg-loss)]' => $tone === 'loss',
        'text-[var(--vg-paper)]' => $tone === 'ink',
    ]) data-numeric>{{ $value }}</p>

    <p class="mt-1 text-xs text-[var(--vg-slate)]">{{ $label }}</p>

    @if ($sample !== null)
        <p class="mt-0.5 text-[11px] text-[var(--vg-slate)]" data-numeric>{{ $sample }}</p>
    @endif
</div>
