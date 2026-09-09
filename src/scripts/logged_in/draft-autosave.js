onLoaded(() => {
    for (const form of [$('#entry'), $('#editform')]) {
        if (!form || form.dataset.autosaveBound) continue
        const ta = form.querySelector('textarea')
        const key = autosaveKey(form)
        if (!ta || !key) continue
        form.dataset.autosaveBound = '1'

        if (form.id === 'editform') {
            offerRestore(ta, key)
        } else {
            restore(ta, key)
        }

        let timer = null
        // ponytail: last-write-wins if the same draft is open in two tabs —
        // no cross-tab merge, upgrade to a storage 'input' listener if that
        // ever bites.
        ta.on('input', () => {
            clearTimeout(timer)
            timer = setTimeout(() => save(ta, key), 400)
        })
        // Cancel the pending save first: submit doesn't unload the page
        // instantly (the entry POST is multipart), so a timer that fires after
        // removeItem would re-persist the just-published text as a draft.
        form.on('submit', () => {
            clearTimeout(timer)
            removeItem(key)
        })
    }
})

/**
 * The localStorage key a form's draft is stored under, or null when none
 * applies (an unrecognised form).
 *
 * @param {HTMLFormElement} form
 * @returns {string|null}
 */
function autosaveKey(form)
{
    if (form.id === 'entry') return 'lamb:autosave:new'
    if (form.id === 'editform') {
        const id = form.querySelector('input[name="id"]')?.value
        return id ? `lamb:autosave:edit:${id}` : null
    }
    return null
}

/**
 * Restores a stored draft into the textarea, but only while it's still empty
 * — a server-prefilled edit body or a preload_text() draft must never be
 * clobbered.
 *
 * @param {HTMLTextAreaElement} ta
 * @param {string} key
 * @returns {void}
 */
function restore(ta, key)
{
    const stored = getItem(key)
    if (stored && ta.value.trim() === '') {
        ta.value = stored
        ta.dispatchEvent(new Event('input'))
    }
}

/**
 * On the edit form the textarea is pre-filled with the server's post body, so
 * a stored draft can never be restored silently — the server body may be
 * newer (the post was edited elsewhere) than a stale local draft. Instead,
 * surface a link that lets the author pull the draft in explicitly.
 *
 * @param {HTMLTextAreaElement} ta
 * @param {string} key
 * @returns {void}
 */
function offerRestore(ta, key)
{
    const stored = getItem(key)
    if (!stored || stored === ta.value) return

    const link = document.createElement('a')
    link.href = '#'
    link.className = 'restore-draft'
    link.textContent = 'Restore unsaved changes'
    link.on('click', ev => {
        cancel(ev)
        ta.value = stored
        ta.dispatchEvent(new Event('input'))
        removeItem(key)
        link.remove()
    })
    ta.after(link)
}

/**
 * Saves the textarea's current value under key, or clears it once the field
 * is emptied so an abandoned draft doesn't linger.
 *
 * @param {HTMLTextAreaElement} ta
 * @param {string} key
 * @returns {void}
 */
function save(ta, key)
{
    if (ta.value === '') {
        removeItem(key)
    } else {
        setItem(key, ta.value)
    }
}

// Every storage call is wrapped: private-mode browsers and full storage throw
// on write, and some throw on read too, but a broken autosave must never break
// the editor itself.

function getItem(key)
{
    try {
        return localStorage.getItem(key)
    } catch {
        return null
    }
}

function setItem(key, value)
{
    try {
        localStorage.setItem(key, value)
    } catch {
        // ponytail: silently drops the draft when storage is unavailable —
        // acceptable since the textarea itself still holds the text.
    }
}

function removeItem(key)
{
    try {
        localStorage.removeItem(key)
    } catch {
        // see setItem
    }
}
