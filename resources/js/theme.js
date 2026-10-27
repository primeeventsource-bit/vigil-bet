/*
 * The dark/light switch.
 *
 * Two states, where there were three. "System" was a real choice while
 * the stylesheet followed prefers-color-scheme; it stopped being one
 * when dark became the default, because "follow your system" and "dark"
 * then did the same thing and a control offering both would be lying
 * about one of them.
 *
 * Dark is the product's face. Light is a thing somebody asks for, and
 * once asked for it is remembered.
 *
 * The attribute is already on <html> by the time this runs - an inline
 * script in the head sets it before the first paint, because a deferred
 * module runs after the browser has painted and the result is a flash of
 * the wrong theme on every page load. This file only handles the clicking.
 */

const KEY = 'vigil.theme'
const ORDER = ['dark', 'light']

const LABELS = {
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
        return localStorage.getItem(KEY) === 'light' ? 'light' : 'dark'
    } catch (e) {
        return 'dark'
    }
}

function remember(mode) {
    try {
        // Dark is the default, so choosing it is the absence of a
        // preference rather than a preference. Storing it would pin a
        // visitor to today's default if the default ever moved.
        mode === 'dark' ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, mode)
    } catch (e) {
        // Nothing to do. The choice holds for this page and not beyond it.
    }
}

function apply(mode) {
    document.documentElement.setAttribute('data-theme', mode)
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
