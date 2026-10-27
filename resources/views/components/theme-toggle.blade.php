{{--
    The dark/light switch.

    Two states, not three. There was a "system" setting while the
    stylesheet followed prefers-color-scheme; dark is the default now,
    so "follow your system" and "dark" would do the same thing and a
    control offering both would be lying about one of them.

    No icon alone. The button carries a label for assistive technology
    and announces the current state through aria-pressed-style text, so
    the control is not a shape whose meaning has to be guessed.

    The button is rendered with no JavaScript having run yet, so it starts
    hidden and the script below reveals it. A switch that does nothing
    when scripting is off is worse than no switch: the page is perfectly
    readable in the dark it already arrived in.
--}}
<button type="button"
        data-theme-toggle
        hidden
        class="flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--vg-line)] text-[var(--vg-slate)] transition-colors hover:text-[var(--vg-paper)]"
        aria-live="polite">
    <span class="sr-only" data-theme-label>Change colour theme</span>

    <x-app.icon name="moon" class="h-4 w-4" data-theme-icon="dark" />
    <x-app.icon name="sun" class="h-4 w-4" data-theme-icon="light" hidden />
</button>
