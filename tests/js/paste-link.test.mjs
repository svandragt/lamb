import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts, pasteEvent } from './helpers.mjs'

/**
 * Load shorthand + paste-link and fire DOMContentLoaded so the paste handler
 * attaches (jsdom has already finished parsing by the time we evaluate).
 */
function setup(html = '<!DOCTYPE html><body><textarea></textarea></body>') {
  const ctx = loadScripts({
    files: ['shorthand.js', 'logged_in/paste-link.js'],
    expose: ['isUrl'],
    html,
  })
  ctx.document.dispatchEvent(new ctx.window.Event('DOMContentLoaded'))
  return ctx
}

test('isUrl accepts http(s) URLs', () => {
  const { api } = setup()
  assert.ok(api.isUrl('https://example.com'))
  assert.ok(api.isUrl('http://example.com/path?q=1#frag'))
})

test('isUrl rejects non-URLs, whitespace and non-http schemes', () => {
  const { api } = setup()
  const rejects = [
    '',
    '   ',
    'not a url',
    'hello world',
    'example.com',            // no scheme
    'https://ex ample.com',   // internal whitespace
    'ftp://example.com',      // non-http scheme
    'mailto:a@b.com',
    'javascript:alert(1)',
  ]
  for (const value of rejects) {
    assert.equal(api.isUrl(value), false, `expected ${JSON.stringify(value)} to be rejected`)
  }
})

test('pasting a URL over a selection wraps it in a markdown link', () => {
  const { window, document } = setup()
  const ta = document.querySelector('textarea')
  ta.value = 'see this site'
  ta.setSelectionRange(4, 8) // "this"
  ta.dispatchEvent(pasteEvent(window, { 'text/plain': 'https://example.com' }))
  assert.equal(ta.value, 'see [this](https://example.com) site')
})

test('falls back to getData("text") when text/plain is empty (iOS Safari)', () => {
  const { window, document } = setup()
  const ta = document.querySelector('textarea')
  ta.value = 'see this site'
  ta.setSelectionRange(4, 8)
  ta.dispatchEvent(pasteEvent(window, { 'text/plain': '', text: 'https://example.com' }))
  assert.equal(ta.value, 'see [this](https://example.com) site')
})

test('the pasted URL is trimmed before being wrapped', () => {
  const { window, document } = setup()
  const ta = document.querySelector('textarea')
  ta.value = 'see this site'
  ta.setSelectionRange(4, 8)
  ta.dispatchEvent(pasteEvent(window, { 'text/plain': '  https://example.com  ' }))
  assert.equal(ta.value, 'see [this](https://example.com) site')
})

test('pasting a non-URL leaves the textarea untouched', () => {
  const { window, document } = setup()
  const ta = document.querySelector('textarea')
  ta.value = 'see this site'
  ta.setSelectionRange(4, 8)
  ta.dispatchEvent(pasteEvent(window, { 'text/plain': 'just text' }))
  assert.equal(ta.value, 'see this site')
})

test('pasting with no selection does nothing', () => {
  const { window, document } = setup()
  const ta = document.querySelector('textarea')
  ta.value = 'abc'
  ta.setSelectionRange(1, 1) // collapsed caret
  ta.dispatchEvent(pasteEvent(window, { 'text/plain': 'https://example.com' }))
  assert.equal(ta.value, 'abc')
})
