/*
 * The dark/light switch.
 *
 * Three states and not two. "System" is a real choice rather than the
 * absence of one: somebody who flips their machine to light at dusk gets
 * a light app without coming back here, and that stops being true the
 * moment they pin a mode, which is the point of pinning it.
 *
 * The attribute is already on <html> by the time this runs - an inline
 * script in the head sets it before the first paint, because a deferred
 * module runs after the browser has painted and the result is a flash of
 * the wrong theme on every page load. This file only handles the clicking.
 */

const KEY = 'vigil.theme'
const ORDER = ['system', 'light', 'dark']

const LABELS = {
    system: 'Colour theme: following your system. Change it.',
    light: 'Colour theme: light. Change it.',
    dark: 'Colour theme: dark. Change it.',
}

/**
 * localStorage throws rather than returning null in a private window with
 * site data blocked, so every touch of it is guarded. A theme preference
 * is never worth breaking a page over.
 */
function stored() {
    try {
        const value = localStorage.getItem(KEY)

        return value === 'light' || value === 'dark' ? value : 'system'
    } catch (e) {
        return 'system'
    }
}

function remember(mode) {
    try {
        mode === 'system' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, mode)
    } catch (e) {
        // Nothing to do. The choice holds for this page and not beyond it.
    }
}

function apply(mode) {
    const root = document.documentElement

    if (mode === 'system') {
        root.removeAttribute('data-theme')
    } else {
        root.setAttribute('data-theme', mode)
    }
}

function paint(button, mode) {
    const label = button.querySelector('[data-theme-label]')

    if (label) {
        label.textContent = LABELS[mode]
    }

    button.querySelectorAll('[data-theme-icon]').forEach((icon) => {
        icon.toggleAttribute('hidden', icon.dataset.themeIcon !== mode)
    })
}

export function startTheme() {
    const buttons = document.querySelectorAll('[data-theme-toggle]')

    if (buttons.length === 0) {
        return
    }

    let mode = stored()

    buttons.forEach((button) => {
        // Rendered hidden so it is never a dead control for somebody with
        // scripting off. Revealed now, because it works now.
        button.removeAttribute('hidden')
        paint(button, mode)

        button.addEventListener('click', () => {
            mode = ORDER[(ORDER.indexOf(mode) + 1) % ORDER.length]

            apply(mode)
            remember(mode)
            buttons.forEach((other) => paint(other, mode))
        })
    })
}
