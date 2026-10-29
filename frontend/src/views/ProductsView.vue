<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { askConfirm } from '../lib/dialog'
import { formatCurrency } from '../lib/currency'
import { fields } from '../lib/fields'
import { useAuthStore } from '../stores/auth'
import { Ban, CircleAlert, CircleCheck, Package, Pencil, Plus, RefreshCw, Save, Search, X } from '@lucide/vue'
import SortHeader from '../components/SortHeader.vue'

const auth = useAuthStore()
const items = ref([])
const categories = ref([])
const brands = ref([])
const search = ref('')
const categoryId = ref('')
const brandId = ref('')
const statusFilter = ref('todos')
const stockFilter = ref('todos')
const sort = ref('id')
const direction = ref('asc')
const message = ref('')
const error = ref('')
const editingId = ref(null)
const formOpen = ref(false)
const photo = ref(null)
const photoPreview = ref('')
const photoRemoved = ref(false)
const photoValidationError = ref('')
const photoInput = ref(null)
const form = reactive(emptyForm())
let photoObjectUrl = null

function emptyForm() {
  return {
    sku: '',
    nombre: '',
    descripcion: '',
    costo: 0,
    precio: 0,
    existencia_minima: 0,
    existencia: 0,
    categoria_id: '',
    marca_id: '',
  }
}

function reset() {
  if (photoObjectUrl) URL.revokeObjectURL(photoObjectUrl)
  photoObjectUrl = null
  editingId.value = null
  formOpen.value = false
  photo.value = null
  photoPreview.value = ''
  photoRemoved.value = false
  photoValidationError.value = ''
  if (photoInput.value) photoInput.value.value = ''
  Object.assign(form, emptyForm())
}

function revealForm() {
  formOpen.value = true
}

function openCreate() {
  reset()
  revealForm()
}

function clearPhoto() {
  if (photoObjectUrl) URL.revokeObjectURL(photoObjectUrl)
  photoObjectUrl = null
  photo.value = null
  photoPreview.value = ''
  photoRemoved.value = true
  photoValidationError.value = ''
  if (photoInput.value) photoInput.value.value = ''
}

function selectPhoto(event) {
  const selectedPhoto = event.target.files?.[0] || null
  if (photoObjectUrl) URL.revokeObjectURL(photoObjectUrl)
  photoObjectUrl = null
  photoValidationError.value = ''

  if (selectedPhoto && selectedPhoto.size > 2 * 1024 * 1024) {
    photoValidationError.value = 'La foto no puede superar los 2 MB.'
  } else if (selectedPhoto && !['image/jpeg', 'image/png', 'image/webp'].includes(selectedPhoto.type)) {
    photoValidationError.value = 'La foto debe estar en formato JPG, PNG o WebP.'
  }

  if (photoValidationError.value) {
    photo.value = null
    event.target.value = ''
    photoPreview.value = photoRemoved.value
      ? ''
      : (editingId.value
        ? items.value.find((item) => item.PRO_Producto === editingId.value)?.PRO_Foto_URL || ''
        : '')
    error.value = photoValidationError.value
    photoValidationError.value = ''
    return
  }

  photoRemoved.value = false
  photoObjectUrl = selectedPhoto ? URL.createObjectURL(selectedPhoto) : null
  photo.value = selectedPhoto
  photoPreview.value = photoObjectUrl || (editingId.value ? items.value.find((item) => item.PRO_Producto === editingId.value)?.PRO_Foto_URL || '' : '')
  error.value = ''
}

