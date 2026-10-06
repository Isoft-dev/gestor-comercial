<script setup>
import { nextTick, onMounted, onUnmounted, reactive, ref } from 'vue'
import Chart from 'chart.js/auto'
import api from '../services/api'
import { barStyle, chartDefaults, chartTheme } from '../lib/charts'
import {
  BadgeDollarSign,
  CircleAlert,
  ClipboardClock,
  Filter,
  LayoutDashboard,
  PackageMinus,
  RefreshCw,
  ShoppingCart,
  TrendingUp,
  Wallet,
} from '@lucide/vue'

const data = ref(null)
const error = ref('')
const filters = reactive({
  desde: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
  hasta: new Date().toISOString().slice(0, 10),
})
const salesCanvas = ref(null)
const productsCanvas = ref(null)
let salesChart
let productsChart

function renderCharts() {
  salesChart?.destroy()
  productsChart?.destroy()
  salesChart = null
  productsChart = null
  if (!data.value) return
  if (salesCanvas.value && data.value.ventas_por_fecha.length) {
    salesChart = new Chart(salesCanvas.value, {
      type: 'bar',
      data: {
        labels: data.value.ventas_por_fecha.map((item) => item.fecha),
        datasets: [{
          label: 'Ventas',
          data: data.value.ventas_por_fecha.map((item) => item.total),
          ...barStyle(chartTheme.teal),
        }],
      },
      options: chartDefaults(),
    })
  }
  if (productsCanvas.value && data.value.productos_mas_vendidos.length) {
    productsChart = new Chart(productsCanvas.value, {
      type: 'bar',
      data: {
        labels: data.value.productos_mas_vendidos.map((item) => item.PRO_Nombre),
        datasets: [{
          label: 'Unidades',
          data: data.value.productos_mas_vendidos.map((item) => item.cantidad),
          ...barStyle(chartTheme.amber),
        }],
      },
      options: chartDefaults(),
    })
  }
}

async function load() {
  try {
    const response = await api.get('/dashboard', { params: filters })
    data.value = response.data
    await nextTick()
    renderCharts()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
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
      <p class="eyebrow">Resumen ejecutivo</p>
      <h1 class="page-title"><LayoutDashboard :size="26" :stroke-width="2" /> Dashboard</h1>
    </div>
    <button class="button secondary" type="button" @click="load">
      <RefreshCw :size="16" :stroke-width="2" /> Actualizar
    </button>
  </header>
  <form class="filter-bar" @submit.prevent="load">
    <label>Desde<input v-model="filters.desde" type="date" required /></label>
    <label>Hasta<input v-model="filters.hasta" type="date" required /></label>
    <button class="button primary"><Filter :size="16" /> Aplicar</button>
  </form>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <template v-if="data">
    <section class="metric-grid">
      <article class="metric metric-sales">
        <div class="metric-icon"><BadgeDollarSign :size="18" /></div>
        <span>Ventas del período</span>
        <strong>${{ Number(data.indicadores.ventas).toFixed(2) }}</strong>
      </article>
      <article class="metric metric-balance">
        <div class="metric-icon"><Wallet :size="18" /></div>
        <span>Saldo pendiente</span>
        <strong>${{ Number(data.indicadores.saldo_pendiente).toFixed(2) }}</strong>
      </article>
      <article class="metric metric-pending">
        <div class="metric-icon"><ClipboardClock :size="18" /></div>
        <span>Por aprobar</span>
        <strong>{{ data.indicadores.pedidos_por_aprobar }}</strong>
      </article>
      <article class="metric metric-stock">
        <div class="metric-icon"><PackageMinus :size="18" /></div>
        <span>Bajo mínimo</span>
        <strong>{{ data.indicadores.productos_bajo_minimo }}</strong>
      </article>
      <article class="metric metric-purchases">
        <div class="metric-icon"><ShoppingCart :size="18" /></div>
        <span>Compras del período</span>
        <strong>${{ Number(data.indicadores.compras).toFixed(2) }}</strong>
      </article>
    </section>
    <p class="muted">Período: {{ data.periodo.desde }} al {{ data.periodo.hasta }}</p>
    <section class="chart-grid">
      <article class="panel chart-panel">
        <div class="panel-heading"><TrendingUp :size="18" /><h2>Ventas por fecha</h2></div>
        <p v-if="!data.ventas_por_fecha.length" class="muted">No hay ventas en el período.</p>
        <div v-show="data.ventas_por_fecha.length" class="chart-body"><canvas ref="salesCanvas"></canvas></div>
      </article>
      <article class="panel chart-panel">
        <div class="panel-heading"><PackageMinus :size="18" /><h2>Productos más vendidos</h2></div>
        <p v-if="!data.productos_mas_vendidos.length" class="muted">No hay unidades vendidas en el período.</p>
        <div v-show="data.productos_mas_vendidos.length" class="chart-body"><canvas ref="productsCanvas"></canvas></div>
      </article>
    </section>
  </template>
</template>
