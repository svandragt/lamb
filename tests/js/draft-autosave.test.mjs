import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

function load(html) {
  return loadScripts({ files: ['shorthand.js', 'logged_in/draft-autosave.js'], html })
}

function setup(html) {
  const ctx = load(html)
  ctx.document.dispatchEvent(new ctx.window.Event('DOMContentLoaded'))
  return ctx
}

// The handler debounces saves by ~400ms, so tests wait past that.
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

const ENTRY_FORM = '<form id="entry"><textarea name="contents"></textarea></form>'

function editForm(id, contents = '') {
  return `<form id="editform"><textarea name="contents">${contents}</textarea><input type="hidden" name="id" value="${id}"></form>`
}

test('saves on input', async () => {
  const { window, document } = setup(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  const ta = document.querySelector('textarea')

  ta.value = 'hello world'
  ta.dispatchEvent(new window.Event('input'))
  await wait(450)

  assert.equal(window.localStorage.getItem('lamb:autosave:new'), 'hello world')
})

test('restores into empty field', () => {
  const { window, document } = load(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  window.localStorage.setItem('lamb:autosave:new', 'restored draft')
  const ta = document.querySelector('textarea')

  let inputFired = false
  ta.addEventListener('input', () => { inputFired = true })
  document.dispatchEvent(new window.Event('DOMContentLoaded'))

  assert.equal(ta.value, 'restored draft')
  assert.equal(inputFired, true)
})

test('does not restore over existing content', () => {
  const { window, document } = load(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  window.localStorage.setItem('lamb:autosave:new', 'stored draft')
  const ta = document.querySelector('textarea')
  ta.value = 'already typed'

  document.dispatchEvent(new window.Event('DOMContentLoaded'))

  assert.equal(ta.value, 'already typed')
})

test('clears on submit', () => {
  const { window, document } = setup(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  window.localStorage.setItem('lamb:autosave:new', 'stored draft')
  const form = document.querySelector('form')

  form.dispatchEvent(new window.Event('submit', { cancelable: true, bubbles: true }))

  assert.equal(window.localStorage.getItem('lamb:autosave:new'), null)
})

test('submit cancels a pending save so the published text is not re-persisted', async () => {
  const { window, document } = setup(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  const ta = document.querySelector('textarea')

  ta.value = 'about to publish'
  ta.dispatchEvent(new window.Event('input'))
  // Submit before the 400ms debounce fires; the text is still in the field
  // because the page hasn't navigated yet.
  document.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true, bubbles: true }))
  await wait(450)

  assert.equal(window.localStorage.getItem('lamb:autosave:new'), null)
})

test('removes key when emptied', async () => {
  const { window, document } = setup(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  window.localStorage.setItem('lamb:autosave:new', 'stored draft')
  const ta = document.querySelector('textarea')

  ta.value = ''
  ta.dispatchEvent(new window.Event('input'))
  await wait(450)

  assert.equal(window.localStorage.getItem('lamb:autosave:new'), null)
})

test('edit form keyed by id', async () => {
  const { window, document } = setup(`<!DOCTYPE html><body>${editForm(7)}</body>`)
  const ta = document.querySelector('textarea')

  ta.value = 'editing post 7'
  ta.dispatchEvent(new window.Event('input'))
  await wait(450)

  assert.equal(window.localStorage.getItem('lamb:autosave:edit:7'), 'editing post 7')
  assert.equal(window.localStorage.getItem('lamb:autosave:new'), null)
})

test('edit form shows a restore link when the draft differs from the body', () => {
  const { window, document } = load(`<!DOCTYPE html><body>${editForm(7, 'server body')}</body>`)
  window.localStorage.setItem('lamb:autosave:edit:7', 'unsaved local draft')

  document.dispatchEvent(new window.Event('DOMContentLoaded'))

  const link = document.querySelector('.restore-draft')
  assert.ok(link, 'expected a restore-draft link')
  assert.equal(link.textContent, 'Restore unsaved changes')
  assert.equal(document.querySelector('textarea').value, 'server body')
})

test('clicking the restore link swaps in the draft, fires input, clears storage, and removes itself', () => {
  const { window, document } = load(`<!DOCTYPE html><body>${editForm(7, 'server body')}</body>`)
  window.localStorage.setItem('lamb:autosave:edit:7', 'unsaved local draft')
  document.dispatchEvent(new window.Event('DOMContentLoaded'))
  const ta = document.querySelector('textarea')

  let inputFired = false
  ta.addEventListener('input', () => { inputFired = true })
  document.querySelector('.restore-draft').dispatchEvent(new window.Event('click', { cancelable: true, bubbles: true }))

  assert.equal(ta.value, 'unsaved local draft')
  assert.equal(inputFired, true)
  assert.equal(window.localStorage.getItem('lamb:autosave:edit:7'), null)
  assert.equal(document.querySelector('.restore-draft'), null)
})

test('edit form shows no restore link when the draft matches the body or is absent', () => {
  const { window, document } = load(`<!DOCTYPE html><body>${editForm(7, 'server body')}</body>`)
  window.localStorage.setItem('lamb:autosave:edit:7', 'server body')

  document.dispatchEvent(new window.Event('DOMContentLoaded'))
  assert.equal(document.querySelector('.restore-draft'), null)

  const { window: window2, document: document2 } = load(`<!DOCTYPE html><body>${editForm(9, 'server body')}</body>`)
  document2.dispatchEvent(new window2.Event('DOMContentLoaded'))
  assert.equal(document2.querySelector('.restore-draft'), null)
})

test('body with HTML-special characters round-trips, so an equal draft shows no link', () => {
  // edit.php escapes the body with htmlspecialchars before it reaches the
  // textarea; the DOM decodes it back, so a stored draft equal to the raw text
  // must compare equal. Pins the round-trip against an escape() change.
  const raw = 'tom & jerry <b> "quoted"'
  const { window, document } = load(`<!DOCTYPE html><body>${editForm(7, 'tom &amp; jerry &lt;b&gt; &quot;quoted&quot;')}</body>`)
  assert.equal(document.querySelector('textarea').value, raw)
  window.localStorage.setItem('lamb:autosave:edit:7', raw)

  document.dispatchEvent(new window.Event('DOMContentLoaded'))

  assert.equal(document.querySelector('.restore-draft'), null)
})

test('entry form never shows a restore link', () => {
  const { window, document } = load(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  window.localStorage.setItem('lamb:autosave:new', 'restored draft')

  document.dispatchEvent(new window.Event('DOMContentLoaded'))

  assert.equal(document.querySelector('.restore-draft'), null)
  assert.equal(document.querySelector('textarea').value, 'restored draft')
})

test('guarded storage', async () => {
  const { window, document } = setup(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  window.localStorage.setItem = () => { throw new Error('storage unavailable') }
  const ta = document.querySelector('textarea')

  ta.value = 'still editable'
  assert.doesNotThrow(() => ta.dispatchEvent(new window.Event('input')))
  await wait(450)

  assert.equal(ta.value, 'still editable')
})
