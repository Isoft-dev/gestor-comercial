import assert from 'node:assert/strict'
import { afterEach, test } from 'node:test'
import { pendingMutations, releasePageRequest, trackPageRequest } from './pageLoading.js'
import { dismissNotification, notifications, notify } from './notifications.js'

afterEach(() => {
  notifications.value = []
})

test('mutation loading tracks concurrent changes outside navigation and releases once', () => {
  const first = trackPageRequest({ method: 'post' })
  const second = trackPageRequest({ method: 'delete' })
  const read = trackPageRequest({ method: 'get' })
  assert.equal(pendingMutations.value, 2)
  releasePageRequest(read)
  assert.equal(pendingMutations.value, 2)
  releasePageRequest(first)
  releasePageRequest(first)
  assert.equal(pendingMutations.value, 1)
  releasePageRequest(second)
  assert.equal(pendingMutations.value, 0)
})

test('notifications preserve tone, can close, and expire', (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  notify('Cliente creado.')
  notify('Sin stock.', 'error')
  assert.equal(notifications.value[0].tone, 'success')
  assert.equal(notifications.value[1].tone, 'error')
  t.mock.timers.tick(5000)
  assert.equal(notifications.value.length, 1)
  assert.equal(notifications.value[0].message, 'Sin stock.')
  t.mock.timers.tick(3000)
  assert.equal(notifications.value.length, 0)
  notify('Actualizado.')
  dismissNotification(notifications.value[0].id)
  assert.equal(notifications.value.length, 0)
})

test('notifications show at most four recent operations', (t) => {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  for (let i = 0; i < 6; i += 1) notify(`Accion ${i}`)
  assert.equal(notifications.value.length, 4)
  assert.equal(notifications.value[0].message, 'Accion 2')
})
