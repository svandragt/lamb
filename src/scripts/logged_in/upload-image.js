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
})

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
            textarea.value = text.slice(0, cursor) + data + text.slice(cursor)
            textarea.dispatchEvent(new Event('input'))
        })
        .catch(error => console.error(error))
}
