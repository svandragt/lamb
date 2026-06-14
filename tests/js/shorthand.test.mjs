import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

test('$ returns the first matching element', () => {
  const { api } = loadScripts({
    files: ['shorthand.js'],
    expose: ['$'],
    html: '<!DOCTYPE html><body><p class="x">a</p><p class="x">b</p></body>',
  })
  assert.equal(api.$('.x').textContent, 'a')
})

test('$ returns null when nothing matches', () => {
  const { api } = loadScripts({ files: ['shorthand.js'], expose: ['$'] })
  assert.equal(api.$('.missing'), null)
})

test('$$ returns every matching element', () => {
  const { api } = loadScripts({
    files: ['shorthand.js'],
    expose: ['$$'],
    html: '<!DOCTYPE html><body><p class="x"></p><p class="x"></p><p class="x"></p></body>',
  })
  assert.equal(api.$$('.x').length, 3)
})

test('cancel prevents default and stops propagation', () => {
  const { api } = loadScripts({ files: ['shorthand.js'], expose: ['cancel'] })
  let prevented = false
  let stopped = false
  api.cancel({
    preventDefault: () => { prevented = true },
    stopPropagation: () => { stopped = true },
  })
  assert.ok(prevented, 'preventDefault should be called')
  assert.ok(stopped, 'stopPropagation should be called')
})

test('Node.prototype.on registers a listener and is chainable', () => {
  const { window, document } = loadScripts({
    files: ['shorthand.js'],
    html: '<!DOCTYPE html><body><button></button></body>',
  })
  const button = document.querySelector('button')
  let clicks = 0
  const returned = button.on('click', () => { clicks += 1 })
  assert.equal(returned, button, 'on() should return the node for chaining')
  button.dispatchEvent(new window.Event('click'))
  assert.equal(clicks, 1)
})

test('onLoaded runs its callback on DOMContentLoaded', () => {
  const { window, document, api } = loadScripts({
    files: ['shorthand.js'],
    expose: ['onLoaded'],
  })
  let ran = false
  api.onLoaded(() => { ran = true })
  document.dispatchEvent(new window.Event('DOMContentLoaded'))
  assert.ok(ran)
})
