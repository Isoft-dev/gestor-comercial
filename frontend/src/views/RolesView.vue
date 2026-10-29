<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { permisoEnEntrega } from '../config/entrega'
import { fields } from '../lib/fields'
import { useAuthStore } from '../stores/auth'
import { CircleAlert, CircleCheck, KeyRound, Pencil, Plus, RefreshCw, Save, Shield, X } from '@lucide/vue'
import SortHeader from '../components/SortHeader.vue'

const router = useRouter()

const auth = useAuthStore()

const roles = ref([])
const permissions = ref([])
const error = ref('')
const message = ref('')
const editingId = ref(null)
const formOpen = ref(false)
const formPanel = ref(null)
const search = ref('')
const statusFilter = ref('todos')
const sort = ref('id')
const direction = ref('asc')
const form = reactive({
  nombre: '',
  permisos: [],
})

const groupedPermissions = computed(() => {
  return permissions.value
    .filter((permission) => permisoEnEntrega(permission.PER_Codigo))
    .reduce((groups, permission) => {
      groups[permission.PER_Modulo] ??= []
      groups[permission.PER_Modulo].push(permission)
      return groups
    }, {})
})

const visibleRoles = computed(() => {
  const term = search.value.trim().toLowerCase()
  const rows = roles.value.filter((role) => {
    if (statusFilter.value === 'activos' && !role.ROL_Estado) return false
    if (statusFilter.value === 'inactivos' && role.ROL_Estado) return false
    if (!term) return true
    return String(role.ROL_Rol).includes(term) || role.ROL_Nombre.toLowerCase().includes(term)
  })
  const factor = direction.value === 'asc' ? 1 : -1
  const value = (role) => {
    if (sort.value === 'nombre') return role.ROL_Nombre.toLowerCase()
    if (sort.value === 'permisos') return role.permisos.length
    if (sort.value === 'estado') return role.ROL_Estado ? 1 : 0
    return Number(role.ROL_Rol)
  }
  return [...rows].sort((left, right) => {
    const a = value(left)
    const b = value(right)
    if (a < b) return -1 * factor
    if (a > b) return 1 * factor
    return left.ROL_Rol - right.ROL_Rol
  })
})

function toggleSort(column) {
  if (sort.value === column) direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  else {
    sort.value = column
    direction.value = 'asc'
  }
}

function reset() {
  editingId.value = null
  formOpen.value = false
  form.nombre = ''
  form.permisos = []
}

function revealForm() {
  formOpen.value = true
  nextTick(() => formPanel.value?.scrollIntoView({ block: 'start' }))
}

function openCreate() {
  reset()
  revealForm()
}

function isAdmin(role) {
  return role.ROL_Nombre === 'ADMINISTRADOR'
}

function isAdminForm() {
  return Boolean(editingId.value && form.nombre === 'ADMINISTRADOR')
}

function isLockedPermission(permission) {
  return isAdminForm() && permission.PER_Codigo.startsWith('USUARIOS.')
}

