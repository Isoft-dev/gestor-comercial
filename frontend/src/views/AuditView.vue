<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { CircleAlert, Filter, ShieldCheck } from '@lucide/vue'

const rows = ref([])
const error = ref('')
const filters = reactive({ modulo: '', accion: '', desde: '', hasta: '' })

async function load() {
  try {
    const { data } = await api.get('/auditoria', { params: filters })
    rows.value = data.data
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Trazabilidad</p>
      <h1 class="page-title"><ShieldCheck :size="26" :stroke-width="2" /> Auditoría</h1>
    </div>
  </header>
  <form class="filter-bar" @submit.prevent="load">
    <label>Módulo<input v-model="filters.modulo" /></label>
    <label>Acción<input v-model="filters.accion" /></label>
    <label>Desde<input v-model="filters.desde" type="date" /></label>
    <label>Hasta<input v-model="filters.hasta" type="date" /></label>
    <button class="button primary"><Filter :size="16" /> Filtrar</button>
  </form>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section class="panel">
    <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Usuario</th><th>Módulo</th><th>Acción</th><th>Registro</th><th>Datos</th></tr></thead>
      <tbody><tr v-for="row in rows" :key="row.AUD_Auditoria"><td>{{ row.AUD_Fecha }}</td><td>{{ row.USU_Primer_Nombre }} {{ row.USU_Primer_Apellido }}</td><td>{{ row.AUD_Modulo }}</td><td>{{ row.AUD_Accion }}</td><td class="mono">{{ row.AUD_Registro }}</td><td><code>{{ row.AUD_Datos }}</code></td></tr></tbody>
    </table></div>
  </section>
</template>
