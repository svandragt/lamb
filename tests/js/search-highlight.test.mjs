import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

// search-highlight.js is an IIFE that runs on load (not DOMContentLoaded), so
// the relevant DOM must exist in the html before the scripts are evaluated.
function run(html) {
  return loadScripts({ files: ['shorthand.js', 'search/search-highlight.js'], html })
}

const marks = (document) => [...document.querySelectorAll('main mark')].map((m) => m.textContent)

test('wraps each keyword occurrence in <mark>', () => {
  const { document } = run('<!DOCTYPE html><body class="search"><input id="s" value="quick fox"><main><p>The quick brown fox</p></main></body>')
  assert.deepEqual(marks(document), ['quick', 'fox'])
})

test('matching is case-insensitive but preserves the original casing', () => {
  const { document } = run('<!DOCTYPE html><body class="search"><input id="s" value="QUICK"><main><p>The Quick quick</p></main></body>')
  assert.deepEqual(marks(document), ['Quick', 'quick'])
})

test('escapes regex metacharacters in search terms', () => {
  const { document } = run('<!DOCTYPE html><body class="search"><input id="s" value="c++"><main><p>I love c++ a lot</p></main></body>')
  assert.deepEqual(marks(document), ['c++'])
})

test('does nothing when the body lacks the search class', () => {
  const { document } = run('<!DOCTYPE html><body><input id="s" value="fox"><main><p>quick fox</p></main></body>')
  assert.equal(document.querySelector('mark'), null)
})

test('does nothing when the search box is empty or whitespace', () => {
  const { document } = run('<!DOCTYPE html><body class="search"><input id="s" value="   "><main><p>quick fox</p></main></body>')
  assert.equal(document.querySelector('mark'), null)
})

test('does not descend into textarea/input/script/style elements', () => {
  const { document } = run('<!DOCTYPE html><body class="search"><input id="s" value="fox"><main><textarea>a fox here</textarea><p>a fox there</p></main></body>')
  // Only the <p> text is highlighted, never the <textarea> contents.
  assert.deepEqual(marks(document), ['fox'])
})
