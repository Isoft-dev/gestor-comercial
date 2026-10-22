<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { CircleAlert, Filter, ShieldCheck } from '@lucide/vue'

const rows = ref([])
const modules = ref([])
const actions = ref([])
const error = ref('')
const filters = reactive({ modulo: '', accion: '', desde: '', hasta: '' })
const activeCatalog = ref('')
let blurTimeout
const filteredModules = computed(() => filterCatalog(modules.value, filters.modulo))
const filteredActions = computed(() => filterCatalog(actions.value, filters.accion))

function filterCatalog(options, query) {
  const term = query.trim().toLocaleLowerCase()
  return options
    .filter((option) => !term || option.toLocaleLowerCase().includes(term))
    .slice(0, 50)
}

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/auditoria', { params: filters })
    rows.value = data.data
    modules.value = data.catalogos.modulos
    actions.value = data.catalogos.acciones
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function openCatalog(catalog) {
  clearTimeout(blurTimeout)
  activeCatalog.value = catalog
}

function closeCatalog() {
  activeCatalog.value = ''
}

function closeCatalogOnPointerDown(event) {
  if (event.target instanceof Element && event.target.closest('.audit-catalog')) return
  closeCatalog()
}

function chooseCatalogValue(field, value) {
  filters[field] = value
  closeCatalog()
}

onMounted(() => {
  document.addEventListener('pointerdown', closeCatalogOnPointerDown)
  load()
})

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', closeCatalogOnPointerDown)
  clearTimeout(blurTimeout)
})

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
    <label>Módulo
      <div class="audit-catalog">
        <input
          v-model="filters.modulo"
          autocomplete="off"
          placeholder="Todos los módulos"
          role="combobox"
          aria-controls="audit-module-options"
          :aria-expanded="activeCatalog === 'modulo'"
          @focus="openCatalog('modulo')"
          @blur="blurTimeout = setTimeout(closeCatalog, 150)"
          @keydown.escape="closeCatalog"
        />
        <div v-if="activeCatalog === 'modulo'" id="audit-module-options" class="product-options" role="listbox">
          <p v-if="filteredModules.length === 0" class="product-option-empty">No hay módulos que coincidan.</p>
          <button
            v-for="module in filteredModules"
            :key="module"
            class="product-option"
            type="button"
            role="option"
            @mousedown.prevent
            @click="chooseCatalogValue('modulo', module)"
          >{{ module }}</button>
        </div>
      </div>
    </label>
    <label>Acción
      <div class="audit-catalog">
        <input
          v-model="filters.accion"
          autocomplete="off"
          placeholder="Todas las acciones"
          role="combobox"
          aria-controls="audit-action-options"
          :aria-expanded="activeCatalog === 'accion'"
          @focus="openCatalog('accion')"
          @blur="blurTimeout = setTimeout(closeCatalog, 150)"
          @keydown.escape="closeCatalog"
        />
        <div v-if="activeCatalog === 'accion'" id="audit-action-options" class="product-options" role="listbox">
          <p v-if="filteredActions.length === 0" class="product-option-empty">No hay acciones que coincidan.</p>
          <button
            v-for="action in filteredActions"
            :key="action"
            class="product-option"
            type="button"
            role="option"
            @mousedown.prevent
            @click="chooseCatalogValue('accion', action)"
          >{{ action }}</button>
        </div>
      </div>
    </label>
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
