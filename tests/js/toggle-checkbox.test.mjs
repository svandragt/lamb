import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

function load() {
  return loadScripts({
    files: ['shorthand.js', 'logged_in/toggle-checkbox.js'],
    expose: ['saveCheckbox'],
    html: '<!DOCTYPE html><body><article data-post-id="42"><input type="checkbox" data-checkbox-index="3"></article></body>',
  })
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

test('saveCheckbox posts id, index and checked state to /checkbox', async () => {
  const { window, document, api } = load()
  const box = document.querySelector('input')
  box.checked = true

  let captured
  window.fetch = (url, opts) => {
    captured = { url, body: opts.body, method: opts.method }
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true }) })
  }

  api.saveCheckbox(box)
  await flush()

  assert.equal(captured.url, '/checkbox')
  assert.equal(captured.method, 'POST')
  assert.equal(captured.body.get('id'), '42')
  assert.equal(captured.body.get('index'), '3')
  assert.equal(captured.body.get('checked'), '1')
  assert.equal(box.disabled, false)
})

test('saveCheckbox reverts the checkbox on an HTTP error', async () => {
  const { window, document, api } = load()
  const box = document.querySelector('input')
  box.checked = true
  window.fetch = () => Promise.resolve({ ok: false, status: 500 })

  api.saveCheckbox(box)
  await flush()

  assert.equal(box.checked, false, 'should revert the optimistic toggle')
  assert.equal(box.disabled, false)
})

test('saveCheckbox reverts when the server reports failure in JSON', async () => {
  const { window, document, api } = load()
  const box = document.querySelector('input')
  box.checked = false
  window.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: false }) })

  api.saveCheckbox(box)
  await flush()

  assert.equal(box.checked, true, 'should revert from unchecked back to checked')
  assert.equal(box.disabled, false)
})

test('saveCheckbox does nothing without a [data-post-id] ancestor', () => {
  const { window, document, api } = load()
  const orphan = document.createElement('input')
  orphan.type = 'checkbox'
  let called = false
  window.fetch = () => { called = true; return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true }) }) }

  api.saveCheckbox(orphan)
  assert.equal(called, false, 'should not POST when there is no owning post')
})
