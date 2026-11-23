import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

const MARKUP = `<!DOCTYPE html><body>
  <div class="settings-tabs">
    <input type="radio" name="settings-tab" id="settings-tab-config" checked>
    <input type="radio" name="settings-tab" id="settings-tab-logs">
    <nav class="settings-tabs__nav">
      <label for="settings-tab-config">Configuration</label>
      <label for="settings-tab-logs">Logs</label>
    </nav>
  </div>
</body>`

test('selecting a tab scrolls its label into view in the nav', () => {
  const ctx = loadScripts({ files: ['shorthand.js', 'settings/tab-scroll.js'], html: MARKUP })
  const calls = []
  for (const label of ctx.document.querySelectorAll('label')) {
    label.scrollIntoView = (options) => calls.push([label.textContent, JSON.stringify(options)])
  }
  ctx.document.dispatchEvent(new ctx.window.Event('DOMContentLoaded'))

  ctx.document.querySelector('#settings-tab-logs').dispatchEvent(new ctx.window.Event('change', { bubbles: true }))

  assert.deepEqual(calls, [['Logs', '{"block":"nearest","inline":"nearest"}']])
})

test('a page without the tabs does nothing', () => {
  const ctx = loadScripts({ files: ['shorthand.js', 'settings/tab-scroll.js'], html: '<!DOCTYPE html><body></body>' })
  ctx.document.dispatchEvent(new ctx.window.Event('DOMContentLoaded'))
})
