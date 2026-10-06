<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { Ban, CircleAlert, CircleCheck, Package, Pencil, Save, Search, X } from '@lucide/vue'

const auth = useAuthStore()
const items = ref([])
const categories = ref([])
const brands = ref([])
const search = ref('')
const message = ref('')
const error = ref('')
const editingId = ref(null)
const form = reactive(emptyForm())

function emptyForm() {
  return {
    sku: '',
    nombre: '',
    descripcion: '',
    costo: 0,
    precio: 0,
    existencia_minima: 0,
    categoria_id: '',
    marca_id: '',
  }
}

function reset() {
  editingId.value = null
  Object.assign(form, emptyForm())
}

async function load() {
  error.value = ''
  try {
    const requests = [
      api.get('/catalogos/productos', { params: { buscar: search.value, por_pagina: 50 } }),
    ]
    if (!categories.value.length) {
      requests.push(
        api.get('/catalogos/categorias?por_pagina=100'),
        api.get('/catalogos/marcas?por_pagina=100'),
      )
    }
    const [productResponse, categoryResponse, brandResponse] = await Promise.all(requests)
    items.value = productResponse.data.data
    if (categoryResponse) categories.value = categoryResponse.data.data
    if (brandResponse) brands.value = brandResponse.data.data
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  message.value = ''
  error.value = ''
  try {
    if (editingId.value) {
      await api.put(`/catalogos/productos/${editingId.value}`, form)
      message.value = 'Producto actualizado correctamente.'
    } else {
      await api.post('/catalogos/productos', form)
      message.value = 'Producto creado correctamente.'
    }
    reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function edit(item) {
  editingId.value = item.PRO_Producto
  Object.assign(form, {
    sku: item.PRO_SKU,
    nombre: item.PRO_Nombre,
    descripcion: item.PRO_Descripcion || '',
    costo: item.PRO_Costo,
    precio: item.PRO_Precio,
    existencia_minima: item.PRO_Existencia_Minima,
    categoria_id: item.CAT_Categoria,
    marca_id: item.MAR_Marca,
  })
}

async function deactivate(item) {
  if (!confirm(`¿Desea inactivar ${item.PRO_Nombre}?`)) return
  try {
    await api.delete(`/catalogos/productos/${item.PRO_Producto}`)
    message.value = 'Producto inactivado. La existencia no se elimina.'
    if (editingId.value === item.PRO_Producto) reset()
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
      <h1 class="page-title"><Package :size="26" :stroke-width="2" /> Productos</h1>
    </div>
  </header>
  <form class="filter-bar" @submit.prevent="load">
    <label>Buscar
      <input v-model="search" type="search" placeholder="SKU o nombre" />
    </label>
    <button class="button primary" type="submit"><Search :size="16" /> Consultar</button>
  </form>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section class="two-column">
    <form v-if="auth.can('CATALOGOS.GESTIONAR')" class="panel" @submit.prevent="submit">
      <div class="panel-heading">
        <Package :size="18" />
        <h2>{{ editingId ? 'Modificar producto' : 'Nuevo producto' }}</h2>
      </div>
      <div class="form-grid">
        <label>SKU<input v-model="form.sku" required maxlength="40" /></label>
        <label>Nombre<input v-model="form.nombre" required maxlength="120" /></label>
        <label>Categoría
          <select v-model="form.categoria_id" required>
            <option value="">Seleccione</option>
            <option v-for="item in categories" :key="item.CAT_Categoria" :value="item.CAT_Categoria">{{ item.CAT_Nombre }}</option>
          </select>
        </label>
        <label>Marca
          <select v-model="form.marca_id" required>
            <option value="">Seleccione</option>
            <option v-for="item in brands" :key="item.MAR_Marca" :value="item.MAR_Marca">{{ item.MAR_Nombre }}</option>
          </select>
        </label>
        <label>Costo<input v-model="form.costo" type="number" min="0" step="0.01" required /></label>
        <label>Precio<input v-model="form.precio" type="number" min="0" step="0.01" required /></label>
        <label>Existencia mínima<input v-model="form.existencia_minima" type="number" min="0" step="1" required /></label>
        <label>Descripción<input v-model="form.descripcion" /></label>
      </div>
      <p class="muted">La existencia actual no se edita aquí. Solo cambia con compras, ventas o ajustes.</p>
      <div class="actions">
        <button class="button primary" type="submit"><Save :size="16" /> {{ editingId ? 'Guardar cambios' : 'Crear producto' }}</button>
        <button v-if="editingId" class="button secondary" type="button" @click="reset"><X :size="16" /> Cancelar</button>
      </div>
    </form>
    <section class="panel">
      <div class="panel-heading"><Package :size="18" /><h2>Productos registrados</h2></div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>SKU</th>
              <th>Nombre</th>
              <th>Clasificación</th>
              <th>Precio</th>
              <th>Stock</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.PRO_Producto">
              <td class="mono">{{ item.PRO_SKU }}</td>
              <td>{{ item.PRO_Nombre }}</td>
              <td>{{ item.CAT_Nombre }} · {{ item.MAR_Nombre }}</td>
              <td class="mono">${{ Number(item.PRO_Precio).toFixed(2) }}</td>
              <td>{{ item.INV_Existencia }} / mín. {{ item.PRO_Existencia_Minima }}</td>
              <td><span class="status" :class="{ off: !item.PRO_Estado }">{{ item.PRO_Estado ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="actions">
                <button class="link" type="button" @click="edit(item)"><Pencil :size="14" /> Editar</button>
                <button v-if="item.PRO_Estado && auth.can('CATALOGOS.INACTIVAR')" class="link danger" type="button" @click="deactivate(item)">
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
