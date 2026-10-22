<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { askConfirm } from '../lib/dialog'
import { formatCurrency } from '../lib/currency'
import { CircleAlert, CircleCheck, Eye, PackageCheck, Plus, Search, Truck, X } from '@lucide/vue'
import SortHeader from '../components/SortHeader.vue'

const purchases = ref([])
const receipts = ref([])
const providers = ref([])
const products = ref([])
const error = ref('')
const message = ref('')
const purchaseStatus = ref('')
const sort = ref('id')
const direction = ref('asc')
const receiptSearch = ref('')
const receiptFrom = ref('')
const receiptTo = ref('')
const receiptPage = ref(1)
const receiptPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const selectedReceipt = ref(null)
const inspection = ref(null)
const incidents = ref([])
const resolution = reactive({ id: '', gestion: 'DEVOLUCION', resolucion: '', monto_credito: '' })
const form = reactive({ proveedor_id: '', producto_id: '', cantidad: 1, costo_unitario: 1, observacion: '' })
const providerQuery = ref('')
const providerDropdownOpen = ref(false)
const productQuery = ref('')
const productDropdownOpen = ref(false)
let providerBlurTimeout
let productBlurTimeout
const filteredProviders = computed(() => {
  const query = providerQuery.value.trim().toLocaleLowerCase()
  if (!query) return providers.value

  return providers.value.filter((provider) =>
    [
      provider.PRV_Identificacion_Fiscal,
      provider.PRV_Razon_Social,
      provider.PRV_Contacto,
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
const gestiones = [
  { codigo: 'DEVOLUCION', nombre: 'Devolución al proveedor' },
  { codigo: 'GARANTIA', nombre: 'Garantía' },
  { codigo: 'REEMPLAZO', nombre: 'Reemplazo' },
  { codigo: 'NOTA_CREDITO', nombre: 'Nota de crédito' },
]

async function load() {
  try {
    const { data } = await api.get('/compras', {
      params: {
        con_opciones: 1,
        estado: purchaseStatus.value || undefined,
        orden: sort.value,
        direccion: direction.value,
      },
    })
    purchases.value = data.data
    if (data.proveedores) providers.value = data.proveedores
    if (data.productos) products.value = data.productos
    await loadReceipts()
    await loadIncidents()
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

function openProviderOptions() {
  clearTimeout(providerBlurTimeout)
  clearTimeout(productBlurTimeout)
  productDropdownOpen.value = false
  providerDropdownOpen.value = true
}

function openProductOptions() {
  clearTimeout(productBlurTimeout)
  clearTimeout(providerBlurTimeout)
  providerDropdownOpen.value = false
  productDropdownOpen.value = true
}

function closePurchaseDropdowns() {
  clearTimeout(providerBlurTimeout)
  clearTimeout(productBlurTimeout)
  providerDropdownOpen.value = false
  productDropdownOpen.value = false
}

function closePurchaseDropdownsOnPointerDown(event) {
  if (event.target instanceof Element && event.target.closest('.order-picker')) return
  closePurchaseDropdowns()
}

function updateProviderQuery(value) {
  providerQuery.value = value
  form.proveedor_id = ''
  providerDropdownOpen.value = true
}

function updateProductQuery(value) {
  productQuery.value = value
  form.producto_id = ''
  productDropdownOpen.value = true
}

function selectProvider(provider) {
  providerQuery.value = provider.PRV_Razon_Social
  form.proveedor_id = provider.PRV_Proveedor
  providerDropdownOpen.value = false
}

function selectProduct(product) {
  productQuery.value = `${product.PRO_SKU} · ${product.PRO_Nombre}`
  form.producto_id = product.PRO_Producto
  productDropdownOpen.value = false
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
  if (!form.proveedor_id || !form.producto_id) {
    error.value = 'Seleccione un proveedor y un producto de las sugerencias.'
    return
  }

  error.value = ''
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

function openReceive(purchase) {
  error.value = ''
  message.value = ''
  inspection.value = {
    id: purchase.COM_Compra,
    proveedor: purchase.PRV_Razon_Social,
    loteIndivisible: false,
    observacion: '',
    lineas: (purchase.detalles || [])
      .filter((detail) => Number(detail.DCO_Pendiente ?? detail.DCO_Cantidad) > 0)
      .map((detail) => ({
        producto_id: detail.PRO_Producto,
        sku: detail.PRO_SKU,
        nombre: detail.PRO_Nombre,
        comprada: Number(detail.DCO_Cantidad),
        pendiente: Number(detail.DCO_Pendiente ?? detail.DCO_Cantidad),
        aceptada: Number(detail.DCO_Pendiente ?? detail.DCO_Cantidad),
        danada: 0,
        faltante: 0,
        motivo: '',
        foto: null,
      })),
  }
}

function lineGap(line) {
  return line.pendiente - (Number(line.aceptada) || 0) - (Number(line.danada) || 0) - (Number(line.faltante) || 0)
}

function setPhoto(line, event) {
  line.foto = event.target.files?.[0] || null
}

async function confirmReceive() {
  error.value = ''
  const lines = inspection.value?.lineas || []
  if (lines.some((line) => lineGap(line) !== 0)) {
    error.value = 'La suma de unidades buenas, dañadas y faltantes debe ser igual a la cantidad pendiente.'
    return
  }
  if (lines.some((line) => Number(line.danada) > 0 && !line.motivo.trim())) {
    error.value = 'Indique el motivo de cada producto dañado.'
    return
  }
  const accepted = lines.reduce((sum, line) => sum + Number(line.aceptada || 0), 0)
  const damaged = lines.reduce((sum, line) => sum + Number(line.danada || 0), 0)
  const missing = lines.reduce((sum, line) => sum + Number(line.faltante || 0), 0)
  if (inspection.value.loteIndivisible && (damaged > 0 || missing > 0)) {
    error.value = 'El lote no se puede dividir. Deje la recepción pendiente o marque todas las unidades como buenas.'
    return
  }
  const confirmed = await askConfirm({
    title: 'Confirmar recepción',
    message: `Entrarán ${accepted} unidades al inventario. ${damaged} dañadas quedarán como incidencia. ${missing} faltantes seguirán pendientes.`,
    confirmText: 'Confirmar recepción',
    tone: 'warning',
  })
  if (!confirmed) return
  const body = new FormData()
  body.append('observacion', inspection.value.observacion || '')
  body.append('lote_indivisible', inspection.value.loteIndivisible ? '1' : '0')
  body.append('lineas', JSON.stringify(lines.map((line) => ({
    producto_id: line.producto_id,
    aceptada: Number(line.aceptada) || 0,
    danada: Number(line.danada) || 0,
    faltante: Number(line.faltante) || 0,
    motivo: line.motivo,
  }))))
  lines.forEach((line) => {
    if (line.foto && Number(line.danada) > 0) body.append(`fotos[${line.producto_id}]`, line.foto)
  })
  try {
    const { data } = await api.post(`/compras/${inspection.value.id}/recibir`, body)
    message.value = data.message
    inspection.value = null
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function loadIncidents() {
  try {
    const { data } = await api.get('/incidencias', { params: { estado: 'PENDIENTE' } })
    incidents.value = data.data
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function resolveIncident() {
  error.value = ''
  try {
    const { data } = await api.post(`/incidencias/${resolution.id}/resolver`, {
      gestion: resolution.gestion,
      resolucion: resolution.resolucion,
      monto_credito: resolution.gestion === 'NOTA_CREDITO' ? resolution.monto_credito : undefined,
    })
    message.value = data.message
    resolution.id = ''
    resolution.resolucion = ''
    resolution.monto_credito = ''
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', closePurchaseDropdownsOnPointerDown)
  load()
})

function toggleSort(column) {
  if (sort.value === column) direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  else {
    sort.value = column
    direction.value = 'asc'
  }
  load()
}

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closePurchaseDropdownsOnPointerDown)
  clearTimeout(providerBlurTimeout)
  clearTimeout(productBlurTimeout)
})
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
      <label>Proveedor
        <div class="order-picker">
          <input
            :value="providerQuery"
            autocomplete="off"
            placeholder="Escriba nombre, identificación o contacto"
            required
            role="combobox"
            aria-controls="purchase-provider-options"
            :aria-expanded="providerDropdownOpen"
            @input="updateProviderQuery($event.target.value)"
            @focus="openProviderOptions"
            @blur="providerBlurTimeout = setTimeout(() => { providerDropdownOpen = false }, 150)"
            @keydown.escape="providerDropdownOpen = false"
          />
          <div v-if="providerDropdownOpen" id="purchase-provider-options" class="product-options" role="listbox">
            <p v-if="filteredProviders.length === 0" class="product-option-empty">No hay proveedores que coincidan.</p>
            <button
              v-for="provider in filteredProviders"
              :key="provider.PRV_Proveedor"
              class="product-option"
              type="button"
              role="option"
              :aria-selected="form.proveedor_id === provider.PRV_Proveedor"
              @mousedown.prevent
              @click="selectProvider(provider)"
            >
              {{ provider.PRV_Razon_Social }}<span v-if="provider.PRV_Identificacion_Fiscal"> · {{ provider.PRV_Identificacion_Fiscal }}</span>
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
            required
            role="combobox"
            aria-controls="purchase-product-options"
            :aria-expanded="productDropdownOpen"
            @input="updateProductQuery($event.target.value)"
            @focus="openProductOptions"
            @blur="productBlurTimeout = setTimeout(() => { productDropdownOpen = false }, 150)"
            @keydown.escape="productDropdownOpen = false"
          />
          <div v-if="productDropdownOpen" id="purchase-product-options" class="product-options" role="listbox">
            <p v-if="filteredProducts.length === 0" class="product-option-empty">No hay productos que coincidan.</p>
            <button
              v-for="product in filteredProducts"
              :key="product.PRO_Producto"
              class="product-option"
              type="button"
              role="option"
              :aria-selected="form.producto_id === product.PRO_Producto"
              @mousedown.prevent
              @click="selectProduct(product)"
            >
              <span class="mono">{{ product.PRO_SKU }}</span> · {{ product.PRO_Nombre }}
            </button>
          </div>
        </div>
      </label>
      <div class="form-grid"><label>Cantidad<input v-model.number="form.cantidad" type="number" min="1" required /></label><label>Costo unitario (Q)<input v-model.number="form.costo_unitario" type="number" min="0.01" step="0.01" required /></label></div>
      <label>Observación<textarea v-model="form.observacion"></textarea></label>
      <button class="button primary"><Plus :size="16" /> Registrar compra</button>
    </form>
    <section class="panel">
      <div class="panel-heading"><PackageCheck :size="18" /><h2>Órdenes recientes</h2></div>
      <form class="filter-bar" @submit.prevent="load">
        <label>Estado
          <select v-model="purchaseStatus" @change="load">
            <option value="">Todos</option>
            <option value="PENDIENTE">Pendiente</option>
            <option value="PARCIAL">Recepción parcial</option>
            <option value="RECIBIDA">Recibida</option>
            <option value="CANCELADA">Cancelada</option>
          </select>
        </label>
        <button class="button primary" type="submit"><Search :size="16" /> Consultar</button>
      </form>
      <div class="table-wrap"><table>
        <thead><tr>
          <SortHeader label="ID" column="id" :sort="sort" :direction="direction" @sort="toggleSort" />
          <SortHeader label="Proveedor" column="proveedor" :sort="sort" :direction="direction" @sort="toggleSort" />
          <SortHeader label="Total" column="total" :sort="sort" :direction="direction" @sort="toggleSort" />
          <SortHeader label="Estado" column="estado" :sort="sort" :direction="direction" @sort="toggleSort" />
          <th></th>
        </tr></thead>
        <tbody>
          <template v-for="purchase in purchases" :key="purchase.COM_Compra">
            <tr>
              <td class="mono">{{ purchase.COM_Compra }}</td>
              <td>{{ purchase.PRV_Razon_Social }}</td>
              <td class="mono">{{ formatCurrency(purchase.COM_Total) }}</td>
              <td><span class="status" :data-state="purchase.COM_Estado">{{ purchase.COM_Estado }}</span></td>
              <td><button v-if="['PENDIENTE', 'PARCIAL'].includes(purchase.COM_Estado)" class="link" type="button" @click="openReceive(purchase)"><PackageCheck :size="14" /> Recibir productos</button></td>
            </tr>
            <tr v-if="purchase.detalles?.length" class="detail-row">
              <td colspan="5">
                <ul class="detail-list">
                  <li v-for="detail in purchase.detalles" :key="detail.DCO_Detalle_Compra">
                    <span class="mono">{{ detail.PRO_SKU }}</span> {{ detail.PRO_Nombre }}
                    · compradas {{ detail.DCO_Cantidad }}
                    · pendientes {{ detail.DCO_Pendiente ?? detail.DCO_Cantidad }}
                    · {{ formatCurrency(detail.DCO_Costo_Unitario) }}
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
  <section v-if="inspection" class="panel">
    <div class="panel-heading">
      <div><PackageCheck :size="18" /><h2>Inspección de la compra #{{ inspection.id }}</h2></div>
      <button class="button secondary" type="button" @click="inspection = null">Dejar pendiente</button>
    </div>
    <p class="muted">{{ inspection.proveedor }}. Solo las unidades buenas aumentan el inventario. Las dañadas quedan como incidencia y las faltantes siguen pendientes. Si el proveedor exige devolver todo el pedido, use Dejar pendiente: no se confirma el ingreso.</p>
    <div class="table-wrap"><table class="inspect-table">
      <thead><tr><th>Producto</th><th>Comprada</th><th>Pendiente</th><th>Buenas</th><th>Dañadas</th><th>Faltantes</th><th>Motivo y foto</th></tr></thead>
      <tbody>
        <tr v-for="line in inspection.lineas" :key="line.producto_id">
          <td><span class="mono">{{ line.sku }}</span> {{ line.nombre }}</td>
          <td>{{ line.comprada }}</td>
          <td>{{ line.pendiente }}</td>
          <td><input v-model.number="line.aceptada" type="number" min="0" :max="line.pendiente" /></td>
          <td><input v-model.number="line.danada" type="number" min="0" :max="line.pendiente" /></td>
          <td><input v-model.number="line.faltante" type="number" min="0" :max="line.pendiente" /></td>
          <td>
            <template v-if="Number(line.danada) > 0">
              <input v-model="line.motivo" type="text" placeholder="Motivo del daño" maxlength="1000" />
              <input type="file" accept="image/jpeg,image/png,image/webp" @change="setPhoto(line, $event)" />
            </template>
            <span v-else-if="lineGap(line) !== 0" class="muted">Faltan {{ lineGap(line) }} por clasificar</span>
            <span v-else class="muted">Listo</span>
          </td>
        </tr>
      </tbody>
    </table></div>
    <label class="check-line"><input v-model="inspection.loteIndivisible" type="checkbox" /> El proveedor no permite dividir el lote. Si hay dañadas o faltantes, no se confirma la recepción.</label>
    <label>Observación<textarea v-model="inspection.observacion"></textarea></label>
    <button class="button primary" type="button" @click="confirmReceive"><PackageCheck :size="16" /> Confirmar recepción</button>
  </section>
  <section v-if="incidents.length" class="panel">
    <div class="panel-heading"><CircleAlert :size="18" /><h2>Incidencias pendientes</h2></div>
    <div class="table-wrap"><table>
      <thead><tr><th>#</th><th>Compra</th><th>Producto</th><th>Cantidad</th><th>Motivo</th><th>Evidencia</th><th></th></tr></thead>
      <tbody>
        <tr v-for="incident in incidents" :key="incident.INC_Incidencia">
          <td class="mono">{{ incident.INC_Incidencia }}</td>
          <td class="mono">#{{ incident.COM_Compra }} · {{ incident.PRV_Razon_Social }}</td>
          <td>{{ incident.PRO_Nombre }}</td>
          <td>{{ incident.INC_Cantidad }}</td>
          <td>{{ incident.INC_Motivo }}</td>
          <td><a v-if="incident.INC_Foto_URL" :href="incident.INC_Foto_URL" target="_blank" rel="noopener">Ver foto</a><span v-else class="muted">Sin foto</span></td>
          <td><button class="link" type="button" @click="resolution.id = incident.INC_Incidencia">Gestionar</button></td>
        </tr>
      </tbody>
    </table></div>
    <form v-if="resolution.id" class="filter-bar" @submit.prevent="resolveIncident">
      <label>Gestión
        <select v-model="resolution.gestion">
          <option v-for="item in gestiones" :key="item.codigo" :value="item.codigo">{{ item.nombre }}</option>
        </select>
      </label>
      <label>Resolución<input v-model="resolution.resolucion" required maxlength="1000" /></label>
      <label v-if="resolution.gestion === 'NOTA_CREDITO'">Monto (Q)<input v-model.number="resolution.monto_credito" type="number" min="0.01" step="0.01" required /></label>
      <button class="button primary" type="submit">Registrar gestión</button>
    </form>
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
      <thead><tr><th>SKU</th><th>Producto</th><th>Buenas</th><th>Dañadas</th><th>Faltantes</th><th>Costo unitario</th><th>Subtotal</th></tr></thead>
      <tbody>
        <tr v-for="detail in selectedReceipt.detalles" :key="detail.DCO_Detalle_Compra">
          <td class="mono">{{ detail.PRO_SKU }}</td>
          <td>{{ detail.PRO_Nombre }}<small v-if="detail.DRE_Motivo" class="muted"> · {{ detail.DRE_Motivo }}</small></td>
          <td>{{ detail.DRE_Aceptada ?? detail.DCO_Cantidad }}</td>
          <td>{{ detail.DRE_Danada ?? 0 }}</td>
          <td>{{ detail.DRE_Faltante ?? 0 }}</td>
          <td class="mono">{{ formatCurrency(detail.DCO_Costo_Unitario) }}</td>
          <td class="mono">{{ formatCurrency(detail.DCO_Subtotal) }}</td>
        </tr>
        <tr><td colspan="6"><strong>Total de compra</strong></td><td class="mono"><strong>{{ formatCurrency(selectedReceipt.COM_Total) }}</strong></td></tr>
      </tbody>
    </table></div>
  </section>
</template>
