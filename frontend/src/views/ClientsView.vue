<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { Ban, CircleAlert, CircleCheck, Pencil, Save, Search, UserPlus, Users, X } from '@lucide/vue'

const auth = useAuthStore()
const items = ref([])
const search = ref('')
const message = ref('')
const error = ref('')
const editingId = ref(null)
const form = reactive(emptyForm())

function emptyForm() {
  return {
    identificacion: '',
    primer_nombre: '',
    segundo_nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    correo: '',
    telefono: '',
  }
}

function reset() {
  editingId.value = null
  Object.assign(form, emptyForm())
}

function fullName(item) {
  return [item.CLI_Primer_Nombre, item.CLI_Segundo_Nombre, item.CLI_Primer_Apellido, item.CLI_Segundo_Apellido]
    .filter(Boolean)
    .join(' ')
}

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/catalogos/clientes', {
      params: { buscar: search.value, por_pagina: 50 },
    })
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
      await api.put(`/catalogos/clientes/${editingId.value}`, form)
      message.value = 'Cliente actualizado correctamente.'
    } else {
      await api.post('/catalogos/clientes', form)
      message.value = 'Cliente creado correctamente.'
    }
    reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function edit(item) {
  editingId.value = item.CLI_Cliente
  Object.assign(form, {
    identificacion: item.CLI_Identificacion,
    primer_nombre: item.CLI_Primer_Nombre,
    segundo_nombre: item.CLI_Segundo_Nombre || '',
    primer_apellido: item.CLI_Primer_Apellido,
    segundo_apellido: item.CLI_Segundo_Apellido || '',
    correo: item.CLI_Correo || '',
    telefono: item.CLI_Telefono || '',
  })
}

async function deactivate(item) {
  if (!confirm(`¿Desea inactivar a ${fullName(item)}?`)) return
  try {
    await api.delete(`/catalogos/clientes/${item.CLI_Cliente}`)
    message.value = 'Cliente inactivado.'
    if (editingId.value === item.CLI_Cliente) reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Módulo obligatorio</p>
      <h1 class="page-title"><Users :size="26" :stroke-width="2" /> Clientes</h1>
    </div>
  </header>
  <form class="filter-bar" @submit.prevent="load">
    <label>Buscar
      <input v-model="search" type="search" placeholder="Identificación o nombre" />
    </label>
    <button class="button primary" type="submit"><Search :size="16" /> Consultar</button>
  </form>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section class="two-column">
    <form v-if="auth.can('CLIENTES.GESTIONAR')" class="panel" @submit.prevent="submit">
      <div class="panel-heading">
        <UserPlus :size="18" />
        <h2>{{ editingId ? 'Modificar cliente' : 'Nuevo cliente' }}</h2>
      </div>
      <div class="form-grid">
        <label>Identificación<input v-model="form.identificacion" required maxlength="30" /></label>
        <label>Teléfono<input v-model="form.telefono" maxlength="30" /></label>
        <label>Primer nombre<input v-model="form.primer_nombre" required maxlength="60" /></label>
        <label>Segundo nombre<input v-model="form.segundo_nombre" maxlength="60" /></label>
        <label>Primer apellido<input v-model="form.primer_apellido" required maxlength="60" /></label>
        <label>Segundo apellido<input v-model="form.segundo_apellido" maxlength="60" /></label>
        <label>Correo<input v-model="form.correo" type="email" maxlength="120" /></label>
      </div>
      <div class="actions">
        <button class="button primary" type="submit"><Save :size="16" /> {{ editingId ? 'Guardar cambios' : 'Crear cliente' }}</button>
        <button v-if="editingId" class="button secondary" type="button" @click="reset"><X :size="16" /> Cancelar</button>
      </div>
    </form>
    <section class="panel">
      <div class="panel-heading"><Users :size="18" /><h2>Clientes registrados</h2></div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Identificación</th>
              <th>Nombre</th>
              <th>Contacto</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.CLI_Cliente">
              <td class="mono">{{ item.CLI_Identificacion }}</td>
              <td>{{ fullName(item) }}</td>
              <td>{{ item.CLI_Correo || item.CLI_Telefono || '—' }}</td>
              <td><span class="status" :class="{ off: !item.CLI_Estado }">{{ item.CLI_Estado ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="actions">
                <button class="link" type="button" @click="edit(item)"><Pencil :size="14" /> Editar</button>
                <button v-if="item.CLI_Estado && auth.can('CLIENTES.GESTIONAR')" class="link danger" type="button" @click="deactivate(item)">
                  <Ban :size="14" /> Inactivar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>
