onLoaded(() => {
    const bs = $$('button.button-edit');
    bs?.forEach($button => $button.on('click', (ev) => {
        const id = ev.target.dataset.id
        location.href = `/edit/${id}`
    }))
})
