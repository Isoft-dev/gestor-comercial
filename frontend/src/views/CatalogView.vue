<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import { askConfirm } from '../lib/dialog'
import { fields as masks } from '../lib/fields'
import { useAuthStore } from '../stores/auth'
import { Ban, Building2, CircleAlert, CircleCheck, Layers, Pencil, Save, Search, Tags, X } from '@lucide/vue'
import SortHeader from '../components/SortHeader.vue'

const route = useRoute()
const auth = useAuthStore()
const items = ref([])
const providerOptions = ref([])
const message = ref('')
const error = ref('')
const editingId = ref(null)
const search = ref('')
const statusFilter = ref('todos')
const page = ref(1)
const sort = ref('id')
const direction = ref('asc')
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const form = reactive({})

const definitions = {
  proveedores: [
    ['identificacion_fiscal', 'Identificación fiscal', 'text', 'codigo'],
    ['razon_social', 'Razón social', 'text', 'empresa'],
    ['contacto', 'Contacto', 'text', 'persona'],
    ['correo', 'Correo', 'email', 'correo'],
    ['telefono', 'Teléfono', 'text', 'telefono'],
  ],
  categorias: [['nombre', 'Nombre', 'text', 'catalogo']],
  marcas: [['nombre', 'Nombre', 'text', 'catalogo']],
}

const titles = {
  proveedores: 'Proveedores',
  categorias: 'Categorías',
  marcas: 'Marcas',
}

const type = computed(() => {
  const catalog = route.meta.catalog || route.params.type
  return definitions[catalog] ? catalog : 'proveedores'
})
const fields = computed(() => definitions[type.value])
const idKey = computed(() => ({
  proveedores: 'PRV_Proveedor',
  categorias: 'CAT_Categoria',
  marcas: 'MAR_Marca',
}[type.value]))
const stateKey = computed(() => ({
  proveedores: 'PRV_Estado',
  categorias: 'CAT_Estado',
  marcas: 'MAR_Estado',
}[type.value]))

function isActive(item) {
  return Boolean(item[stateKey.value])
}

function reset() {
  editingId.value = null
  Object.keys(form).forEach((key) => delete form[key])
}

function fill(item) {
  editingId.value = item[idKey.value]
  if (type.value === 'proveedores') {
    Object.assign(form, {
      identificacion_fiscal: item.PRV_Identificacion_Fiscal,
      razon_social: item.PRV_Razon_Social,
      contacto: item.PRV_Contacto || '',
      correo: item.PRV_Correo || '',
      telefono: item.PRV_Telefono || '',
    })
    return
  }
  form.nombre = item.CAT_Nombre || item.MAR_Nombre
}

