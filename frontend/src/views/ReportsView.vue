<script setup>
import { nextTick, onMounted, onUnmounted, reactive, ref } from 'vue'
import Chart from 'chart.js/auto'
import api from '../services/api'
import { barStyle, chartDefaults, chartTheme } from '../lib/charts'
import { BarChart3, CircleAlert, Download, FileText, Filter, PackageMinus, ShoppingCart, TrendingUp, Truck } from '@lucide/vue'

const reports = ref(null)
const filters = reactive({
  desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
  hasta: new Date().toISOString().slice(0, 10),
})
const error = ref('')
const salesCanvas = ref(null)
const productsCanvas = ref(null)
let salesChart
let productsChart

async function load() {
  try {
    const { data } = await api.get('/reportes', { params: filters })
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
        options: chartDefaults(),
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

async function downloadFile(path, filename, type) {
  try {
    const response = await api.get(path, { params: filters, responseType: 'blob' })
    const url = URL.createObjectURL(new Blob([response.data], { type }))
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function exportCsv() {
  downloadFile('/reportes/ventas.csv', `ventas_${filters.desde}_${filters.hasta}.csv`, 'text/csv;charset=UTF-8')
}

function exportPdf(tipo) {
  downloadFile(`/reportes/${tipo}.pdf`, `${tipo}_${filters.desde}_${filters.hasta}.pdf`, 'application/pdf')
}

onMounted(load)
onUnmounted(() => {
  salesChart?.destroy()
  productsChart?.destroy()
})
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Información gerencial</p>
      <h1 class="page-title"><BarChart3 :size="26" :stroke-width="2" /> Reportes</h1>
    </div>
    <button class="button secondary" type="button" @click="exportCsv">
      <Download :size="16" /> Exportar ventas CSV
    </button>
  </header>
  <form class="filter-bar" @submit.prevent="load">
    <label>Desde<input v-model="filters.desde" type="date" required /></label>
    <label>Hasta<input v-model="filters.hasta" type="date" required /></label>
    <button class="button primary"><Filter :size="16" /> Aplicar</button>
  </form>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <template v-if="reports">
    <p class="muted">Generado: {{ reports.generado_en }}</p>
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
        <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Cantidad</th><th>Total</th></tr></thead><tbody><tr v-for="row in reports.ventas_por_fecha" :key="row.fecha"><td>{{ row.fecha }}</td><td>{{ row.cantidad }}</td><td class="mono">${{ row.total }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" @click="exportPdf('ventas')"><FileText :size="16" /> Exportar PDF</button>
      </article>
      <article class="panel">
        <div class="panel-heading"><ShoppingCart :size="18" /><h2>Productos más vendidos</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Producto</th><th>Unidades</th><th>Total</th></tr></thead><tbody><tr v-for="row in reports.productos_mas_vendidos" :key="row.PRO_SKU"><td>{{ row.PRO_Nombre }}</td><td>{{ row.cantidad }}</td><td class="mono">${{ row.total }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" @click="exportPdf('productos')"><FileText :size="16" /> Exportar PDF</button>
      </article>
      <article class="panel">
        <div class="panel-heading"><PackageMinus :size="18" /><h2>Inventario bajo</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Producto</th><th>Stock</th><th>Mínimo</th></tr></thead><tbody><tr v-for="row in reports.inventario_bajo" :key="row.PRO_SKU"><td>{{ row.PRO_Nombre }}</td><td>{{ row.INV_Existencia }}</td><td>{{ row.PRO_Existencia_Minima }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" @click="exportPdf('inventario')"><FileText :size="16" /> Exportar PDF</button>
      </article>
      <article class="panel">
        <div class="panel-heading"><Truck :size="18" /><h2>Compras por proveedor</h2></div>
        <div class="table-wrap"><table><thead><tr><th>Proveedor</th><th>Compras</th><th>Total</th></tr></thead><tbody><tr v-for="row in reports.compras_por_proveedor" :key="row.proveedor"><td>{{ row.proveedor }}</td><td>{{ row.cantidad }}</td><td class="mono">${{ row.total }}</td></tr></tbody></table></div>
        <button class="button secondary" type="button" @click="exportPdf('compras')"><FileText :size="16" /> Exportar PDF</button>
      </article>
    </section>
  </template>
</template>
