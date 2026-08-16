/**
 * The editor URL for a post id.
 *
 * Split out of the click handler so it can be tested without navigating: it has
 * to carry the install's base, or the Edit button leaves a subdirectory install
 * for the domain root.
 *
 * @param {string} id - The post id from the button's data-id.
 * @returns {string} The editor path.
 */
function editUrl(id)
{
    return appPath(`/edit/${id}`)
}

onLoaded(() => {
    const bs = $$('button.button-edit');
    bs?.forEach($button => $button.on('click', (ev) => {
        const id = ev.target.dataset.id
        location.href = editUrl(id)
    }))
})
