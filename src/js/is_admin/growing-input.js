window.addEventListener('load', function () {
  var ta = document.querySelector('textarea')
  if (!ta) return
  ta.style.overflow = 'hidden'
  ta.addEventListener('keyup', growing_input)
  growing_input({ 'target': ta })
})

function growing_input (ev) {
  const target = ev.target
  target.style.height = 'auto'
  target.style.height = (10 + target.scrollHeight) + 'px'
}
