onLoaded(() => {
    let ta = $('textarea')
    if (!ta) {
        return
        ta.on('input', growing_input)
        growing_input({'target': ta})
    });
    }

function growing_input(ev)
{
    console.log('recalculating input')
    const target = ev.target
    target.style.height = 'auto'
    target.style.height = (20 + target.scrollHeight) + 'px'
}
