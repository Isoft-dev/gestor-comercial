<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { notify } from '../lib/notifications'
import { askPrompt } from '../lib/dialog'
import { formatCurrency } from '../lib/currency'
import { clampAmount } from '../lib/fields'
import { useAuthStore } from '../stores/auth'
import { Ban, Check, CircleAlert, CircleCheck, ClipboardList, Filter, Plus, RefreshCw, ShoppingBag, X } from '@lucide/vue'

const auth = useAuthStore()
const orders = ref([])
const clients = ref([])
const products = ref([])
const error = ref('')
const message = ref('')
const form = reactive({ cliente_id: '', producto_id: '', cantidad: 1, descuento_porcentaje: 0 })
const formOpen = ref(false)
const clientQuery = ref('')
const productQuery = ref('')
const clientDropdownOpen = ref(false)
const productDropdownOpen = ref(false)
const lines = ref([])
const selectedOrder = ref(null)
const subtotal = computed(() => lines.value.reduce((sum, line) => sum + line.cantidad * Number(line.PRO_Precio), 0))
const total = computed(() => subtotal.value * (1 - Number(form.descuento_porcentaje || 0) / 100))

function limitDiscount(event) {
  const raw = Number(event.target.value)
  if (event.target.value === '' || (raw >= 0 && raw <= 100)) return
  notify(raw > 100 ? 'El descuento no puede ser mayor a 100 %.' : 'El descuento no puede ser negativo.', 'error')
  form.descuento_porcentaje = clampAmount(raw, 100)
  event.target.value = form.descuento_porcentaje
}
const filters = reactive({ buscar: '', estado: '' })
let clientBlurTimeout
let productBlurTimeout
const filteredClients = computed(() => {
  const query = clientQuery.value.trim().toLocaleLowerCase()
  if (!query) return clients.value

  return clients.value.filter((client) =>
    [
      client.CLI_Identificacion,
      client.CLI_Primer_Nombre,
      client.CLI_Segundo_Nombre,
      client.CLI_Primer_Apellido,
      client.CLI_Segundo_Apellido,
    ]
      .filter(Boolean)
      .join(' ')
      .toLocaleLowerCase()
      .includes(query),
  )
})
const filteredProducts = computed(() => {
  const query = productQuery.value.trim().toLocaleLowerCase()
  if (!query) return products.value

  return products.value.filter((product) =>
    `${product.PRO_SKU} ${product.PRO_Nombre}`.toLocaleLowerCase().includes(query),
  )
})
const selectedProduct = computed(() =>
  products.value.find((product) => Number(product.PRO_Producto) === Number(form.producto_id)),
)
const estados = [
  { codigo: 'CREADO', nombre: 'Creado' },
  { codigo: 'PENDIENTE_APROBACION', nombre: 'Pendiente de aprobación' },
  { codigo: 'APROBADO', nombre: 'Aprobado' },
  { codigo: 'EN_PROCESO', nombre: 'En proceso' },
  { codigo: 'COMPLETADO', nombre: 'Completado' },
  { codigo: 'CANCELADO', nombre: 'Cancelado' },
]

