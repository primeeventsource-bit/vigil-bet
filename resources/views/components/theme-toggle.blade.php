{{--
    The dark/light switch.

    Three states, not two. "System" is the default and is a real choice
    rather than the absence of one: a visitor who has never touched this
    gets the mode their machine asks for, and somebody who flips their OS
    to light at dusk gets a light app without having to come back here.
    Choosing dark or light pins it and stops the system being consulted.

    No icon alone. The button carries a label for assistive technology
    and announces the current state through aria-pressed-style text, so
    the control is not a shape whose meaning has to be guessed.

    The button is rendered with no JavaScript having run yet, so it starts
    hidden and the script below reveals it. A switch that does nothing
    when scripting is off is worse than no switch: the page is perfectly
    usable in whichever mode the system asked for.
--}}
<button type="button"
        data-theme-toggle
        hidden
        class="flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--vg-line)] text-[var(--vg-slate)] transition-colors hover:text-[var(--vg-paper)]"
        aria-live="polite">
    <span class="sr-only" data-theme-label>Change colour theme</span>

    <x-app.icon name="moon" class="h-4 w-4" data-theme-icon="dark" />
    <x-app.icon name="sun" class="h-4 w-4" data-theme-icon="light" hidden />
    <x-app.icon name="cog" class="h-4 w-4" data-theme-icon="system" hidden />
</button>
