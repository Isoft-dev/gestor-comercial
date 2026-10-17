<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { CircleAlert, CircleCheck, RefreshCw, Search, SlidersHorizontal, TriangleAlert, Warehouse } from '@lucide/vue'
import SortHeader from '../components/SortHeader.vue'

const auth = useAuthStore()
const stock = ref([])
const movements = ref([])
const alerts = ref([])
const error = ref('')
const message = ref('')
const adjustment = reactive({ producto_id: '', tipo: 'AJUSTE_ENTRADA', cantidad: 1, motivo: '' })
const search = ref('')
const stockFilter = ref('todos')
const sort = ref('id')
const direction = ref('asc')

async function load() {
  try {
    const { data } = await api.get('/inventario', {
      params: {
        completo: 1,
        por_pagina: 100,
        buscar: search.value || undefined,
        stock: stockFilter.value,
        orden: sort.value,
        direccion: direction.value,
      },
    })
    stock.value = data.stock.data
    movements.value = data.movimientos.data
    alerts.value = data.alertas
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submitAdjustment() {
  try {
    await api.post('/inventario/ajustes', adjustment)
    message.value = 'Movimiento registrado.'
    adjustment.motivo = ''
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

onMounted(load)

function toggleSort(column) {
  if (sort.value === column) direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  else {
    sort.value = column
    direction.value = 'asc'
  }
  load()
}
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Existencias trazables</p>
      <h1 class="page-title"><Warehouse :size="26" :stroke-width="2" /> Inventario</h1>
    </div>
    <button class="button secondary" type="button" @click="load">
      <RefreshCw :size="16" /> Actualizar
    </button>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <form class="filter-bar" @submit.prevent="load">
    <label>Buscar
      <input v-model="search" type="search" placeholder="ID, SKU o producto" />
    </label>
    <label>Stock
      <select v-model="stockFilter" @change="load">
        <option value="todos">Todo</option>
        <option value="bajo">Bajo mínimo</option>
      </select>
    </label>
    <button class="button primary" type="submit"><Search :size="16" /> Consultar</button>
  </form>
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
  <form v-if="auth.can('INVENTARIO.AJUSTAR')" class="panel compact-form" @submit.prevent="submitAdjustment">
    <div class="panel-heading"><SlidersHorizontal :size="18" /><h2>Ajuste supervisado</h2></div>
    <label>Producto<select v-model="adjustment.producto_id" required><option value="">Seleccione</option><option v-for="item in stock" :key="item.PRO_Producto" :value="item.PRO_Producto">{{ item.PRO_SKU }} · {{ item.PRO_Nombre }}</option></select></label>
    <label>Tipo<select v-model="adjustment.tipo"><option>AJUSTE_ENTRADA</option><option>AJUSTE_SALIDA</option><option>DEVOLUCION</option></select></label>
    <label>Cantidad<input v-model.number="adjustment.cantidad" type="number" min="1" required /></label>
    <label>Motivo<input v-model="adjustment.motivo" required /></label>
    <button class="button primary"><SlidersHorizontal :size="16" /> Registrar</button>
  </form>
  <section class="panel">
    <div class="panel-heading"><Warehouse :size="18" /><h2>Existencias actuales</h2></div>
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
        <tr v-if="stock.length === 0"><td colspan="6">No hay existencias que coincidan con el filtro.</td></tr>
      </tbody>
    </table></div>
  </section>
  <section class="panel">
    <div class="panel-heading"><RefreshCw :size="18" /><h2>Movimientos recientes</h2></div>
    <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Saldo</th><th>Documento</th></tr></thead>
      <tbody><tr v-for="item in movements" :key="item.MOV_Movimiento_Inventario"><td>{{ item.MOV_Fecha }}</td><td class="mono">{{ item.PRO_SKU }} · {{ item.PRO_Nombre }}</td><td><span class="status" :data-state="item.MOV_Tipo">{{ item.MOV_Tipo }}</span></td><td>{{ item.MOV_Cantidad }}</td><td>{{ item.MOV_Existencia_Nueva }}</td><td>{{ item.MOV_Documento_Tipo }} #{{ item.MOV_Documento }}</td></tr></tbody>
    </table></div>
  </section>
</template>