async function load() {
  const opciones = !clients.value.length || !products.value.length
  error.value = ''
  try {
    const { data } = await api.get('/pedidos', {
      params: {
        con_opciones: opciones ? 1 : undefined,
        buscar: filters.buscar || undefined,
        estado: filters.estado || undefined,
      },
    })
    orders.value = data.data
    if (data.clientes) clients.value = data.clientes
    if (data.productos) products.value = data.productos
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function openClientOptions() {
  clearTimeout(clientBlurTimeout)
  clearTimeout(productBlurTimeout)
  productDropdownOpen.value = false
  clientDropdownOpen.value = true
}

function openProductOptions() {
  clearTimeout(productBlurTimeout)
  clearTimeout(clientBlurTimeout)
  clientDropdownOpen.value = false
  productDropdownOpen.value = true
}

function closeOrderDropdowns() {
  clearTimeout(clientBlurTimeout)
  clearTimeout(productBlurTimeout)
  clientDropdownOpen.value = false
  productDropdownOpen.value = false
}

function closeOrderDropdownsOnPointerDown(event) {
  if (event.target instanceof Element && event.target.closest('.order-picker')) return
  closeOrderDropdowns()
}

function updateClientQuery(value) {
  clientQuery.value = value
  form.cliente_id = ''
  clientDropdownOpen.value = true
}

function updateProductQuery(value) {
  productQuery.value = value
  form.producto_id = ''
  productDropdownOpen.value = true
}

function selectClient(client) {
  clientQuery.value = `${client.CLI_Identificacion} · ${[
    client.CLI_Primer_Nombre,
    client.CLI_Segundo_Nombre,
    client.CLI_Primer_Apellido,
    client.CLI_Segundo_Apellido,
  ].filter(Boolean).join(' ')}`
  form.cliente_id = client.CLI_Cliente
  clientDropdownOpen.value = false
}

function selectProduct(product) {
  if (Number(product.INV_Existencia) <= 0) {
    notify(`${product.PRO_Nombre}: no disponible por falta de stock.`, 'error')
    return
  }

  productQuery.value = `${product.PRO_SKU} · ${product.PRO_Nombre}`
  form.producto_id = product.PRO_Producto
  productDropdownOpen.value = false
}

function lineExceedsStock(detail) {
  return Number(detail.DPE_Cantidad) > Number(detail.INV_Existencia ?? 0)
}

function orderExceedsStock(order) {
  return (order.detalles || []).some(lineExceedsStock)
}

function clearOrderForm() {
  lines.value = []
  form.cliente_id = ''
  form.producto_id = ''
  form.cantidad = 1
  form.descuento_porcentaje = 0
  clientQuery.value = ''
  productQuery.value = ''
  clientDropdownOpen.value = false
  productDropdownOpen.value = false
}

function closeForm() {
  formOpen.value = false
  clearOrderForm()
}

function openCreate() {
  clearOrderForm()
  formOpen.value = true
}

async function submit() {
  error.value = ''
  message.value = ''
  if (form.producto_id && !addLine()) return
  if (!form.cliente_id || !lines.value.length) {
    error.value = 'Seleccione un cliente y agregue al menos un producto al detalle.'
    notify(error.value, 'error')
    return
  }
  const discount = Number(form.descuento_porcentaje || 0)
  if (Number.isNaN(discount) || discount < 0 || discount > 100) {
    error.value = 'El descuento debe estar entre 0 % y 100 %.'
    notify(error.value, 'error')
    return
  }
  try {
    await api.post('/pedidos', {
      cliente_id: form.cliente_id,
      descuento_porcentaje: form.descuento_porcentaje,
      detalles: lines.value.map((line) => ({ producto_id: line.PRO_Producto, cantidad: line.cantidad })),
    })
    message.value = Number(form.descuento_porcentaje) > 10
      ? 'Pedido creado. El descuento supera 10 % y requerirá aprobación.'
      : 'Pedido creado.'
    closeForm()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function action(path, payload = {}) {
  error.value = ''
  message.value = ''
  try {
    const { data } = await api.post(path, payload)
    message.value = data.message || 'Operación completada.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }

}

function addLine() {
  const product = selectedProduct.value
  const quantity = Number(form.cantidad)
  const existing = lines.value.find((line) => line.PRO_Producto === product?.PRO_Producto)
  if (!product || !Number.isInteger(quantity) || quantity < 1
      || quantity + (existing?.cantidad || 0) > Number(product.INV_Existencia)) {
    notify('Seleccione un producto y una cantidad válida que no supere la existencia.', 'error')
    return false
  }
  if (existing) existing.cantidad += quantity
  else lines.value.push({ ...product, cantidad: quantity })
  form.producto_id = ''
  form.cantidad = 1
  productQuery.value = ''
  productDropdownOpen.value = false
  return true
}

function scheduleClientClose() {
  clientBlurTimeout = setTimeout(() => { clientDropdownOpen.value = false }, 150)
}

function scheduleProductClose() {
  productBlurTimeout = setTimeout(() => { productDropdownOpen.value = false }, 150)
}

async function cancel(id) {
  const motivo = await askPrompt({
    title: 'Cancelar pedido',
    message: 'Escriba el motivo de la cancelación.',
    confirmText: 'Cancelar pedido',
    tone: 'danger',
  })
  if (motivo) action(`/pedidos/${id}/cancelar`, { motivo })
}

async function decide(id, decision) {
  const motivo = await askPrompt({
    title: decision === 'APROBAR' ? 'Aprobar descuento' : 'Rechazar descuento',
    message: `Escriba el motivo para ${decision.toLowerCase()}.`,
    confirmText: decision === 'APROBAR' ? 'Aprobar' : 'Rechazar',
    tone: decision === 'APROBAR' ? 'warning' : 'danger',
  })
  if (motivo) action(`/pedidos/${id}/decidir`, { decision, motivo })
}

onMounted(() => {
  document.addEventListener('pointerdown', closeOrderDropdownsOnPointerDown)
  load()
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeOrderDropdownsOnPointerDown)
  clearTimeout(clientBlurTimeout)
  clearTimeout(productBlurTimeout)
})
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Flujo comercial</p>
      <h1 class="page-title"><ClipboardList :size="26" :stroke-width="2" /> Pedidos y aprobaciones</h1>
    </div>
    <div class="header-actions">
      <button v-if="auth.can('PEDIDOS.REGISTRAR')" class="button primary" type="button" @click="openCreate"><Plus :size="16" /> Nuevo pedido</button>
      <button class="button secondary" type="button" @click="load"><RefreshCw :size="16" /> Actualizar</button>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <div v-if="auth.can('PEDIDOS.REGISTRAR')" class="form-slot" :class="{ open: formOpen }">
  <div class="form-slot-inner">
  <form class="panel" :inert="!formOpen" @submit.prevent="submit">
      <div class="panel-heading"><Plus :size="18" /><h2>Nuevo pedido</h2></div>
      <label>Cliente
        <div class="order-picker">
          <input
            :value="clientQuery"
            autocomplete="off"
            placeholder="Escriba identificación o nombre"
            required
            role="combobox"
            aria-controls="order-client-options"
            :aria-expanded="clientDropdownOpen"
            @input="updateClientQuery($event.target.value)"
            @focus="openClientOptions"
            @blur="scheduleClientClose"
            @keydown.escape="clientDropdownOpen = false"
          />
          <div v-if="clientDropdownOpen" id="order-client-options" class="product-options" role="listbox">
            <p v-if="filteredClients.length === 0" class="product-option-empty">No hay clientes que coincidan.</p>
            <button
              v-for="client in filteredClients"
              :key="client.CLI_Cliente"
              class="product-option"
              type="button"
              role="option"
              :aria-selected="form.cliente_id === client.CLI_Cliente"
              @mousedown.prevent
              @click="selectClient(client)"
            >
              {{ client.CLI_Identificacion }} · {{ [client.CLI_Primer_Nombre, client.CLI_Segundo_Nombre, client.CLI_Primer_Apellido, client.CLI_Segundo_Apellido].filter(Boolean).join(' ') }}
            </button>
          </div>
        </div>
      </label>
      <label>Producto
        <div class="order-picker">
          <input
            :value="productQuery"
            autocomplete="off"
            placeholder="Escriba SKU o nombre del producto"
            role="combobox"
            aria-controls="order-product-options"
            :aria-expanded="productDropdownOpen"
            @input="updateProductQuery($event.target.value)"
            @focus="openProductOptions"
            @blur="scheduleProductClose"
            @keydown.escape="productDropdownOpen = false"
          />
          <div v-if="productDropdownOpen" id="order-product-options" class="product-options" role="listbox">
            <p v-if="filteredProducts.length === 0" class="product-option-empty">No hay productos que coincidan.</p>
            <button
              v-for="product in filteredProducts"
              :key="product.PRO_Producto"
              class="product-option"
              :class="{ unavailable: Number(product.INV_Existencia) <= 0 }"
              type="button"
              role="option"
              :aria-disabled="Number(product.INV_Existencia) <= 0"
              :aria-selected="form.producto_id === product.PRO_Producto"
              @mousedown.prevent
              @click="selectProduct(product)"
            >
              <span class="mono">{{ product.PRO_SKU }}</span> · {{ product.PRO_Nombre }} ({{ product.INV_Existencia }})
              <span v-if="Number(product.INV_Existencia) <= 0"> — No disponible</span>
            </button>
          </div>
        </div>
      </label>
      <div class="form-grid">
        <label>Cantidad<input v-model.number="form.cantidad" type="number" min="1" :max="selectedProduct ? Number(selectedProduct.INV_Existencia) : undefined" required /><small v-if="selectedProduct">Existencia disponible: {{ selectedProduct.INV_Existencia }}</small></label>
        <label>Descuento %<input v-model.number="form.descuento_porcentaje" type="number" min="0" max="100" step="0.01" title="Entre 0 % y 100 %" @input="limitDiscount" /></label>
      </div>
      <button class="button secondary" type="button" @click="addLine"><Plus :size="16" /> Agregar al detalle</button>
      <div v-if="lines.length" class="table-wrap">
        <table>
          <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th><th></th></tr></thead>
          <tbody><tr v-for="(line, index) in lines" :key="line.PRO_Producto">
            <td>{{ line.PRO_SKU }} · {{ line.PRO_Nombre }}</td>
            <td>{{ line.cantidad }}</td><td>{{ formatCurrency(line.PRO_Precio) }}</td>
            <td>{{ formatCurrency(line.cantidad * Number(line.PRO_Precio)) }}</td>
            <td><button class="link danger" type="button" @click="lines.splice(index, 1)">Quitar</button></td>
          </tr></tbody>
        </table>
        <p>Subtotal: {{ formatCurrency(subtotal) }} · Descuento: {{ formatCurrency(subtotal - total) }} · Total: {{ formatCurrency(total) }}</p>
      </div>
      <p v-if="selectedProduct && Number(form.cantidad) > Number(selectedProduct.INV_Existencia)" class="alert error"><CircleAlert :size="16" /> No se puede vender más de la existencia disponible.</p>
      <p v-if="Number(form.descuento_porcentaje) > 10" class="muted">Un descuento mayor a 10 % queda pendiente hasta que un supervisor lo apruebe.</p>
      <div class="actions">
        <button class="button primary" type="submit"><Plus :size="16" /> Crear pedido</button>
        <button class="button secondary" type="button" @click="closeForm"><X :size="16" /> Cancelar</button>
      </div>
    </form>
  </div>
  </div>
    <section class="panel">
      <div class="panel-heading"><ClipboardList :size="18" /><h2>Pedidos</h2></div>
      <form class="filter-bar" @submit.prevent="load">
        <label>Buscar<input v-model="filters.buscar" type="search" placeholder="ID, identificación o cliente" /></label>
        <label>Estado
          <select v-model="filters.estado">
            <option value="">Todos</option>
            <option v-for="estado in estados" :key="estado.codigo" :value="estado.codigo">{{ estado.nombre }}</option>
          </select>
        </label>
        <button class="button primary" type="submit"><Filter :size="16" /> Filtrar</button>
      </form>
      <div class="table-wrap"><table><thead><tr><th>#</th><th>Cliente</th><th>Descuento</th><th>Total</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
          <tr v-if="orders.length === 0"><td colspan="6">No hay pedidos que coincidan con la búsqueda.</td></tr>
          <template v-for="order in orders" :key="order.PED_Pedido">
            <tr>
              <td class="mono">{{ order.PED_Pedido }}</td>
              <td>{{ order.CLI_Primer_Nombre }} {{ order.CLI_Primer_Apellido }}</td>
              <td class="mono">{{ Number(order.PED_Descuento_Porcentaje) }}% · {{ formatCurrency(order.PED_Descuento_Monto) }}</td>
              <td class="mono">{{ formatCurrency(order.PED_Total) }}</td>
              <td><span class="status" :data-state="order.PED_Estado">{{ order.PED_Estado }}</span></td>
              <td class="actions">
                <button class="link" type="button" @click="selectedOrder = order">Ver detalle</button>
                <button v-if="auth.can('PEDIDOS.REGISTRAR') && order.PED_Estado === 'CREADO'" class="link" type="button" @click="action(`/pedidos/${order.PED_Pedido}/avanzar`)"><Check :size="14" /> Avanzar</button>
                <template v-if="order.PED_Estado === 'PENDIENTE_APROBACION' && auth.can('PEDIDOS.APROBAR')">
                  <button class="link" type="button" @click="decide(order.PED_Pedido, 'APROBAR')"><Check :size="14" /> Aprobar</button>
                  <button class="link danger" type="button" @click="decide(order.PED_Pedido, 'RECHAZAR')"><X :size="14" /> Rechazar</button>
                </template>
                <button v-if="auth.can('VENTAS.REGISTRAR') && ['APROBADO', 'EN_PROCESO'].includes(order.PED_Estado)" class="link" type="button" :disabled="orderExceedsStock(order)" :title="orderExceedsStock(order) ? 'La cantidad supera la existencia disponible' : ''" @click="action(`/pedidos/${order.PED_Pedido}/vender`)"><ShoppingBag :size="14" /> Vender</button>
                <button v-if="auth.can('PEDIDOS.REGISTRAR') && !['COMPLETADO', 'CANCELADO'].includes(order.PED_Estado)" class="link danger" type="button" @click="cancel(order.PED_Pedido)"><Ban :size="14" /> Cancelar</button>
              </td>
            </tr>
            <tr v-if="order.detalles?.length || order.historial?.length" class="detail-row">
              <td colspan="6">
                <ul v-if="order.detalles?.length" class="detail-list">
                  <li v-for="detail in order.detalles" :key="detail.DPE_Detalle_Pedido">
                    <span class="mono">{{ detail.PRO_SKU }}</span> {{ detail.PRO_Nombre }}
                    · {{ detail.DPE_Cantidad }} × {{ formatCurrency(detail.DPE_Precio_Unitario) }}
                    · {{ formatCurrency(detail.DPE_Subtotal) }}
                    · existencia {{ detail.INV_Existencia ?? 0 }}
                    <span v-if="lineExceedsStock(detail)"> — supera la existencia</span>
                  </li>
                </ul>
                <p v-if="order.historial?.length" class="muted">
                  Estados:
                  <span v-for="(step, index) in order.historial" :key="step.HES_Historial_Estado">
                    <template v-if="index"> → </template>{{ step.HES_Estado_Nuevo }}
                  </span>
                </p>
              </td>
            </tr>
          </template>
        </tbody>
      </table></div>
    </section>
  <Teleport to="body">
    <div v-if="selectedOrder" class="dialog-backdrop" @click.self="selectedOrder = null" @keydown.esc="selectedOrder = null">
      <section class="dialog-card sale-detail-dialog" role="dialog" aria-modal="true" :aria-label="`Detalle del pedido ${selectedOrder.PED_Pedido}`" tabindex="-1">
        <header class="sale-detail-heading">
          <h2>Pedido #{{ selectedOrder.PED_Pedido }}</h2>
          <button class="icon-button" type="button" aria-label="Cerrar detalle" autofocus @click="selectedOrder = null"><X :size="18" /></button>
        </header>
        <p>{{ selectedOrder.CLI_Primer_Nombre }} {{ selectedOrder.CLI_Primer_Apellido }} · {{ selectedOrder.CLI_Identificacion }}</p>
        <p>{{ selectedOrder.PED_Fecha }} · {{ selectedOrder.PED_Estado }}</p>
        <div class="table-wrap"><table>
          <thead><tr><th>SKU / Producto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th></tr></thead>
          <tbody><tr v-for="line in selectedOrder.detalles" :key="line.DPE_Detalle_Pedido">
            <td>{{ line.PRO_SKU }} · {{ line.PRO_Nombre }}</td><td>{{ line.DPE_Cantidad }}</td>
            <td>{{ formatCurrency(line.DPE_Precio_Unitario) }}</td><td>{{ formatCurrency(line.DPE_Subtotal) }}</td>
          </tr></tbody>
        </table></div>
        <p>Subtotal: {{ formatCurrency(selectedOrder.PED_Subtotal) }}</p>
        <p>Descuento ({{ selectedOrder.PED_Descuento_Porcentaje }}%): {{ formatCurrency(selectedOrder.PED_Descuento_Monto) }}</p>
        <h3>Total: {{ formatCurrency(selectedOrder.PED_Total) }}</h3>
        <h3>Historial</h3>
        <ul class="detail-list"><li v-for="step in selectedOrder.historial" :key="step.HES_Historial_Estado">{{ step.HES_Fecha }} · {{ step.HES_Estado_Nuevo }} · {{ step.HES_Motivo }}</li></ul>
      </section>
    </div>
  </Teleport>
</template>
