<script setup>
import { nextTick, onMounted, onUnmounted, reactive, ref } from 'vue'
import Chart from 'chart.js/auto'
import api from '../services/api'
import { barStyle, chartDefaults, chartTheme } from '../lib/charts'
import { formatCurrency } from '../lib/currency'
import { BarChart3, CircleAlert, Download, ExternalLink, FileText, Filter, PackageMinus, ShoppingCart, TrendingUp, Truck, X } from '@lucide/vue'

const reports = ref(null)
const filters = reactive({
  desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
  hasta: new Date().toISOString().slice(0, 10),
  estado_pago: '',
  categoria_id: '',
  proveedor_id: '',
})
const error = ref('')
const preview = ref(null)
const opening = ref(false)
const salesCanvas = ref(null)
const productsCanvas = ref(null)
let salesChart
let productsChart

const titles = {
  ventas: 'Ventas por fecha',
  productos: 'Productos más vendidos',
  inventario: 'Inventario bajo',
  compras: 'Compras por proveedor',
}

function queryParams() {
  return {
    desde: filters.desde,
    hasta: filters.hasta,
    estado_pago: filters.estado_pago || undefined,
    categoria_id: filters.categoria_id || undefined,
    proveedor_id: filters.proveedor_id || undefined,
  }
}

