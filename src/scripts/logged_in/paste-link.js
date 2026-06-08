onLoaded(() => {
    const ta = $('textarea')
    if (!ta) return
    ta.on('paste', (ev) => {
        const start = ta.selectionStart
        const end = ta.selectionEnd
        if (start === end) return // nothing selected

        // iOS Safari returns an empty string for getData('text'); the
        // standards-compliant 'text/plain' type works there, so try it first.
        const clipboard = ev.clipboardData || window.clipboardData
        const pasted = clipboard.getData('text/plain') || clipboard.getData('text')
        if (!isUrl(pasted)) return

        cancel(ev)

        const selected = ta.value.slice(start, end)
        const markdown = `[${selected}](${pasted.trim()})`
        ta.setRangeText(markdown, start, end, 'end')
        ta.dispatchEvent(new Event('input'))
    })
})

/**
 * Tests whether a string is a single http(s) URL.
 *
 * @param {string} str - The candidate string.
 * @returns {boolean} True when str is one http(s) URL with no surrounding whitespace.
 */
function isUrl(str) {
    const trimmed = str.trim()
    if (!trimmed || /\s/.test(trimmed)) return false
    try {
        const url = new URL(trimmed)
        return url.protocol === 'http:' || url.protocol === 'https:'
    } catch {
        return false
    }
}
