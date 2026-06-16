onLoaded(() => {
    let ta = $('textarea')
    if (!ta) {
        return
    }
    ta.on('input', growing_input)
    growing_input({'target': ta})
})

function growing_input(ev)
{
    const target = ev.target
    // Resetting height to 'auto' momentarily collapses the textarea, so the
    // browser clamps the page scroll. Save and restore it to avoid jumping when
    // editing near the end of a long post.
    const scrollY = window.scrollY
    target.style.height = 'auto'
    target.style.height = (20 + target.scrollHeight) + 'px'
    window.scrollTo(window.scrollX, scrollY)
}
