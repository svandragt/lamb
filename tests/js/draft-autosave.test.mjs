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

test('guarded storage', async () => {
  const { window, document } = setup(`<!DOCTYPE html><body>${ENTRY_FORM}</body>`)
  window.localStorage.setItem = () => { throw new Error('storage unavailable') }
  const ta = document.querySelector('textarea')

  ta.value = 'still editable'
  assert.doesNotThrow(() => ta.dispatchEvent(new window.Event('input')))
  await wait(450)

  assert.equal(ta.value, 'still editable')
})
