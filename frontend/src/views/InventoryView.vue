<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { CircleAlert, CircleCheck, RefreshCw, Search, SlidersHorizontal, TriangleAlert, Warehouse, X } from '@lucide/vue'
import SortHeader from '../components/SortHeader.vue'

const auth = useAuthStore()
const stock = ref([])
const categories = ref([])
const movements = ref([])
const alerts = ref([])
const error = ref('')
const message = ref('')
const stockFilters = reactive({ buscar: '', categoria_id: '', stock: 'todos' })
const stockPage = ref(1)
const stockPagination = ref({ current_page: 1, last_page: 1, total: 0 })
const adjustment = reactive({ producto_id: '', tipo: 'AJUSTE_ENTRADA', cantidad: 1, motivo: '' })
const formOpen = ref(false)
const formPanel = ref(null)
const sort = ref('id')
const direction = ref('asc')
const productQuery = ref('')
const productOptions = ref([])
const productDropdownOpen = ref(false)
const productSearchLoading = ref(false)
let productSearchTimeout
let productBlurTimeout
let productSearchRequest = 0
const filteredProductOptions = computed(() => {
  const query = productQuery.value.trim().toLocaleLowerCase()
  if (!query) return productOptions.value

  return productOptions.value.filter((item) =>
    `${item.PRO_SKU} ${item.PRO_Nombre}`.toLocaleLowerCase().includes(query),
  )
})