async function load() {
  error.value = ''
  try {
    const params = {
      buscar: search.value,
      por_pagina: 100,
      orden: sort.value,
      direccion: direction.value,
      estado: statusFilter.value,
      stock: stockFilter.value,
    }
    if (categoryId.value) params.categoria_id = categoryId.value
    if (brandId.value) params.marca_id = brandId.value
    if (!categories.value.length || !brands.value.length) params.con_opciones = 1
    const { data } = await api.get('/catalogos/productos', { params })
    items.value = data.data
    if (data.categorias) categories.value = data.categorias
    if (data.marcas) brands.value = data.marcas
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  message.value = ''
  error.value = ''
  try {
    const payload = new FormData()
    Object.entries(form).forEach(([key, value]) => payload.append(key, String(value)))
    if (!auth.can('INVENTARIO.AJUSTAR')) payload.delete('existencia')
    if (photo.value) payload.append('foto', photo.value)
    if (photoRemoved.value && !photo.value) payload.append('quitar_foto', '1')

    if (editingId.value) {
      payload.append('_method', 'PUT')
      await api.post(`/catalogos/productos/${editingId.value}`, payload)
      message.value = 'Producto actualizado correctamente.'
    } else {
      payload.delete('existencia')
      await api.post('/catalogos/productos', payload)
      message.value = 'Producto creado correctamente.'
    }
    reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function edit(item) {
  if (photoObjectUrl) URL.revokeObjectURL(photoObjectUrl)
  photoObjectUrl = null
  if (photoInput.value) photoInput.value.value = ''
  photoValidationError.value = ''
  editingId.value = item.PRO_Producto
  Object.assign(form, {
    sku: item.PRO_SKU,
    nombre: item.PRO_Nombre,
    descripcion: item.PRO_Descripcion || '',
    costo: item.PRO_Costo,
    precio: item.PRO_Precio,
    existencia_minima: item.PRO_Existencia_Minima,
    existencia: Number(item.INV_Existencia ?? 0),
    categoria_id: item.CAT_Categoria,
    marca_id: item.MAR_Marca,
  })
  photo.value = null
  photoRemoved.value = false
  photoPreview.value = item.PRO_Foto_URL || ''
  revealForm()
}

async function deactivate(item) {
  await changeState(item, false)
}

async function activate(item) {
  await changeState(item, true)
}

async function changeState(item, active) {
  const accepted = await askConfirm({
    title: active ? 'Activar producto' : 'Inactivar producto',
    message: active
      ? `¿Desea volver a activar ${item.PRO_Nombre}?`
      : `¿Desea inactivar ${item.PRO_Nombre}? La existencia se conserva.`,
    confirmText: active ? 'Activar' : 'Inactivar',
    tone: active ? 'warning' : 'danger',
  })
  if (!accepted) return
  try {
    if (active) {
      await api.patch(`/catalogos/productos/${item.PRO_Producto}/estado`)
      message.value = 'Producto activado.'
    } else {
      await api.delete(`/catalogos/productos/${item.PRO_Producto}`)
      message.value = 'Producto inactivado. La existencia no se elimina.'
    }
    if (editingId.value === item.PRO_Producto) reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

onMounted(load)

function toggleSort(column) {
  if (sort.value === column) {
    direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = column
    direction.value = 'asc'
  }
  load()
}
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Catálogo</p>
      <h1 class="page-title"><Package :size="26" :stroke-width="2" /> Productos</h1>
    </div>
    <div class="header-actions">
      <button v-if="auth.can('CATALOGOS.GESTIONAR')" class="button primary" type="button" @click="openCreate"><Plus :size="16" /> Nuevo producto</button>
      <button class="button secondary" type="button" @click="load"><RefreshCw :size="16" /> Actualizar</button>
    </div>
  </header>
  <form class="filter-bar" @submit.prevent="load">
    <label>Buscar
      <input v-model="search" type="search" placeholder="ID, SKU o nombre" />
    </label>
    <label>Categoría
      <select v-model="categoryId" @change="load">
        <option value="">Todas</option>
        <option v-for="item in categories" :key="item.CAT_Categoria" :value="item.CAT_Categoria">{{ item.CAT_Nombre }}</option>
      </select>
    </label>
    <label>Marca
      <select v-model="brandId" @change="load">
        <option value="">Todas</option>
        <option v-for="item in brands" :key="item.MAR_Marca" :value="item.MAR_Marca">{{ item.MAR_Nombre }}</option>
      </select>
    </label>
    <label>Estado
      <select v-model="statusFilter" @change="load">
        <option value="todos">Todos</option>
        <option value="activos">Activos</option>
        <option value="inactivos">Inactivos</option>
      </select>
    </label>
    <label>Stock
      <select v-model="stockFilter" @change="load">
        <option value="todos">Todo</option>
        <option value="bajo">Bajo mínimo</option>
      </select>
    </label>
    <button class="button primary" type="submit"><Search :size="16" /> Consultar</button>
  </form>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
    <div v-if="auth.can('CATALOGOS.GESTIONAR')" class="form-slot" :class="{ open: formOpen }">
    <div class="form-slot-inner">
    <form class="panel" :inert="!formOpen" @submit.prevent="submit">
      <div class="panel-heading">
        <Package :size="18" />
        <h2>{{ editingId ? 'Modificar producto' : 'Nuevo producto' }}</h2>
      </div>
      <p class="muted">{{ editingId ? `ID asignado: ${editingId}` : 'El sistema asignará un ID único al guardar.' }}</p>
      <div class="form-grid">
        <label>SKU<input v-model="form.sku" required maxlength="40" :pattern="fields.codigo.pattern" :title="fields.codigo.title" @input="form.sku = fields.codigo.clean($event.target.value)" /></label>
        <label>Nombre<input v-model="form.nombre" required maxlength="120" :pattern="fields.catalogo.pattern" :title="fields.catalogo.title" @input="form.nombre = fields.catalogo.clean($event.target.value)" /></label>
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
        <label>Costo (Q)<input v-model="form.costo" type="number" min="0" step="0.01" required /></label>
        <label>Precio (Q)<input v-model="form.precio" type="number" min="0" step="0.01" required /></label>
        <label>Existencia mínima<input v-model="form.existencia_minima" type="number" min="0" step="1" required /></label>
        <label v-if="editingId && auth.can('INVENTARIO.AJUSTAR')">Existencia actual<input v-model.number="form.existencia" type="number" min="0" step="1" required /></label>
        <label>Descripción<input v-model="form.descripcion" maxlength="500" :pattern="fields.empresa.pattern" :title="fields.empresa.title" @input="form.descripcion = fields.empresa.clean($event.target.value)" /></label>
        <label>Foto (opcional)<input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" @change="selectPhoto" /><small class="muted">JPG, PNG o WebP; máximo 2 MB.</small></label>
        <div v-if="photoPreview" class="photo-preview">
          <img :src="photoPreview" alt="Vista previa de la foto del producto" />
          <button class="link danger" type="button" @click="clearPhoto">Quitar foto</button>
        </div>
      </div>
      <p v-if="editingId && auth.can('INVENTARIO.AJUSTAR')" class="muted">Si cambia la existencia, el sistema registra un ajuste de inventario.</p>
      <p v-else class="muted">La existencia inicial es 0. Se actualiza al recibir una compra o con un ajuste de inventario.</p>
      <div class="actions">
        <button class="button primary" type="submit"><Save :size="16" /> {{ editingId ? 'Guardar cambios' : 'Crear producto' }}</button>
        <button class="button secondary" type="button" @click="reset"><X :size="16" /> Cancelar</button>
      </div>
    </form>
    </div>
    </div>
    <section class="panel">
      <div class="panel-heading"><Package :size="18" /><h2>Productos registrados</h2></div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <SortHeader label="ID" column="id" :sort="sort" :direction="direction" @sort="toggleSort" />
              <th>Foto</th>
              <SortHeader label="SKU" column="sku" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Nombre" column="nombre" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Clasificación" column="clasificacion" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Precio" column="precio" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Stock" column="stock" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Estado" column="estado" :sort="sort" :direction="direction" @sort="toggleSort" />
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in items" :key="item.PRO_Producto">
              <td class="mono">{{ item.PRO_Producto }}</td>
              <td>
                <img v-if="item.PRO_Foto_URL" class="product-thumbnail" :src="item.PRO_Foto_URL" :alt="`Foto de ${item.PRO_Nombre}`" loading="lazy" />
                <span v-else class="muted">Sin foto</span>
              </td>
              <td class="mono">{{ item.PRO_SKU }}</td>
              <td>{{ item.PRO_Nombre }}</td>
              <td>{{ item.CAT_Nombre }} · {{ item.MAR_Nombre }}</td>
              <td class="mono">{{ formatCurrency(item.PRO_Precio) }}</td>
              <td>{{ item.INV_Existencia }} / mín. {{ item.PRO_Existencia_Minima }}</td>
              <td><span class="status" :class="{ off: !item.PRO_Estado }">{{ item.PRO_Estado ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="actions">
                <button v-if="auth.can('CATALOGOS.GESTIONAR')" class="link" type="button" @click="edit(item)"><Pencil :size="14" /> Editar</button>
                <button v-if="item.PRO_Estado && auth.can(['CATALOGOS.INACTIVAR', 'CATALOGOS.GESTIONAR'])" class="link danger" type="button" @click="deactivate(item)">
                  <Ban :size="14" /> Inactivar
                </button>
                <button v-else-if="!item.PRO_Estado && auth.can(['CATALOGOS.INACTIVAR', 'CATALOGOS.GESTIONAR'])" class="link" type="button" @click="activate(item)">
                  <CircleCheck :size="14" /> Activar
                </button>
              </td>
            </tr>
            <tr v-if="items.length === 0">
              <td colspan="9">No hay productos que coincidan con el filtro.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
</template>
