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
        let pasted = clipboard.getData('text/plain') || clipboard.getData('text')
        // iOS "Copy Link" puts the URL in text/uri-list and leaves text/plain empty.
        if (!isUrl(pasted)) pasted = firstUri(clipboard.getData('text/uri-list'))
        if (!isUrl(pasted)) return

        cancel(ev)

        const selected = ta.value.slice(start, end)
        const markdown = `[${selected}](${pasted.trim()})`
        ta.setRangeText(markdown, start, end, 'end')
        ta.dispatchEvent(new Event('input'))
    })
})

/**
 * Returns the first URI in a text/uri-list value, skipping blank and
 * `#` comment lines (RFC 2483).
 *
 * @param {string} list - The text/uri-list clipboard data.
 * @returns {string} The first URI line, or an empty string when there is none.
 */
function firstUri(list) {
    const line = list.split(/\r?\n/).find((l) => l.trim() && !l.trim().startsWith('#'))
    return line ? line.trim() : ''
}

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
