(() => {
  var bs = document.querySelectorAll('button.button-edit')
  if (!bs) return
  bs.forEach(f => f.addEventListener('click', link_edit))
})()

function link_edit(ev) {
  const id = ev.target.dataset.id
  location.href = `/edit/${id}`
}