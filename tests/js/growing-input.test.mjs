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
