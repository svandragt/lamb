import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

function load(html) {
  return loadScripts({ files: ['shorthand.js', 'logged_in/growing-input.js'], html })
}

// Setting the textarea height to 'auto' momentarily collapses it, which makes a
// browser clamp the window scroll to the (smaller) page height. The handler must
// save the scroll position and restore it so editing near the end of a long post
// does not jump the page.
test('resizing the textarea restores the window scroll position', () => {
  const { window, document } = load('<!DOCTYPE html><body><textarea></textarea></body>')

  let scrollY = 0
  Object.defineProperty(window, 'scrollY', { configurable: true, get: () => scrollY })
  const calls = []
  window.scrollTo = (x, y) => { calls.push([x, y]); scrollY = y }

  // DOMContentLoaded runs the initial resize once.
  document.dispatchEvent(new window.Event('DOMContentLoaded'))
  const ta = document.querySelector('textarea')

  // Pretend the user has scrolled down a long post, then types.
  scrollY = 500
  ta.dispatchEvent(new window.Event('input'))

  assert.deepEqual(calls.at(-1), [0, 500])
})

function stubLayout(ta, { rendered, scrollHeight }) {
  Object.defineProperty(ta, 'offsetParent', { configurable: true, get: () => (rendered ? ta.parentNode : null) })
  Object.defineProperty(ta, 'scrollHeight', { configurable: true, get: () => scrollHeight })
}

test('typing into the second textarea grows the second one', () => {
  const { window, document } = load('<!DOCTYPE html><body><textarea></textarea><textarea></textarea></body>')
  const [, second] = document.querySelectorAll('textarea')
  stubLayout(second, { rendered: true, scrollHeight: 100 })

  document.dispatchEvent(new window.Event('DOMContentLoaded'))
  second.style.height = ''
  second.dispatchEvent(new window.Event('input'))

  assert.equal(second.style.height, '120px')
})

test('a textarea that is not rendered at load keeps its rows height', () => {
  const { window, document } = load('<!DOCTYPE html><body><textarea></textarea></body>')
  const ta = document.querySelector('textarea')
  stubLayout(ta, { rendered: false, scrollHeight: 0 })

  document.dispatchEvent(new window.Event('DOMContentLoaded'))

  assert.equal(ta.style.height, '')
})

test('focusing a textarea that was hidden at load sizes it', () => {
  const { window, document } = load('<!DOCTYPE html><body><textarea></textarea></body>')
  const ta = document.querySelector('textarea')
  stubLayout(ta, { rendered: false, scrollHeight: 80 })

  document.dispatchEvent(new window.Event('DOMContentLoaded'))
  ta.dispatchEvent(new window.Event('focus'))

  assert.equal(ta.style.height, '100px')
})
