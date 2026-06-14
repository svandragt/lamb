import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

function setup(html) {
  const ctx = loadScripts({ files: ['shorthand.js', 'logged_in/confirm-delete.js'], html })
  ctx.document.dispatchEvent(new ctx.window.Event('DOMContentLoaded'))
  return ctx
}

// The handler confirms inside a 50ms setTimeout, so tests wait it out.
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

test('submitting a delete form confirms before submitting', async () => {
  const { window, document } = setup('<!DOCTYPE html><body><form class="form-delete" data-id="7"></form></body>')
  const form = document.querySelector('form')
  let submitted = false
  form.submit = () => { submitted = true } // jsdom does not implement form.submit()
  let prompt = null
  window.confirm = (msg) => { prompt = msg; return true }

  form.dispatchEvent(new window.Event('submit', { cancelable: true, bubbles: true }))
  await wait(70)

  assert.equal(prompt, 'Really delete status 7?')
  assert.equal(submitted, true)
})

test('declining the confirmation does not submit', async () => {
  const { window, document } = setup('<!DOCTYPE html><body><form class="form-delete" data-id="7"></form></body>')
  const form = document.querySelector('form')
  let submitted = false
  form.submit = () => { submitted = true }
  window.confirm = () => false

  form.dispatchEvent(new window.Event('submit', { cancelable: true, bubbles: true }))
  await wait(70)

  assert.equal(submitted, false)
})
