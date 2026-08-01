import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

function load(html = '<!DOCTYPE html><body></body>') {
  return loadScripts({
    files: ['shorthand.js', 'logged_in/upload-image.js'],
    expose: ['clipboardImageFiles', 'handleFiles'],
    html,
  })
}

// Build a fake clipboard item like the ones DataTransfer.items yields.
function imageItem(window, type, name = '') {
  return { kind: 'file', type, getAsFile: () => new window.File(['x'], name, { type }) }
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

test('clipboardImageFiles returns [] for a null clipboard', () => {
  const { api } = load()
  assert.equal(api.clipboardImageFiles(null).length, 0)
})

test('clipboardImageFiles ignores non-file and non-image items', () => {
  const { window, api } = load()
  const items = [
    { kind: 'string', type: 'text/plain', getAsFile: () => null },
    imageItem(window, 'application/pdf', 'a.pdf'), // file, but not an image
  ]
  assert.equal(api.clipboardImageFiles({ items }).length, 0)
})

test('clipboardImageFiles names pasted images uniquely with a real extension', () => {
  const { window, api } = load()
  const files = api.clipboardImageFiles({ items: [imageItem(window, 'image/png'), imageItem(window, 'image/jpeg')] })
  assert.equal(files.length, 2)
  assert.match(files[0].name, /^pasted-\d+-0\.png$/)
  assert.match(files[1].name, /^pasted-\d+-1\.jpeg$/)
  assert.equal(files[0].type, 'image/png')
})

test('handleFiles inserts the upload response at the cursor and fires input', async () => {
  const { window, document, api } = load('<!DOCTYPE html><body><textarea></textarea></body>')
  const ta = document.querySelector('textarea')
  ta.value = 'abcd'
  ta.setSelectionRange(2, 2) // cursor between "ab" and "cd"
  let inputFired = false
  ta.addEventListener('input', () => { inputFired = true })

  let posted
  window.fetch = (url, opts) => {
    posted = { url, opts }
    return Promise.resolve({ ok: true, json: () => Promise.resolve('![](/img.webp)') })
  }

  api.handleFiles([new window.File(['x'], 'a.png', { type: 'image/png' })], ta)
  await flush()

  assert.equal(posted.url, '/upload')
  assert.equal(ta.value, 'ab![](/img.webp)cd')
  assert.ok(inputFired, 'an input event should be dispatched')
})

test('handleFiles posts a dropped video file the same way as an image', async () => {
  // The drop handler forwards any dropped file with no client-side type
  // filtering; a video is uploaded/inserted through the same path as an image.
  const { window, document, api } = load('<!DOCTYPE html><body><textarea></textarea></body>')
  const ta = document.querySelector('textarea')
  ta.value = ''
  ta.setSelectionRange(0, 0)

  let posted
  window.fetch = (url, opts) => {
    posted = { url, opts }
    return Promise.resolve({ ok: true, json: () => Promise.resolve('![](/clip.mp4)') })
  }

  api.handleFiles([new window.File(['x'], 'clip.mp4', { type: 'video/mp4' })], ta)
  await flush()

  assert.equal(posted.url, '/upload')
  assert.equal(posted.opts.body.get('imageFiles[]').name, 'clip.mp4')
  assert.equal(ta.value, '![](/clip.mp4)')
})

test('handleFiles strips server alt text so the user sees empty [] to fill in', async () => {
  const { window, document, api } = load('<!DOCTYPE html><body><textarea></textarea></body>')
  const ta = document.querySelector('textarea')
  ta.value = ''
  ta.setSelectionRange(0, 0)

  window.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve('![photo.jpg](/img.webp)') })

  api.handleFiles([new window.File(['x'], 'photo.jpg', { type: 'image/png' })], ta)
  await flush()

  assert.equal(ta.value, '![](/img.webp)')
})