function permissionSelected(id) {
  return form.permisos.some((item) => Number(item) === Number(id))
}

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/roles', { params: { con_permisos: 1 } })
    roles.value = data.roles
    permissions.value = data.permisos
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  message.value = ''
  error.value = ''
  try {
    if (editingId.value) {
      await api.put(`/roles/${editingId.value}`, form)
      message.value = 'Rol actualizado. El menú y las pantallas se actualizan ahora.'
    } else {
      await api.post('/roles', form)
      message.value = 'Rol creado.'
    }
    reset()
    await load()
    await applyOwnAccess()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function edit(role) {
  editingId.value = role.ROL_Rol
  form.nombre = role.ROL_Nombre
  form.permisos = permissions.value
    .filter((permission) => role.permisos.includes(permission.PER_Codigo))
    .map((permission) => Number(permission.PER_Permiso))
  revealForm()
}

function togglePermission(permission, checked) {
  if (isLockedPermission(permission)) return
  const id = Number(permission.PER_Permiso)
  if (checked) {
    if (!permissionSelected(id)) form.permisos.push(id)
    if (permission.PER_Codigo.endsWith('.GESTIONAR')) {
      const consult = permissions.value.find((item) => item.PER_Codigo === permission.PER_Codigo.replace('.GESTIONAR', '.CONSULTAR'))
      if (consult && !permissionSelected(consult.PER_Permiso)) {
        form.permisos.push(Number(consult.PER_Permiso))
      }
    }
    return
  }

  form.permisos = form.permisos.filter((item) => Number(item) !== id)
  if (permission.PER_Codigo.endsWith('.CONSULTAR')) {
    const manage = permissions.value.find((item) => item.PER_Codigo === permission.PER_Codigo.replace('.CONSULTAR', '.GESTIONAR'))
    if (manage) form.permisos = form.permisos.filter((item) => Number(item) !== Number(manage.PER_Permiso))
  }
}

async function applyOwnAccess() {
  try {
    await auth.refreshProfile()
  } catch {
    return
  }
  if (!auth.can('USUARIOS.CONSULTAR')) {
    await router.push(auth.homePath())
  }
}

async function toggle(role) {
  error.value = ''
  try {
    await api.patch(`/roles/${role.ROL_Rol}/estado`)
    if (editingId.value === role.ROL_Rol) reset()
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
      <p class="eyebrow">Administración</p>
      <h1 class="page-title"><Shield :size="26" :stroke-width="2" /> Roles y permisos</h1>
    </div>
    <div class="header-actions">
      <button v-if="auth.can('USUARIOS.GESTIONAR')" class="button primary" type="button" @click="openCreate"><Plus :size="16" /> Nuevo rol</button>
      <button class="button secondary" type="button" @click="load"><RefreshCw :size="16" /> Actualizar</button>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
    <form v-if="auth.can('USUARIOS.GESTIONAR') && formOpen" ref="formPanel" class="panel" @submit.prevent="submit">
      <div class="panel-heading">
        <KeyRound :size="18" />
        <h2>{{ editingId ? 'Modificar rol' : 'Nuevo rol' }}</h2>
      </div>
      <label>Nombre del rol
        <input
          v-model="form.nombre"
          required
          maxlength="30"
          :pattern="fields.persona.pattern"
          :title="fields.persona.title"
          :disabled="editingId && form.nombre === 'ADMINISTRADOR'"
          @input="form.nombre = fields.persona.clean($event.target.value)"
        />
      </label>
      <div>
        <p class="eyebrow">Permisos</p>
        <p v-if="isAdminForm()" class="muted">El rol Administrador debe conservar los permisos de usuarios para no perder esta pantalla. Para probar que alguien no vea Usuarios, edite otro rol.</p>
        <div v-for="(items, module) in groupedPermissions" :key="module" class="permission-group">
          <strong>{{ module }}</strong>
          <label v-for="permission in items" :key="permission.PER_Permiso" class="check-row">
            <input
              type="checkbox"
              :checked="permissionSelected(permission.PER_Permiso)"
              :disabled="isLockedPermission(permission)"
              @change="togglePermission(permission, $event.target.checked)"
            />
            <span>
              {{ permission.PER_Nombre }}
              <small>{{ permission.PER_Codigo }}</small>
            </span>
          </label>
        </div>
      </div>
      <div class="actions">
        <button class="button primary" type="submit"><Save :size="16" /> {{ editingId ? 'Guardar cambios' : 'Crear rol' }}</button>
        <button class="button secondary" type="button" @click="reset"><X :size="16" /> Cancelar</button>
      </div>
    </form>
    <section class="panel">
      <div class="panel-heading"><Shield :size="18" /><h2>Roles del sistema</h2></div>
      <form class="filter-bar" @submit.prevent>
        <label>Buscar
          <input v-model="search" type="search" placeholder="ID o nombre del rol" />
        </label>
        <label>Estado
          <select v-model="statusFilter">
            <option value="todos">Todos</option>
            <option value="activos">Activos</option>
            <option value="inactivos">Inactivos</option>
          </select>
        </label>
      </form>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <SortHeader label="ID" column="id" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Rol" column="nombre" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Permisos" column="permisos" :sort="sort" :direction="direction" @sort="toggleSort" />
              <SortHeader label="Estado" column="estado" :sort="sort" :direction="direction" @sort="toggleSort" />
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="role in visibleRoles" :key="role.ROL_Rol">
              <td class="mono">{{ role.ROL_Rol }}</td>
              <td>{{ role.ROL_Nombre }}</td>
              <td>{{ role.permisos.length }} asignados</td>
              <td>
                <label v-if="auth.can('USUARIOS.GESTIONAR')" class="switch" :class="{ disabled: isAdmin(role) }">
                  <input type="checkbox" :checked="role.ROL_Estado" :disabled="isAdmin(role)" @change="toggle(role)" />
                  <span class="switch-ui"></span>
                  <span class="switch-text">{{ role.ROL_Estado ? 'Activo' : 'Inactivo' }}</span>
                </label>
                <span v-else class="status" :class="{ off: !role.ROL_Estado }">{{ role.ROL_Estado ? 'Activo' : 'Inactivo' }}</span>
              </td>
              <td>
                <button v-if="auth.can('USUARIOS.GESTIONAR')" class="link" type="button" @click="edit(role)"><Pencil :size="14" /> Editar permisos</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
</template>
