<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { askConfirm } from '../lib/dialog'
import { formatCurrency } from '../lib/currency'
import { CircleAlert, CircleCheck, Eye, PackageCheck, Plus, Search, Truck, X } from '@lucide/vue'

const purchases = ref([])
const receipts = ref([])
const providers = ref([])
const products = ref([])
const error = ref('')
const message = ref('')
const receiptSearch = ref('')
const receiptFrom = ref('')
const receiptTo = ref('')
const receiptPage = ref(1)
const receiptPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const selectedReceipt = ref(null)
const form = reactive({ proveedor_id: '', producto_id: '', cantidad: 1, costo_unitario: 1, observacion: '' })

async function load() {
  try {
    const { data } = await api.get('/compras', { params: { con_opciones: 1 } })
    purchases.value = data.data
    if (data.proveedores) providers.value = data.proveedores
    if (data.productos) products.value = data.productos
    await loadReceipts()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function loadReceipts() {
  try {
    const { data } = await api.get('/recepciones', {
      params: {
        buscar: receiptSearch.value || undefined,
        desde: receiptFrom.value || undefined,
        hasta: receiptTo.value || undefined,
        page: receiptPage.value,
      },
    })
    receipts.value = data.data
    receiptPagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    }
    if (selectedReceipt.value && !receipts.value.some((item) => item.REC_Recepcion === selectedReceipt.value.REC_Recepcion)) {
      selectedReceipt.value = null
    }
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function searchReceipts() {
  receiptPage.value = 1
  loadReceipts()
}

function changeReceiptPage(page) {
  receiptPage.value = page
  loadReceipts()
}

async function viewReceipt(id) {
  error.value = ''
  try {
    const { data } = await api.get(`/recepciones/${id}`)
    selectedReceipt.value = data
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  try {
    await api.post('/compras', {
      proveedor_id: form.proveedor_id,
      observacion: form.observacion,
      detalles: [{ producto_id: form.producto_id, cantidad: form.cantidad, costo_unitario: form.costo_unitario }],
    })
    message.value = 'Orden de compra registrada.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function receive(id) {
  const accepted = await askConfirm({
    title: 'Recibir compra',
    message: '¿Confirmar la recepción? Esta acción dará entrada al inventario.',
    confirmText: 'Recibir',
    tone: 'warning',
  })
  if (!accepted) return
  try {
    await api.post(`/compras/${id}/recibir`)
    message.value = 'Compra recibida e inventario actualizado.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Abastecimiento</p>
      <h1 class="page-title"><Truck :size="26" :stroke-width="2" /> Compras y recepción</h1>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section class="two-column">
    <form class="panel" @submit.prevent="submit">
      <div class="panel-heading"><Plus :size="18" /><h2>Nueva orden</h2></div>
      <label>Proveedor<select v-model="form.proveedor_id" required><option value="">Seleccione</option><option v-for="provider in providers" :key="provider.PRV_Proveedor" :value="provider.PRV_Proveedor">{{ provider.PRV_Razon_Social }}</option></select></label>
      <label>Producto<select v-model="form.producto_id" required><option value="">Seleccione</option><option v-for="product in products" :key="product.PRO_Producto" :value="product.PRO_Producto">{{ product.PRO_SKU }} · {{ product.PRO_Nombre }}</option></select></label>
      <div class="form-grid"><label>Cantidad<input v-model.number="form.cantidad" type="number" min="1" required /></label><label>Costo unitario (Q)<input v-model.number="form.costo_unitario" type="number" min="0.01" step="0.01" required /></label></div>
      <label>Observación<textarea v-model="form.observacion"></textarea></label>
      <button class="button primary"><Plus :size="16" /> Registrar compra</button>
    </form>
    <section class="panel">
      <div class="panel-heading"><PackageCheck :size="18" /><h2>Órdenes recientes</h2></div>
      <div class="table-wrap"><table>
        <thead><tr><th>#</th><th>Proveedor</th><th>Total</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <template v-for="purchase in purchases" :key="purchase.COM_Compra">
            <tr>
              <td class="mono">{{ purchase.COM_Compra }}</td>
              <td>{{ purchase.PRV_Razon_Social }}</td>
              <td class="mono">{{ formatCurrency(purchase.COM_Total) }}</td>
              <td><span class="status" :data-state="purchase.COM_Estado">{{ purchase.COM_Estado }}</span></td>
              <td><button v-if="purchase.COM_Estado === 'PENDIENTE'" class="link" type="button" @click="receive(purchase.COM_Compra)"><PackageCheck :size="14" /> Recibir</button></td>
            </tr>
            <tr v-if="purchase.detalles?.length" class="detail-row">
              <td colspan="5">
                <ul class="detail-list">
                  <li v-for="detail in purchase.detalles" :key="detail.DCO_Detalle_Compra">
                    <span class="mono">{{ detail.PRO_SKU }}</span> {{ detail.PRO_Nombre }}
                    · {{ detail.DCO_Cantidad }} × {{ formatCurrency(detail.DCO_Costo_Unitario) }}
                    · {{ formatCurrency(detail.DCO_Subtotal) }}
                  </li>
                </ul>
              </td>
            </tr>
          </template>
        </tbody>
      </table></div>
    </section>
  </section>
  <section class="panel">
    <div class="panel-heading"><PackageCheck :size="18" /><h2>Recepciones</h2></div>
    <form class="filter-bar" @submit.prevent="searchReceipts">
      <label>Buscar
        <input v-model="receiptSearch" type="search" placeholder="Recepción, compra, proveedor o producto" />
      </label>
      <label>Desde<input v-model="receiptFrom" type="date" /></label>
      <label>Hasta<input v-model="receiptTo" type="date" /></label>
      <button class="button primary" type="submit"><Search :size="16" /> Buscar</button>
    </form>
    <p class="muted">Recepciones encontradas: {{ receiptPagination.total }}</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Recepción</th><th>Compra</th><th>Fecha</th><th>Proveedor</th><th>Recibió</th><th>Total</th><th></th></tr></thead>
      <tbody>
        <tr v-for="receipt in receipts" :key="receipt.REC_Recepcion">
          <td class="mono">#{{ receipt.REC_Recepcion }}</td>
          <td class="mono">#{{ receipt.COM_Compra }}</td>
          <td>{{ receipt.REC_Fecha }}</td>
          <td>{{ receipt.PRV_Razon_Social }}</td>
          <td>{{ receipt.USU_Primer_Nombre }} {{ receipt.USU_Primer_Apellido }}</td>
          <td class="mono">{{ formatCurrency(receipt.COM_Total) }}</td>
          <td><button class="link" type="button" @click="viewReceipt(receipt.REC_Recepcion)"><Eye :size="14" /> Ver detalle</button></td>
        </tr>
        <tr v-if="receipts.length === 0"><td colspan="7">No hay recepciones que coincidan con la búsqueda.</td></tr>
      </tbody>
    </table></div>
    <div v-if="receiptPagination.last_page > 1" class="actions pagination">
      <button class="button secondary" type="button" :disabled="receiptPage <= 1" @click="changeReceiptPage(receiptPage - 1)">Anterior</button>
      <span class="muted">Página {{ receiptPagination.current_page }} de {{ receiptPagination.last_page }}</span>
      <button class="button secondary" type="button" :disabled="receiptPage >= receiptPagination.last_page" @click="changeReceiptPage(receiptPage + 1)">Siguiente</button>
    </div>
  </section>
  <section v-if="selectedReceipt" class="panel">
    <div class="panel-heading">
      <div><PackageCheck :size="18" /><h2>Detalle de recepción #{{ selectedReceipt.REC_Recepcion }}</h2></div>
      <button class="icon-button" type="button" aria-label="Cerrar detalle" @click="selectedReceipt = null"><X :size="18" /></button>
    </div>
    <div class="form-grid">
      <p><strong>Compra:</strong> #{{ selectedReceipt.COM_Compra }} · {{ selectedReceipt.COM_Fecha }}</p>
      <p><strong>Proveedor:</strong> {{ selectedReceipt.PRV_Razon_Social }} ({{ selectedReceipt.PRV_Identificacion_Fiscal }})</p>
      <p><strong>Contacto:</strong> {{ selectedReceipt.PRV_Contacto || '—' }} · {{ selectedReceipt.PRV_Telefono || '—' }} · {{ selectedReceipt.PRV_Correo || '—' }}</p>
      <p><strong>Recibió:</strong> {{ [selectedReceipt.USU_Primer_Nombre, selectedReceipt.USU_Segundo_Nombre, selectedReceipt.USU_Primer_Apellido, selectedReceipt.USU_Segundo_Apellido].filter(Boolean).join(' ') }}</p>
      <p><strong>Fecha de recepción:</strong> {{ selectedReceipt.REC_Fecha }}</p>
      <p><strong>Observación:</strong> {{ selectedReceipt.REC_Observacion || '—' }}</p>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>SKU</th><th>Producto</th><th>Cantidad recibida</th><th>Costo unitario</th><th>Subtotal</th></tr></thead>
      <tbody>
        <tr v-for="detail in selectedReceipt.detalles" :key="detail.DCO_Detalle_Compra">
          <td class="mono">{{ detail.PRO_SKU }}</td>
          <td>{{ detail.PRO_Nombre }}</td>
          <td>{{ detail.DCO_Cantidad }}</td>
          <td class="mono">{{ formatCurrency(detail.DCO_Costo_Unitario) }}</td>
          <td class="mono">{{ formatCurrency(detail.DCO_Subtotal) }}</td>
        </tr>
        <tr><td colspan="4"><strong>Total de compra</strong></td><td class="mono"><strong>{{ formatCurrency(selectedReceipt.COM_Total) }}</strong></td></tr>
      </tbody>
    </table></div>
  </section>
</template>