test('handleFiles positions cursor inside [] after insertion', async () => {
  const { window, document, api } = load('<!DOCTYPE html><body><textarea></textarea></body>')
  const ta = document.querySelector('textarea')
  ta.value = 'abcd'
  ta.setSelectionRange(2, 2) // cursor between "ab" and "cd"

  window.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve('![photo.jpg](/img.webp)') })

  api.handleFiles([new window.File(['x'], 'photo.jpg', { type: 'image/png' })], ta)
  await flush()

  assert.equal(ta.value, 'ab![](/img.webp)cd')
  assert.equal(ta.selectionStart, 4) // inside the [], after ![
  assert.equal(ta.selectionEnd, 4)
})

test('handleFiles leaves the textarea alone when the upload is rejected', async () => {
  // A rejected upload answers with an error status and a JSON *string* error
  // message. That message must never be inserted into the post being written.
  const { window, document, api } = load('<!DOCTYPE html><body><textarea></textarea></body>')
  const ta = document.querySelector('textarea')
  ta.value = 'draft text'
  ta.setSelectionRange(5, 5)

  const errors = []
  window.console.error = (e) => errors.push(e)
  window.fetch = () => Promise.resolve({
    ok: false,
    status: 400,
    json: () => Promise.resolve('Unsupported file type.'),
  })

  api.handleFiles([new window.File(['x'], 'evil.svg', { type: 'image/svg+xml' })], ta)
  await flush()

  assert.equal(ta.value, 'draft text')
  assert.equal(errors.length, 1)
})

test('handleFiles shows the rejection reason to the author', async () => {
  // Leaving the textarea alone is only half the job: uploads happen without a
  // page load, so the author has to be told why nothing appeared.
  const { window, document, api } = load('<!DOCTYPE html><body><form><textarea></textarea></form></body>')
  const ta = document.querySelector('textarea')
  window.console.error = () => {}
  window.fetch = () => Promise.resolve({
    ok: false,
    status: 400,
    json: () => Promise.resolve('Unsupported file type.'),
  })

  api.handleFiles([new window.File(['x'], 'evil.svg', { type: 'image/svg+xml' })], ta)
  await flush()

  const flash = document.querySelector('.flash.upload-error')
  assert.ok(flash, 'an upload failure should be surfaced in the page')
  assert.match(flash.textContent, /Unsupported file type\./)
  assert.equal(flash.getAttribute('role'), 'alert')
  // Above the form, so it is not pushed off-screen by a long draft.
  assert.equal(flash.nextElementSibling.tagName, 'FORM')
})

test('handleFiles falls back to the status when the endpoint sends no message', async () => {
  const { window, document, api } = load('<!DOCTYPE html><body><form><textarea></textarea></form></body>')
  const ta = document.querySelector('textarea')
  window.console.error = () => {}
  window.fetch = () => Promise.resolve({
    ok: false,
    status: 500,
    json: () => Promise.reject(new Error('not json')),
  })

  api.handleFiles([new window.File(['x'], 'a.png', { type: 'image/png' })], ta)
  await flush()

  assert.match(document.querySelector('.flash.upload-error').textContent, /Upload failed \(500\)/)
})

test('handleFiles does not stack repeated failures, and clears on success', async () => {
  const { window, document, api } = load('<!DOCTYPE html><body><form><textarea></textarea></form></body>')
  const ta = document.querySelector('textarea')
  window.console.error = () => {}
  window.fetch = () => Promise.resolve({
    ok: false,
    status: 400,
    json: () => Promise.resolve('Unsupported file type.'),
  })

  api.handleFiles([new window.File(['x'], 'a.svg', { type: 'image/svg+xml' })], ta)
  await flush()
  api.handleFiles([new window.File(['x'], 'b.svg', { type: 'image/svg+xml' })], ta)
  await flush()
  assert.equal(document.querySelectorAll('.flash.upload-error').length, 1)

  window.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve('![](/img.webp)') })
  api.handleFiles([new window.File(['x'], 'c.png', { type: 'image/png' })], ta)
  await flush()
  assert.equal(document.querySelectorAll('.flash.upload-error').length, 0)
})
