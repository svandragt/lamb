(() => {
  var fs = document.querySelectorAll('form.form-delete')
  if (!fs) return
  fs.forEach(f => f.addEventListener('submit', confirm_delete))
})()

function confirm_delete(ev) {
  const id = ev.target.dataset.id
  let confirmed = confirm(`Really delete status ${id}?`)
  if (!confirmed) {
    ev.preventDefault()
  }
}