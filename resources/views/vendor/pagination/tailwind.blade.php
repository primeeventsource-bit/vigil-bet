{{--
    The paginator, in the product's own palette.

    Laravel's shipped Tailwind paginator is hardcoded bg-white and
    text-gray-800, with its dark variants behind `dark:` - which Tailwind
    compiles to @media (prefers-color-scheme: dark).

    That was survivable while the whole stylesheet followed the same
    media query: a visitor on a light machine got a light page and a
    light paginator, and the two at least agreed. Dark is the default
    now, so the shipped view would put white buttons on a black page for
    everybody whose operating system is set to light - and it would do it
    on eight pages, including the public record.

    So it is overridden rather than patched around. Every colour here is
    a token, which means the paginator follows a rebrand like everything
    else, and it reads from data-theme like everything else.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination"
         class="flex items-center justify-between gap-4">
        {{-- Previous / next, which are the whole control on a phone. --}}
        <div class="flex flex-1 justify-between sm:hidden">
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
        </div>

        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <p class="font-[family-name:var(--font-mono)] text-[11px] text-[var(--vg-muted)]">
                {!! __('Showing') !!}
                <span class="text-[var(--vg-slate)]">{{ $paginator->firstItem() }}</span>
                {!! __('to') !!}
                <span class="text-[var(--vg-slate)]">{{ $paginator->lastItem() }}</span>
                {!! __('of') !!}
                <span class="text-[var(--vg-slate)]">{{ $paginator->total() }}</span>
                {!! __('results') !!}
            </p>

            <span class="flex items-center gap-1">
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}"
                          class="cursor-default rounded-lg border border-[var(--vg-line)] px-2.5 py-1.5 text-xs text-[var(--vg-muted)]">&lsaquo;</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('pagination.previous') }}"
                       class="rounded-lg border border-[var(--vg-line)] px-2.5 py-1.5 text-xs text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)]">&lsaquo;</a>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span aria-disabled="true"
                              class="px-2 py-1.5 text-xs text-[var(--vg-muted)]">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                {{-- aria-current, not colour alone. --}}
                                <span aria-current="page"
                                      class="rounded-lg border border-[var(--vg-line)] bg-[var(--vg-slate-dark)] px-2.5 py-1.5 font-[family-name:var(--font-mono)] text-xs font-bold text-[var(--vg-paper)]">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}"
                                   class="rounded-lg border border-[var(--vg-line)] px-2.5 py-1.5 font-[family-name:var(--font-mono)] text-xs text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)]">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('pagination.next') }}"
                       class="rounded-lg border border-[var(--vg-line)] px-2.5 py-1.5 text-xs text-[var(--vg-slate)] transition hover:text-[var(--vg-paper)]">&rsaquo;</a>
                @else
                    <span aria-disabled="true" aria-label="{{ __('pagination.next') }}"
                          class="cursor-default rounded-lg border border-[var(--vg-line)] px-2.5 py-1.5 text-xs text-[var(--vg-muted)]">&rsaquo;</span>
                @endif
            </span>
        </div>
    </nav>
@endif