async function load() {
  error.value = ''
  try {
    const params = {
      page: page.value,
      buscar: search.value,
      estado: statusFilter.value,
      por_pagina: 20,
      orden: sort.value,
      direccion: direction.value,
    }
    if (type.value === 'proveedores' && !providerOptions.value.length) params.con_opciones = 1
    const { data } = await api.get(`/catalogos/${type.value}`, { params })
    items.value = data.data
    pagination.value = {
      current_page: data.current_page || 1,
      last_page: data.last_page || 1,
      total: data.total || 0,
    }
    if (data.opciones) providerOptions.value = data.opciones
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function searchRecords() {
  page.value = 1
  load()
}

function toggleSort(column) {
  if (sort.value === column) direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  else {
    sort.value = column
    direction.value = 'asc'
  }
  page.value = 1
  load()
}

function changePage(nextPage) {
  page.value = nextPage
  load()
}

async function submit() {
  message.value = ''
  error.value = ''
  try {
    if (editingId.value) {
      await api.put(`/catalogos/${type.value}/${editingId.value}`, form)
      message.value = 'Registro actualizado correctamente.'
    } else {
      await api.post(`/catalogos/${type.value}`, form)
      message.value = 'Registro creado correctamente.'
    }
    reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function deactivate(item) {
  await changeState(item, false)
}

async function activate(item) {
  await changeState(item, true)
}

async function changeState(item, active) {
  const name = item.PRV_Razon_Social || item.CAT_Nombre || item.MAR_Nombre
  const accepted = await askConfirm({
    title: active ? 'Activar registro' : 'Inactivar registro',
    message: active
      ? `¿Desea volver a activar ${name}?`
      : `¿Desea inactivar ${name}? El registro se conservará, pero dejará de estar disponible.`,
    confirmText: active ? 'Activar' : 'Inactivar',
    tone: active ? 'warning' : 'danger',
  })
  if (!accepted) return
  message.value = ''
  error.value = ''
  try {
    if (active) {
      await api.patch(`/catalogos/${type.value}/${item[idKey.value]}/estado`)
      message.value = 'Registro activado.'
    } else {
      await api.delete(`/catalogos/${type.value}/${item[idKey.value]}`)
      message.value = 'Registro inactivado.'
    }
    if (editingId.value === item[idKey.value]) reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

watch(type, () => {
  reset()
  search.value = ''
  statusFilter.value = 'todos'
  page.value = 1
  sort.value = 'id'
  direction.value = 'asc'
  providerOptions.value = []
  load()
}, { immediate: true })
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Catálogos</p>
      <h1 class="page-title">
        <Building2 v-if="type === 'proveedores'" :size="26" :stroke-width="2" />
        <Tags v-else-if="type === 'categorias'" :size="26" :stroke-width="2" />
        <Layers v-else :size="26" :stroke-width="2" />
        {{ titles[type] }}
      </h1>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section :class="{ 'two-column': auth.can('CATALOGOS.GESTIONAR') }">
    <form v-if="auth.can('CATALOGOS.GESTIONAR')" class="panel" @submit.prevent="submit">
      <div class="panel-heading"><Save :size="18" /><h2>{{ editingId ? 'Modificar registro' : 'Nuevo registro' }}</h2></div>
      <div class="form-grid">
        <label v-for="[name, label, inputType, kind] in fields" :key="name">
          {{ label }}
          <input
            v-model="form[name]"
            :type="inputType || 'text'"
            :required="!['correo', 'telefono', 'contacto'].includes(name)"
            :maxlength="kind && masks[kind].maxlength ? masks[kind].maxlength : undefined"
            :pattern="kind ? masks[kind].pattern : undefined"
            :title="kind ? masks[kind].title : undefined"
            @input="kind && (form[name] = masks[kind].clean($event.target.value))"
          />
        </label>
      </div>
      <div class="actions">
        <button class="button primary"><Save :size="16" /> {{ editingId ? 'Guardar cambios' : 'Guardar' }}</button>
        <button v-if="editingId" class="button secondary" type="button" @click="reset"><X :size="16" /> Cancelar</button>
      </div>
    </form>
    <section class="panel">
      <div class="panel-heading"><Layers :size="18" /><h2>Registros</h2></div>
      <form class="filter-bar" @submit.prevent="searchRecords">
        <label>Buscar
          <input v-model="search" :list="type === 'proveedores' ? 'provider-options' : undefined" type="search" placeholder="ID o nombre" />
          <datalist v-if="type === 'proveedores'" id="provider-options">
            <option
              v-for="provider in providerOptions"
              :key="provider.PRV_Identificacion_Fiscal"
              :value="provider.PRV_Razon_Social"
              :label="provider.PRV_Identificacion_Fiscal"
            />
          </datalist>
        </label>
        <label>Estado
          <select v-model="statusFilter" @change="searchRecords">
            <option value="todos">Todos</option>
            <option value="activos">Activos</option>
            <option value="inactivos">Inactivos</option>
          </select>
        </label>
        <button class="button primary" type="submit"><Search :size="16" /> Consultar</button>
      </form>
      <p class="muted">Registros encontrados: {{ pagination.total }}</p>
      <div class="table-wrap">
        <table>
          <thead>
            <tr v-if="type === 'proveedores'">
              <SortHeader label="ID" column="id" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Identificación fiscal" column="identificacion" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Razón social" column="razon_social" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Contacto" column="contacto" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Correo" column="correo" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Teléfono" column="telefono" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Estado" column="estado" :sort="sort" :direction="direction" @sort="toggleSort" />
              <th>Acción</th>
            </tr>
            <tr v-else>
              <SortHeader label="ID" column="id" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Nombre" column="nombre" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Estado" column="estado" :sort="sort" :direction="direction" @sort="toggleSort" />
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item[idKey]">
              <template v-if="type === 'proveedores'">
                <td class="mono">{{ item.PRV_Proveedor }}</td>
                <td class="mono">{{ item.PRV_Identificacion_Fiscal }}</td>
                <td>{{ item.PRV_Razon_Social }}</td>
                <td>{{ item.PRV_Contacto || '—' }}</td>
                <td>{{ item.PRV_Correo || '—' }}</td>
                <td>{{ item.PRV_Telefono || '—' }}</td>
              </template>
              <template v-else>
                <td class="mono">{{ item.PRV_Identificacion_Fiscal || item.CAT_Categoria || item.MAR_Marca }}</td>
                <td>{{ item.PRV_Razon_Social || item.CAT_Nombre || item.MAR_Nombre }}</td>
              </template>
              <td><span class="status" :class="{ off: !isActive(item) }">{{ isActive(item) ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="actions">
                <button v-if="auth.can('CATALOGOS.GESTIONAR')" class="link" type="button" @click="fill(item)"><Pencil :size="14" /> Editar</button>
                <button v-if="isActive(item) && auth.can(['CATALOGOS.INACTIVAR', 'CATALOGOS.GESTIONAR'])" class="link danger" type="button" @click="deactivate(item)">
                  <Ban :size="14" /> Inactivar
                </button>
                <button v-else-if="!isActive(item) && auth.can(['CATALOGOS.INACTIVAR', 'CATALOGOS.GESTIONAR'])" class="link" type="button" @click="activate(item)">
                  <CircleCheck :size="14" /> Activar
                </button>
              </td>
            </tr>
            <tr v-if="items.length === 0">
              <td :colspan="type === 'proveedores' ? 8 : 4">No hay registros que coincidan con la búsqueda.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="pagination.last_page > 1" class="actions pagination">
        <button class="button secondary" type="button" :disabled="page <= 1" @click="changePage(page - 1)">Anterior</button>
        <span class="muted">Página {{ pagination.current_page }} de {{ pagination.last_page }}</span>
        <button class="button secondary" type="button" :disabled="page >= pagination.last_page" @click="changePage(page + 1)">Siguiente</button>
      </div>
    </section>
  </section>
</template>
