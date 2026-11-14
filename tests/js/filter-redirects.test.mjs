import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

const ROWS = [
  ['/old-post', '/status/1'],
  ['/Another-Slug', '/new-home'],
  ['/third', '/status/3'],
]

function markup() {
  const rows = ROWS.map(([from, to]) => `<tr><td>${from}</td><td>${to}</td></tr>`).join('')
  // The logs table sits outside the redirections panel and must stay untouched.
  return `<!DOCTYPE html><body>
    <section id="settings-panel-redirects">
      <p id="redirects-filter" hidden>
        <label for="redirects-filter-input">Filter redirects</label>
        <input type="search" id="redirects-filter-input">
      </p>
      <p id="redirects-filter-status" aria-live="polite"></p>
      <table><tbody>${rows}</tbody></table>
    </section>
    <table class="logs"><tbody><tr><td>/old-post</td></tr></tbody></table>
  </body>`
}

function setup() {
  const ctx = loadScripts({ files: ['shorthand.js', 'settings/filter-redirects.js'], html: markup() })
  ctx.document.dispatchEvent(new ctx.window.Event('DOMContentLoaded'))
  const input = ctx.document.querySelector('input')
  const rows = [...ctx.document.querySelectorAll('#settings-panel-redirects tbody tr')]
  const status = ctx.document.querySelector('#redirects-filter-status')
  const type = (value) => {
    input.value = value
    input.dispatchEvent(new ctx.window.Event('input', { bubbles: true }))
    return rows.filter((row) => !row.hidden).map((row) => row.cells[0].textContent)
  }
  return { ...ctx, input, rows, status, type }
}

test('the filter box is shown once the script has loaded', () => {
  const { document } = setup()

  assert.equal(document.querySelector('#redirects-filter').hidden, false)
})

test('typing an old path keeps only the matching rows', () => {
  const { type } = setup()

  assert.deepEqual(type('old-post'), ['/old-post'])
})

test('typing a new path keeps only the matching rows', () => {
  const { type } = setup()

  assert.deepEqual(type('new-home'), ['/Another-Slug'])
})

test('matching ignores case', () => {
  const { type } = setup()

  assert.deepEqual(type('ANOTHER-slug'), ['/Another-Slug'])
})

test('a query that matches the new path of several rows keeps all of them', () => {
  const { type } = setup()

  assert.deepEqual(type('status'), ['/old-post', '/third'])
})

test('clearing the box shows every row again and clears the count', () => {
  const { type, status } = setup()
  type('third')

  assert.equal(type('').length, 3)
  assert.equal(status.textContent, '')
})

test('the status line counts the matching redirects', () => {
  const { type, status } = setup()
  type('status')

  assert.equal(status.textContent, '2 of 3 redirects')
})

test('the status line says so when no redirect matches', () => {
  const { type, status } = setup()

  assert.deepEqual(type('nothing-like-this'), [])
  assert.equal(status.textContent, 'No redirects match')
})

test('rows outside the redirections panel are left alone', () => {
  const { document, type } = setup()
  type('third')

  assert.equal(document.querySelector('.logs tr').hidden, false)
})

test('a query of only spaces shows every row', () => {
  const { type } = setup()

  assert.equal(type('   ').length, 3)
})

test('the script does nothing on a page without the filter box', () => {
  const ctx = loadScripts({ files: ['shorthand.js', 'settings/filter-redirects.js'], html: '<!DOCTYPE html><body></body>' })

  assert.doesNotThrow(() => ctx.document.dispatchEvent(new ctx.window.Event('DOMContentLoaded')))
})
