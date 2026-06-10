import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { JSDOM } from 'jsdom'

const here = dirname(fileURLToPath(import.meta.url))
const SCRIPTS_DIR = resolve(here, '../../src/scripts')

/**
 * Load one or more client-side scripts into a fresh jsdom window and return the
 * globals they declare.
 *
 * The scripts in src/scripts/ ship as plain <script>-tag globals (no module
 * exports), so we concatenate their sources, evaluate them inside the window,
 * and return the requested bindings — keeping the shipped files untouched.
 *
 * @param {object}   opts
 * @param {string[]} opts.files   Script paths relative to src/scripts/.
 * @param {string[]} [opts.expose] Names of globals to return from the scripts.
 * @param {string}   [opts.html]  Initial document HTML.
 * @returns {{ dom: import('jsdom').JSDOM, window: Window, document: Document, api: Record<string, any> }}
 */
export function loadScripts({ files, expose = [], html = '<!DOCTYPE html><html><body></body></html>' }) {
  const dom = new JSDOM(html, {
    url: 'https://example.com/',
    pretendToBeVisual: true,
    runScripts: 'outside-only', // enables window.eval with DOM globals in scope
  })
  const source = files
    .map((file) => readFileSync(resolve(SCRIPTS_DIR, file), 'utf8'))
    .join('\n;\n')
  const wrapped = `(function () {\n${source}\n;return { ${expose.join(', ')} };\n})()`
  const api = dom.window.eval(wrapped)
  return { dom, window: dom.window, document: dom.window.document, api }
}

/**
 * Build a fake `paste` event whose clipboardData.getData returns `byType`
 * values, mirroring what the paste-link handler reads.
 *
 * @param {Window} window
 * @param {Record<string, string>} byType  e.g. { 'text/plain': 'https://x' }
 * @returns {Event}
 */
export function pasteEvent(window, byType) {
  const ev = new window.Event('paste', { bubbles: true, cancelable: true })
  ev.clipboardData = { getData: (type) => byType[type] ?? '' }
  return ev
}