async function load(full = true) {
  const complete = full !== false || !categories.value.length
  try {
    const params = {
      page: stockPage.value,
      por_pagina: 20,
      buscar: stockFilters.buscar || undefined,
      categoria_id: stockFilters.categoria_id || undefined,
      stock: stockFilters.stock,
      orden: sort.value,
      direccion: direction.value,
    }
    if (complete) params.completo = 1
    const { data } = await api.get('/inventario', { params })
    if (complete) {
      stock.value = data.stock.data
      categories.value = data.categorias
      stockPagination.value = {
        current_page: data.stock.current_page,
        last_page: data.stock.last_page,
        total: data.stock.total,
      }
      movements.value = data.movimientos.data
      alerts.value = data.alertas
      return
    }
    stock.value = data.data
    stockPagination.value = {
      current_page: data.current_page,
      last_page: data.last_page,
      total: data.total,
    }
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function searchStock() {
  stockPage.value = 1
  load(false)
}

function changeStockPage(page) {
  stockPage.value = page
  load(false)
}

function syncAdjustmentProduct() {
  const query = productQuery.value.trim().toLocaleLowerCase()
  const product = productOptions.value.find((item) => {
    const label = `${item.PRO_SKU} · ${item.PRO_Nombre}`.toLocaleLowerCase()
    return label === query
      || item.PRO_SKU.toLocaleLowerCase() === query
      || item.PRO_Nombre.toLocaleLowerCase() === query
  })
  adjustment.producto_id = product?.PRO_Producto || ''
}

async function searchProducts(query, requestId) {
  productSearchLoading.value = true
  try {
    const { data } = await api.get('/inventario', {
      params: { buscar: query, por_pagina: 100 },
    })
    if (requestId === productSearchRequest) productOptions.value = data.data
  } catch (requestError) {
    if (requestId === productSearchRequest) error.value = requestError.userMessage
  } finally {
    if (requestId === productSearchRequest) productSearchLoading.value = false
  }
}

function openProductOptions() {
  clearTimeout(productBlurTimeout)
  productDropdownOpen.value = true
  if (productOptions.value.length) return

  const requestId = ++productSearchRequest
  searchProducts('', requestId)
}

function selectProduct(product) {
  productQuery.value = `${product.PRO_SKU} · ${product.PRO_Nombre}`
  adjustment.producto_id = product.PRO_Producto
  productDropdownOpen.value = false
}

function closeProductDropdownOnPointerDown(event) {
  if (event.target instanceof Element && event.target.closest('.product-picker')) return
  productDropdownOpen.value = false
}

watch(productQuery, (query) => {
  clearTimeout(productSearchTimeout)
  const requestId = ++productSearchRequest
  if (!query.trim()) {
    adjustment.producto_id = ''
    if (productDropdownOpen.value) {
      searchProducts('', requestId)
    } else {
      productSearchLoading.value = false
    }
    return
  }

  const selectedOption = productOptions.value.find(
    (item) => `${item.PRO_SKU} · ${item.PRO_Nombre}`.toLocaleLowerCase() === query.trim().toLocaleLowerCase(),
  )
  if (selectedOption) {
    adjustment.producto_id = selectedOption.PRO_Producto
    productSearchLoading.value = false
    return
  }

  adjustment.producto_id = ''
  productDropdownOpen.value = true
  productSearchLoading.value = true
  productSearchTimeout = setTimeout(() => searchProducts(query.trim(), requestId), 200)
})

async function submitAdjustment() {
  syncAdjustmentProduct()
  if (!adjustment.producto_id) {
    error.value = 'Busque y seleccione un producto válido.'
    return
  }

  try {
    error.value = ''
    await api.post('/inventario/ajustes', adjustment)
    message.value = 'Movimiento registrado.'
    closeAdjustment()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function closeAdjustment() {
  formOpen.value = false
  adjustment.producto_id = ''
  adjustment.tipo = 'AJUSTE_ENTRADA'
  adjustment.cantidad = 1
  adjustment.motivo = ''
  productQuery.value = ''
  productDropdownOpen.value = false
}

function openAdjustment() {
  closeAdjustment()
  formOpen.value = true
  nextTick(() => formPanel.value?.scrollIntoView({ block: 'start' }))
}

onMounted(() => {
  document.addEventListener('pointerdown', closeProductDropdownOnPointerDown)
  load()
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeProductDropdownOnPointerDown)
  clearTimeout(productSearchTimeout)
  clearTimeout(productBlurTimeout)
})

function toggleSort(column) {
  if (sort.value === column) direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  else {
    sort.value = column
    direction.value = 'asc'
  }
  stockPage.value = 1
  load(false)
}
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Existencias trazables</p>
      <h1 class="page-title"><Warehouse :size="26" :stroke-width="2" /> Inventario</h1>
    </div>
    <div class="header-actions">
      <button v-if="auth.can('INVENTARIO.AJUSTAR')" class="button primary" type="button" @click="openAdjustment"><SlidersHorizontal :size="16" /> Nuevo ajuste</button>
      <button class="button secondary" type="button" @click="load(true)">
        <RefreshCw :size="16" /> Actualizar
      </button>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section v-if="alerts.length" class="alert warning">
    <TriangleAlert :size="16" />
    <span><strong>{{ alerts.length }} alerta(s) activa(s).</strong> Existen productos en su nivel mínimo.</span>
  </section>
  <section class="panel">
    <div class="panel-heading"><TriangleAlert :size="18" /><h2>Alertas de inventario</h2></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Fecha de alerta</th><th>SKU</th><th>Producto</th><th>Existencia actual</th><th>Mínimo requerido</th><th>Estado</th></tr></thead>
      <tbody>
        <tr v-for="alert in alerts" :key="alert.ALE_Alerta" class="critical">
          <td>{{ alert.ALE_Fecha_Generacion }}</td>
          <td class="mono">{{ alert.PRO_SKU }}</td>
          <td>{{ alert.PRO_Nombre }}</td>
          <td>{{ alert.INV_Existencia }}</td>
          <td>{{ alert.PRO_Existencia_Minima }}</td>
          <td><span class="status off">Activa</span></td>
        </tr>
        <tr v-if="alerts.length === 0">
          <td colspan="6">No hay alertas activas. Las existencias están sobre el mínimo establecido.</td>
        </tr>
      </tbody>
    </table></div>
  </section>
  <form v-if="auth.can('INVENTARIO.AJUSTAR') && formOpen" ref="formPanel" class="panel compact-form" @submit.prevent="submitAdjustment">
    <div class="panel-heading"><SlidersHorizontal :size="18" /><h2>Ajuste supervisado</h2></div>
    <label>Producto
      <div class="product-picker">
        <input
          v-model="productQuery"
          autocomplete="off"
          placeholder="Escriba o pegue el SKU o nombre"
          required
          role="combobox"
          aria-controls="inventory-product-options"
          :aria-expanded="productDropdownOpen"
          @focus="openProductOptions"
          @blur="productBlurTimeout = setTimeout(() => { productDropdownOpen = false }, 150)"
          @keydown.escape="productDropdownOpen = false"
        />
        <div v-if="productDropdownOpen" id="inventory-product-options" class="product-options" role="listbox">
          <p v-if="productSearchLoading && filteredProductOptions.length === 0" class="product-option-empty">Buscando productos...</p>
          <p v-else-if="filteredProductOptions.length === 0" class="product-option-empty">No hay productos que coincidan.</p>
          <button
            v-for="item in filteredProductOptions"
            :key="item.PRO_Producto"
            class="product-option"
            type="button"
            role="option"
            :aria-selected="adjustment.producto_id === item.PRO_Producto"
            @mousedown.prevent
            @click="selectProduct(item)"
          >
            <span class="mono">{{ item.PRO_SKU }}</span> · {{ item.PRO_Nombre }}
          </button>
        </div>
      </div>
    </label>
    <label>Tipo<select v-model="adjustment.tipo"><option>AJUSTE_ENTRADA</option><option>AJUSTE_SALIDA</option><option>DEVOLUCION</option></select></label>
    <label>Cantidad<input v-model.number="adjustment.cantidad" type="number" min="1" required /></label>
    <label>Motivo<input v-model="adjustment.motivo" required /></label>
    <div class="actions">
      <button class="button primary" type="submit"><SlidersHorizontal :size="16" /> Registrar</button>
      <button class="button secondary" type="button" @click="closeAdjustment"><X :size="16" /> Cancelar</button>
    </div>
  </form>
  <section class="panel">
    <div class="panel-heading"><Warehouse :size="18" /><h2>Existencias actuales</h2></div>
    <form class="filter-bar" @submit.prevent="searchStock">
      <label>Producto o SKU<input v-model="stockFilters.buscar" type="search" placeholder="ID, nombre o SKU" /></label>
      <label>Categoría
        <select v-model="stockFilters.categoria_id" @change="searchStock">
          <option value="">Todas las categorías</option>
          <option v-for="category in categories" :key="category.CAT_Categoria" :value="category.CAT_Categoria">{{ category.CAT_Nombre }}</option>
        </select>
      </label>
      <label>Stock
        <select v-model="stockFilters.stock" @change="searchStock">
          <option value="todos">Todo</option>
          <option value="bajo">Bajo mínimo</option>
        </select>
      </label>
      <button class="button primary" type="submit"><Search :size="16" /> Consultar</button>
    </form>
    <p class="muted">Productos encontrados: {{ stockPagination.total }}</p>
    <div class="table-wrap"><table><thead><tr>
      <SortHeader label="ID" column="id" :sort="sort" :direction="direction" @sort="toggleSort" />
      <SortHeader label="SKU" column="sku" :sort="sort" :direction="direction" @sort="toggleSort" />
      <SortHeader label="Producto" column="nombre" :sort="sort" :direction="direction" @sort="toggleSort" />
      <SortHeader label="Categoría" column="categoria" :sort="sort" :direction="direction" @sort="toggleSort" />
      <SortHeader label="Existencia" column="existencia" :sort="sort" :direction="direction" @sort="toggleSort" />
      <SortHeader label="Mínimo" column="minimo" :sort="sort" :direction="direction" @sort="toggleSort" />
    </tr></thead>
      <tbody>
        <tr v-for="item in stock" :key="item.PRO_Producto" :class="{ critical: item.bajo_minimo }"><td class="mono">{{ item.PRO_Producto }}</td><td class="mono">{{ item.PRO_SKU }}</td><td>{{ item.PRO_Nombre }}</td><td>{{ item.CAT_Nombre }}</td><td>{{ item.INV_Existencia }}</td><td>{{ item.PRO_Existencia_Minima }}</td></tr>
        <tr v-if="stock.length === 0"><td colspan="6">No hay productos que coincidan con los filtros.</td></tr>
      </tbody>
    </table></div>
    <div v-if="stockPagination.last_page > 1" class="actions pagination">
      <button class="button secondary" type="button" :disabled="stockPage <= 1" @click="changeStockPage(stockPage - 1)">Anterior</button>
      <span class="muted">Página {{ stockPagination.current_page }} de {{ stockPagination.last_page }}</span>
      <button class="button secondary" type="button" :disabled="stockPage >= stockPagination.last_page" @click="changeStockPage(stockPage + 1)">Siguiente</button>
    </div>
  </section>
  <section class="panel">
    <div class="panel-heading"><RefreshCw :size="18" /><h2>Movimientos recientes</h2></div>
    <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Saldo</th><th>Documento</th></tr></thead>
      <tbody><tr v-for="item in movements" :key="item.MOV_Movimiento_Inventario"><td>{{ item.MOV_Fecha }}</td><td class="mono">{{ item.PRO_SKU }} · {{ item.PRO_Nombre }}</td><td><span class="status" :data-state="item.MOV_Tipo">{{ item.MOV_Tipo }}</span></td><td>{{ item.MOV_Cantidad }}</td><td>{{ item.MOV_Existencia_Nueva }}</td><td>{{ item.MOV_Documento_Tipo }} #{{ item.MOV_Documento }}</td></tr></tbody>
    </table></div>
  </section>
</template>
