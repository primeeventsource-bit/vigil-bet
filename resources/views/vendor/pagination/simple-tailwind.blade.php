{{--
    The simple paginator, overridden for the reason given in full in
    tailwind.blade.php beside this: the shipped view is hardcoded white
    with its dark variants behind prefers-color-scheme, and dark is no
    longer a system preference here.

    Nothing in the product calls simplePaginate() today. It is here
    anyway, because the next person who does would otherwise get white
    buttons on a black page and no reason to suspect the paginator.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center gap-2">
        @if ($paginator->onFirstPage())
            <span class="cursor-default rounded-lg border border-[var(--vg-line)] px-3 py-2 text-xs font-semibold text-[var(--vg-muted)]">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev"
               class="rounded-lg border border-[var(--vg-line)] px-3 py-2 text-xs font-semibold text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)]">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next"
               class="rounded-lg border border-[var(--vg-line)] px-3 py-2 text-xs font-semibold text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)]">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="cursor-default rounded-lg border border-[var(--vg-line)] px-3 py-2 text-xs font-semibold text-[var(--vg-muted)]">
                {!! __('pagination.next') !!}
            </span>
        @endif
    </nav>
@endif
