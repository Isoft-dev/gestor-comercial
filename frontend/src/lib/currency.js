const quetzales = new Intl.NumberFormat('es-GT', {
  style: 'currency',
  currency: 'GTQ',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

export function formatCurrency(value) {
  const amount = Number(value)
  return quetzales.format(Number.isFinite(amount) ? amount : 0)
}
