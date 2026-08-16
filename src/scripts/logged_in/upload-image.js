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
    fetch(appPath('/upload'), {
        method: 'POST', body: formData
    })
        // A refusal carries a JSON *string* message, not markdown — hence the
        // ok check. The body is read either way so the message can be shown.
        .then(response => response.json().catch(() => null).then(body => {
            if (!response.ok) {
                throw new Error(uploadErrorMessage(body, response.status))
            }
            return body
        }))
        .then(data => {
            const markdown = data.replace(/!\[[^\]]*\]/g, '![]')
            textarea.value = text.slice(0, cursor) + markdown + text.slice(cursor)
            const altStart = cursor + markdown.indexOf('![') + 2
            textarea.setSelectionRange(altStart, altStart)
            textarea.dispatchEvent(new Event('input'))
            clearUploadError()
        })
        .catch(error => {
            console.error(error)
            showUploadError(textarea, error.message)
        })
}

/**
 * The message to show for a refused upload: the endpoint's own explanation when
 * it sent one, otherwise the bare status so the author still gets something
 * actionable.
 *
 * @param {*} body - The parsed response body (a string for this endpoint's errors).
 * @param {number} status - The HTTP status code.
 * @returns {string}
 */
function uploadErrorMessage(body, status) {
    return typeof body === 'string' && body.trim() !== ''
        ? body.trim()
        : `Upload failed (${status})`
}

/**
 * Shows an upload failure above the entry form.
 *
 * Uploads happen without a page load, so the server-rendered $_SESSION['flash']
 * messages can't carry this — but reusing their `.flash` class means the notice
 * is styled by whichever theme is active, with no new CSS. Replacing the text of
 * an existing notice keeps repeated failures from stacking up.
 *
 * @param {HTMLElement} textarea - The textarea the upload was started from.
 * @param {string} message
 */
function showUploadError(textarea, message) {
    const anchor = textarea.closest('form') || textarea
    let flash = $('.flash.upload-error')
    if (!flash) {
        flash = document.createElement('div')
        flash.className = 'flash upload-error'
        flash.setAttribute('role', 'alert')
        anchor.parentNode.insertBefore(flash, anchor)
    }
    flash.textContent = `⚠ ${message}`
}

/**
 * Removes a previous upload failure notice once an upload succeeds.
 */
function clearUploadError() {
    $('.flash.upload-error')?.remove()
}
