<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import { askConfirm } from '../lib/dialog'
import { fields as masks } from '../lib/fields'
import { useAuthStore } from '../stores/auth'
import { Ban, Building2, CircleAlert, CircleCheck, Layers, Pencil, Save, Tags, X } from '@lucide/vue'

const route = useRoute()
const auth = useAuthStore()
const items = ref([])
const message = ref('')
const error = ref('')
const editingId = ref(null)
const form = reactive({})

const definitions = {
  proveedores: [
    ['identificacion_fiscal', 'Identificación fiscal', 'text', 'codigo'],
    ['razon_social', 'Razón social', 'text', 'empresa'],
    ['contacto', 'Contacto', 'text', 'persona'],
    ['correo', 'Correo', 'email'],
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
    const { data } = await api.get(`/catalogos/${type.value}`)
    items.value = data.data
  } catch (requestError) {
    error.value = requestError.userMessage
  }
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

watch(type, () => { reset(); load() }, { immediate: true })
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
  <section class="two-column">
    <form class="panel" @submit.prevent="submit">
      <div class="panel-heading"><Save :size="18" /><h2>{{ editingId ? 'Modificar registro' : 'Nuevo registro' }}</h2></div>
      <div class="form-grid">
        <label v-for="[name, label, inputType, kind] in fields" :key="name">
          {{ label }}
          <input
            v-model="form[name]"
            :type="inputType || 'text'"
            :required="!['correo', 'telefono', 'contacto'].includes(name)"
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
      <div class="table-wrap">
        <table>
          <thead><tr><th>Identificador</th><th>Nombre</th><th>Estado</th><th>Acción</th></tr></thead>
          <tbody>
            <tr v-for="item in items" :key="item[idKey]">
              <td class="mono">{{ item.PRV_Identificacion_Fiscal || item.CAT_Categoria || item.MAR_Marca }}</td>
              <td>{{ item.PRV_Razon_Social || item.CAT_Nombre || item.MAR_Nombre }}</td>
              <td><span class="status" :class="{ off: !isActive(item) }">{{ isActive(item) ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="actions">
                <button class="link" type="button" @click="fill(item)"><Pencil :size="14" /> Editar</button>
                <button v-if="isActive(item) && auth.can(['CATALOGOS.INACTIVAR', 'CATALOGOS.GESTIONAR'])" class="link danger" type="button" @click="deactivate(item)">
                  <Ban :size="14" /> Inactivar
                </button>
                <button v-else-if="!isActive(item) && auth.can(['CATALOGOS.INACTIVAR', 'CATALOGOS.GESTIONAR'])" class="link" type="button" @click="activate(item)">
                  <CircleCheck :size="14" /> Activar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>
