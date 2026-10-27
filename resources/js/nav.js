/**
 * The navigation drawer, for the widths the sidebar does not fit.
 *
 * The sidebar is `lg` and up only, which left every narrower viewport
 * with no navigation at all beyond the logo - the dashboard, the parlay
 * builder, the bets, all reachable only by typing the URL. This is the
 * off-canvas half of it.
 *
 * Progressive enhancement, the same way the theme toggle does it: the
 * button ships hidden and this reveals it. A control that does nothing
 * when scripting is off is worse than no control, and the desktop
 * sidebar is unaffected either way.
 *
 * What the drawer owes a keyboard: the button says whether it is open,
 * focus moves into the panel and comes back to the button, Escape
 * closes, and while it is open the page behind does not scroll.
 */

const OPEN = 'nav-open'

export function startNav() {
    const toggle = document.querySelector('[data-nav-toggle]')
    const panel = document.querySelector('[data-nav-panel]')
    const backdrop = document.querySelector('[data-nav-backdrop]')

    if (!toggle || !panel) {
        return
    }

    // Nothing below this line runs for a visitor with no JavaScript, so
    // the button is only shown once it is known to work.
    toggle.hidden = false

    const isOpen = () => document.documentElement.classList.contains(OPEN)

    function open() {
        document.documentElement.classList.add(OPEN)
        toggle.setAttribute('aria-expanded', 'true')
        backdrop?.removeAttribute('hidden')

        // Into the panel, so the next Tab is a nav link rather than
        // whatever happened to follow the button in the header.
        panel.querySelector('a, button')?.focus()
    }

    function close({ restoreFocus = true } = {}) {
        document.documentElement.classList.remove(OPEN)
        toggle.setAttribute('aria-expanded', 'false')
        backdrop?.setAttribute('hidden', '')

        if (restoreFocus) {
            toggle.focus()
        }
    }

    toggle.addEventListener('click', () => (isOpen() ? close() : open()))
    backdrop?.addEventListener('click', () => close())

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            close()
        }
    })

    /*
     * Follow the sidebar's own breakpoint back up. Crossing into `lg`
     * with the drawer still "open" would otherwise leave the overlay
     * state applied underneath a sidebar that is now permanently
     * visible, and the scroll lock with it.
     */
    const wide = window.matchMedia('(min-width: 64rem)')

    wide.addEventListener('change', (event) => {
        if (event.matches && isOpen()) {
            close({ restoreFocus: false })
        }
    })

    /*
     * A link inside the drawer navigates, which on a same-document
     * anchor would leave it open over the content it just moved to.
     */
    panel.addEventListener('click', (event) => {
        if (event.target.closest('a') && !wide.matches) {
            close({ restoreFocus: false })
        }
    })
}