async function load() {
  try {
    const { data } = await api.get('/reportes', { params: queryParams() })
    reports.value = data
    await nextTick()
    salesChart?.destroy()
    productsChart?.destroy()
    salesChart = null
    productsChart = null
    if (salesCanvas.value && data.ventas_por_fecha.length) {
      salesChart = new Chart(salesCanvas.value, {
        type: 'bar',
        data: {
          labels: data.ventas_por_fecha.map((item) => item.fecha),
          datasets: [{
            label: 'Ventas',
            data: data.ventas_por_fecha.map((item) => item.total),
            ...barStyle(chartTheme.teal),
          }],
        },
        options: chartDefaults({ currency: true }),
      })
    }
    if (productsCanvas.value && data.productos_mas_vendidos.length) {
      productsChart = new Chart(productsCanvas.value, {
        type: 'bar',
        data: {
          labels: data.productos_mas_vendidos.map((item) => item.PRO_Nombre),
          datasets: [{
            label: 'Unidades',
            data: data.productos_mas_vendidos.map((item) => item.cantidad),
            ...barStyle(chartTheme.amber),
          }],
        },
        options: chartDefaults(),
      })
    }
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function closePreview() {
  if (preview.value?.url) URL.revokeObjectURL(preview.value.url)
  preview.value = null
}

async function openPdf(tipo) {
  error.value = ''
  opening.value = true
  try {
    const response = await api.get(`/reportes/${tipo}.pdf`, { params: queryParams(), responseType: 'blob' })
    closePreview()
    preview.value = {
      kind: 'pdf',
      title: titles[tipo],
      filename: `${tipo}_${filters.desde}_${filters.hasta}.pdf`,
      url: URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' })),
    }
  } catch (requestError) {
    error.value = requestError.userMessage
  } finally {
    opening.value = false
  }
}

async function openCsv() {
  error.value = ''
  opening.value = true
  try {
    const response = await api.get('/reportes/ventas.csv', { params: queryParams(), responseType: 'blob' })
    const blob = new Blob([response.data], { type: 'text/csv;charset=UTF-8' })
    closePreview()
    preview.value = {
      kind: 'csv',
      title: 'Detalle de ventas',
      filename: `ventas_${filters.desde}_${filters.hasta}.csv`,
      url: URL.createObjectURL(blob),
      text: await blob.text(),
    }
  } catch (requestError) {
    error.value = requestError.userMessage
  } finally {
    opening.value = false
  }
}

function downloadPreview() {
  if (!preview.value?.url) return
  const link = document.createElement('a')
  link.href = preview.value.url
  link.download = preview.value.filename
  link.click()
}

function openPreviewTab() {
  if (preview.value?.url) window.open(preview.value.url, '_blank', 'noopener')
}

onMounted(load)
onUnmounted(() => {
  salesChart?.destroy()
  productsChart?.destroy()
  closePreview()
})
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Información gerencial</p>
      <h1 class="page-title"><BarChart3 :size="26" :stroke-width="2" /> Reportes</h1>
    </div>
    <button class="button secondary" type="button" :disabled="opening" @click="openCsv">
      <FileText :size="16" /> Ver ventas CSV
    </button>
  </header>
  <form class="filter-bar" @submit.prevent="load">
    <label>Desde<input v-model="filters.desde" type="date" required /></label>
    <label>Hasta<input v-model="filters.hasta" type="date" required /></label>
    <label>Estado de pago
      <select v-model="filters.estado_pago">
        <option value="">Todos</option>
        <option v-for="item in reports?.opciones?.estados_pago || []" :key="item.EPA_Codigo" :value="item.EPA_Codigo">{{ item.EPA_Nombre }}</option>
      </select>
    </label>
    <label>Categoría
      <select v-model="filters.categoria_id">
        <option value="">Todas</option>
        <option v-for="item in reports?.opciones?.categorias || []" :key="item.CAT_Categoria" :value="item.CAT_Categoria">{{ item.CAT_Nombre }}</option>
      </select>
    </label>
    <label>Proveedor
      <select v-model="filters.proveedor_id">
        <option value="">Todos</option>
        <option v-for="item in reports?.opciones?.proveedores || []" :key="item.PRV_Proveedor" :value="item.PRV_Proveedor">{{ item.PRV_Razon_Social }}</option>
      </select>
    </label>
    <button class="button primary"><Filter :size="16" /> Aplicar</button>
  </form>
  <p class="muted">El período aplica a ventas, productos y compras. El estado de pago filtra las ventas. La categoría filtra productos e inventario. El proveedor filtra las compras. El inventario bajo no depende de las fechas.</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <template v-if="reports">
    <p class="muted">Generado: {{ reports.generado_en }} · {{ reports.criterios }}</p>
    <section class="chart-grid">
      <article class="panel chart-panel">
        <div class="panel-heading"><TrendingUp :size="18" /><h2>Ventas por fecha</h2></div>
        <p v-if="!reports.ventas_por_fecha.length" class="muted">No hay ventas en el período.</p>
        <div v-show="reports.ventas_por_fecha.length" class="chart-body"><canvas ref="salesCanvas"></canvas></div>
      </article>
      <article class="panel chart-panel">
        <div class="panel-heading"><ShoppingCart :size="18" /><h2>Productos más vendidos</h2></div>
        <p v-if="!reports.productos_mas_vendidos.length" class="muted">No hay unidades vendidas en el período.</p>
        <div v-show="reports.productos_mas_vendidos.length" class="chart-body"><canvas ref="productsCanvas"></canvas></div>
      </article>
    </section>
    <section class="report-grid">
      <article class="panel">
        <div class="panel-heading"><TrendingUp :size="18" /><h2>Ventas por fecha</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Cantidad</th><th>Total</th></tr></thead><tbody><tr v-for="row in reports.ventas_por_fecha" :key="row.fecha"><td>{{ row.fecha }}</td><td>{{ row.cantidad }}</td><td class="mono">{{ formatCurrency(row.total) }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" :disabled="opening" @click="openPdf('ventas')"><FileText :size="16" /> Ver PDF</button>
      </article>
      <article class="panel">
        <div class="panel-heading"><ShoppingCart :size="18" /><h2>Productos más vendidos</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Producto</th><th>Unidades</th><th>Total</th></tr></thead><tbody><tr v-for="row in reports.productos_mas_vendidos" :key="row.PRO_SKU"><td>{{ row.PRO_Nombre }}</td><td>{{ row.cantidad }}</td><td class="mono">{{ formatCurrency(row.total) }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" :disabled="opening" @click="openPdf('productos')"><FileText :size="16" /> Ver PDF</button>
      </article>
      <article class="panel">
        <div class="panel-heading"><PackageMinus :size="18" /><h2>Inventario bajo</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Producto</th><th>Stock</th><th>Mínimo</th></tr></thead><tbody><tr v-for="row in reports.inventario_bajo" :key="row.PRO_SKU"><td>{{ row.PRO_Nombre }}</td><td>{{ row.INV_Existencia }}</td><td>{{ row.PRO_Existencia_Minima }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" :disabled="opening" @click="openPdf('inventario')"><FileText :size="16" /> Ver PDF</button>
      </article>
      <article class="panel">
        <div class="panel-heading"><Truck :size="18" /><h2>Compras por proveedor</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Proveedor</th><th>Compras</th><th>Total</th></tr></thead><tbody><tr v-for="row in reports.compras_por_proveedor" :key="row.proveedor"><td>{{ row.proveedor }}</td><td>{{ row.cantidad }}</td><td class="mono">{{ formatCurrency(row.total) }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" :disabled="opening" @click="openPdf('compras')"><FileText :size="16" /> Ver PDF</button>
      </article>
    </section>
  </template>

  <div v-if="preview" class="dialog-backdrop" @click.self="closePreview">
    <section class="preview-card" role="dialog" aria-modal="true" :aria-label="preview.title">
      <header class="preview-head">
        <h2>{{ preview.title }}</h2>
        <button class="icon-button" type="button" aria-label="Cerrar visor" @click="closePreview"><X :size="18" /></button>
      </header>
      <iframe v-if="preview.kind === 'pdf'" class="preview-frame" :src="preview.url" :title="preview.title"></iframe>
      <pre v-else class="preview-text">{{ preview.text }}</pre>
      <footer class="dialog-actions">
        <button class="button secondary" type="button" @click="openPreviewTab"><ExternalLink :size="16" /> Abrir en pestaña</button>
        <button class="button primary" type="button" @click="downloadPreview"><Download :size="16" /> Descargar</button>
      </footer>
    </section>
  </div>
</template>
