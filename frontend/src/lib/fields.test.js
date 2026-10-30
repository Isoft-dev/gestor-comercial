import assert from 'node:assert/strict'
import test from 'node:test'
import { clampAmount, fields, isValidCardNumber } from './fields.js'

test('card numbers require 16 digits and a valid Luhn checksum', () => {
  assert.equal(isValidCardNumber('4111 1111 1111 1111'), true)
  assert.equal(isValidCardNumber('5555555555554444'), true)
  assert.equal(isValidCardNumber('1510 0000 0000 0000'), false)
  assert.equal(isValidCardNumber('4111 1111 1111 111'), false)
})

test('DPI, phone and NIT inputs keep only valid characters', () => {
  assert.equal(fields.dpi.clean('2514-25414 0101 99'), '2514254140101')
  assert.match('2514254140101', new RegExp(`^${fields.dpi.pattern}$`))
  assert.equal(fields.telefono.clean('5555-12345'), '55551234')
  assert.doesNotMatch('15551234', new RegExp(`^${fields.telefono.pattern}$`))
  assert.equal(fields.nit.clean('1234567-k'), '1234567K')
  assert.equal(fields.nit.clean('cf'), 'CF')
  assert.equal(fields.nit.clean('123456789'), '12345678')
  assert.doesNotMatch('123456789', new RegExp(`^${fields.nit.pattern}$`))
})

test('amounts are clamped to the allowed range', () => {
  assert.equal(clampAmount(150, 100), 100)
  assert.equal(clampAmount(-5, 100), 0)
  assert.equal(clampAmount(2002.555, 3000), 2002.56)
})
