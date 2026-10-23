@props(['notice'])

{{--
    Never stale data presented as live.

    Shown above anything read from Edge Lab whenever the sync is behind.
    It says how old the data is rather than which of our feeds broke: a
    customer about to place a bet needs to know whether to trust the
    screen, not our plumbing.
--}}
@if ($notice)
    <p role="status"
       class="mb-6 flex items-start gap-2 rounded-md border border-[var(--vg-warning)] bg-[color-mix(in_srgb,var(--vg-warning)_10%,transparent)] px-4 py-3 text-sm">
        <span class="badge badge-warning shrink-0">Delayed</span>
        <span class="text-[var(--vg-paper)]">{{ $notice }}</span>
    </p>
@endif
