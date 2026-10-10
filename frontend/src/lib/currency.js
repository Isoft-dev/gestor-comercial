const quetzales = new Intl.NumberFormat('es-GT', {
  style: 'currency',
  currency: 'GTQ',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

export function formatCurrency(value) {
  const amount = Number(value)
  if (!Number.isFinite(amount)) throw new TypeError('Currency amount must be a finite number.')
  return quetzales.format(amount)
}
