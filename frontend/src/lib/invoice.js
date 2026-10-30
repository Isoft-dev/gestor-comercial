import api from '../services/api'

export async function downloadInvoice(saleId) {
  const { data } = await api.get(`/ventas/${saleId}/factura`, { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = url
  link.download = `factura-venta-${saleId}.pdf`
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
