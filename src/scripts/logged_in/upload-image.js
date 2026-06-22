onLoaded(() => {
    const ta = $('textarea')
    if (!ta) return
    ta.on('dragover', (ev) => cancel(ev))
    ta.on('drop', (ev) => {
        cancel(ev)

        const files = ev.dataTransfer.files;
        if (files.length > 0) {
            handleFiles(files, ta)
        }
    })
    ta.on('paste', (ev) => {
        const files = clipboardImageFiles(ev.clipboardData || window.clipboardData)
        if (files.length === 0) return // let other paste handlers run (e.g. links)

        cancel(ev)
        handleFiles(files, ta)
    })
})

/**
 * Extract image files from a paste event's clipboard data.
 *
 * Pasted screenshots arrive as a file item with a generic or empty name, so each
 * is given a unique name with a real image extension. This keeps the upload
 * endpoint's extension check happy and stops repeated pastes overwriting each
 * other server-side (the filename is hashed into the stored path).
 *
 * @param {DataTransfer|null} clipboardData
 * @returns {File[]}
 */
function clipboardImageFiles(clipboardData) {
    const items = clipboardData?.items
    if (!items) return []

    const files = []
    for (const item of items) {
        if (item.kind !== 'file' || !item.type.startsWith('image/')) continue
        const file = item.getAsFile()
        if (!file) continue

        const ext = file.type.split('/')[1] || 'png'
        const name = `pasted-${Date.now()}-${files.length}.${ext}`
        files.push(new File([file], name, { type: file.type }))
    }
    return files
}

/**
 * Handle files dropped into the textarea.
 *
 * @param {FileList} files
 * @param {HTMLElement} textarea
 */
function handleFiles(files, textarea) {
    const formData = new FormData()
    for (const file of files) {
        formData.append('imageFiles[]', file)
    }
    const text = textarea.value
    const cursor = textarea.selectionStart
    fetch('/upload', {
        method: 'POST', body: formData
    })
        .then(response => response.json())
        .then(data => {
            const markdown = data.replace(/!\[[^\]]*\]/g, '![]')
            textarea.value = text.slice(0, cursor) + markdown + text.slice(cursor)
            const altStart = cursor + markdown.indexOf('![') + 2
            textarea.setSelectionRange(altStart, altStart)
            textarea.dispatchEvent(new Event('input'))
        })
        .catch(error => console.error(error))
}
