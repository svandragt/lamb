/**
 * Narrows the Redirections tab as you type, matching the old and the new path.
 * The filter box ships hidden, so a visitor without JavaScript sees the full list.
 */
onLoaded(() => {
    const input = $('#redirects-filter-input')
    if (!input) {
        return
    }
    const status = $('#redirects-filter-status')
    const rows = [...$$('#settings-panel-redirects tbody tr')]

    $('#redirects-filter').hidden = false
    input.on('input', () => {
        const query = input.value.trim().toLowerCase()
        let shown = 0
        for (const row of rows) {
            row.hidden = ![...row.cells].some(cell => cell.textContent.toLowerCase().includes(query))
            shown += row.hidden ? 0 : 1
        }
        if (!query) {
            status.textContent = ''
        } else if (!shown) {
            status.textContent = 'No redirects match'
        } else {
            status.textContent = `${shown} of ${rows.length} redirects`
        }
    })
})
