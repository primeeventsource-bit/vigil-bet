/**
 * The league filter on the public board.
 *
 * Progressive enhancement, the same bargain nav.js and theme.js make:
 * the chips ship `hidden` and this reveals them. With scripting off,
 * every game on the board is visible - which is the correct unfiltered
 * state, rather than a row of controls that do nothing.
 *
 * Filtering, not fetching. The games are already in the document, so
 * pressing a chip hides rows instead of asking the server for a subset.
 * That keeps it instant, keeps it working on a bad connection, and means
 * there is no loading state to design because there is no load.
 *
 * What it owes a keyboard and a screen reader: the chips are buttons
 * with `aria-pressed`, so their state is announced rather than only
 * painted, and the count is written to a live region so pressing one
 * says what it did instead of silently changing the page underneath.
 */

export function startBoard() {
    const filters = document.querySelector('[data-board-filters]')
    const list = document.querySelector('[data-board-list]')

    if (!filters || !list) {
        return
    }

    const chips = [...filters.querySelectorAll('[data-board-filter]')]
    const games = [...list.querySelectorAll('[data-board-game]')]
    const status = document.querySelector('[data-board-status]')

    if (chips.length === 0 || games.length === 0) {
        return
    }

    // Nothing below this line runs without JavaScript, so the controls
    // are only shown once they are known to work.
    filters.hidden = false

    function apply(league) {
        let shown = 0

        for (const game of games) {
            const match = league === 'all' || game.dataset.league === league

            // `hidden` rather than a class: it removes the row from the
            // accessibility tree too, so a filtered-out game is not read
            // out by a screen reader that cannot see it is gone.
            game.hidden = !match

            if (match) {
                shown++
            }
        }

        for (const chip of chips) {
            chip.setAttribute(
                'aria-pressed',
                chip.dataset.boardFilter === league ? 'true' : 'false',
            )
        }

        if (status) {
            const label = league === 'all' ? 'all leagues' : league.toUpperCase()

            status.textContent = `Showing ${shown} ${shown === 1 ? 'game' : 'games'}, ${label}.`
        }
    }

    for (const chip of chips) {
        chip.addEventListener('click', () => apply(chip.dataset.boardFilter))
    }
}
