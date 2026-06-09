onLoaded(() => {
    for (const box of $$('input[type=checkbox][data-checkbox-index]')) {
        box.disabled = false
        box.on('change', () => saveCheckbox(box))
    }
})

/**
 * Persist a task-list checkbox toggle as a post edit.
 *
 * Posts the owning post id (from the nearest [data-post-id] ancestor), the
 * checkbox's document-order index, and its new state to /checkbox. On failure
 * the checkbox is reverted so the UI keeps matching the stored source.
 *
 * @param {HTMLInputElement} box
 */
function saveCheckbox(box) {
    const article = box.closest('[data-post-id]')
    if (!article) return

    const checked = box.checked
    const body = new FormData()
    body.append('id', article.dataset.postId)
    body.append('index', box.dataset.checkboxIndex)
    body.append('checked', checked ? '1' : '0')

    box.disabled = true
    fetch('/checkbox', { method: 'POST', body })
        .then(response => response.ok ? response.json() : Promise.reject(response.status))
        .then(data => {
            if (!data.ok) return Promise.reject('save failed')
            box.disabled = false
        })
        .catch(error => {
            console.error(error)
            box.checked = !checked
            box.disabled = false
        })
}
