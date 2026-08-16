import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadScripts } from './helpers.mjs'

function load() {
  return loadScripts({
    files: ['shorthand.js', 'logged_in/link-edit-buttons.js'],
    expose: ['editUrl'],
    html: '<!DOCTYPE html><body><button class="button-edit" data-id="12">Edit</button></body>',
  })
}

test('editUrl points at the post editor', () => {
  const { api } = load()

  assert.equal(api.editUrl('12'), '/edit/12')
})

test('editUrl stays inside a subdirectory install', () => {
  // Issue #580: a bare /edit/12 leaves the install, so the author lands on the
  // domain root instead of the editor.
  const { window, api } = load()
  window.LAMB_BASE = '/blog'

  assert.equal(api.editUrl('12'), '/blog/edit/12')
})
