/*
 * The trial countdown.
 *
 * The expiry is rendered by the server as an ISO-8601 UTC string and
 * this only ever formats the difference. It never decides anything: the
 * clock reaching zero does not revoke access, because access was never
 * this script's to grant. The server compares the same timestamp on
 * every request, so a clock set wrong, a tab left open overnight or a
 * console with the element deleted all change nothing but the text.
 *
 * Ships hidden and reveals itself, the same bargain nav.js and theme.js
 * make. Without scripting the markup still carries the expiry as a plain
 * sentence, so nobody is left with an empty box where a time should be.
 */

function parts(ms) {
    const total = Math.max(0, Math.floor(ms / 1000))

    return {
        hours: Math.floor(total / 3600),
        minutes: Math.floor((total % 3600) / 60),
        seconds: total % 60,
    }
}

function render(el, expiresAt) {
    const left = expiresAt - Date.now()

    if (left <= 0) {
        // Said in words. A row of zeros reads as a broken widget, and
        // "expired" is the one state somebody needs to understand
        // without counting digits.
        el.textContent = 'Trial ended'
        el.classList.add('is-expired')

        return false
    }

    const { hours, minutes, seconds } = parts(left)

    el.textContent = hours > 0
        ? `${hours}h ${String(minutes).padStart(2, '0')}m left`
        : `${minutes}m ${String(seconds).padStart(2, '0')}s left`

    return true
}

export function startTrialCountdown() {
    const elements = document.querySelectorAll('[data-trial-expires]')

    if (elements.length === 0) {
        return
    }

    elements.forEach((el) => {
        const expiresAt = Date.parse(el.dataset.trialExpires)

        if (Number.isNaN(expiresAt)) {
            // An unparseable timestamp leaves the server-rendered
            // sentence in place rather than replacing it with NaN.
            return
        }

        el.removeAttribute('hidden')

        if (!render(el, expiresAt)) {
            return
        }

        /*
         * Once a second under an hour, once a minute above it. A
         * per-second repaint of "19h 42m" is work nobody can see, and
         * on a page left open all day it is work done sixty times for
         * every change.
         */
        const tick = () => {
            if (!render(el, expiresAt)) {
                clearInterval(timer)
            }
        }

        const interval = expiresAt - Date.now() > 3600_000 ? 60_000 : 1_000
        const timer = setInterval(tick, interval)
    })
}
