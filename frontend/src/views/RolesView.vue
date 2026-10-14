<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { permisoEnEntrega } from '../config/entrega'
import { fields } from '../lib/fields'
import { useAuthStore } from '../stores/auth'
import { CircleAlert, CircleCheck, KeyRound, Pencil, Save, Shield, X } from '@lucide/vue'

const router = useRouter()

const auth = useAuthStore()

const roles = ref([])
const permissions = ref([])
const error = ref('')
const message = ref('')
const editingId = ref(null)
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

function reset() {
  editingId.value = null
  form.nombre = ''
  form.permisos = []
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
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section :class="{ 'two-column': auth.can('USUARIOS.GESTIONAR') }">
    <form v-if="auth.can('USUARIOS.GESTIONAR')" class="panel" @submit.prevent="submit">
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
        <button v-if="editingId" class="button secondary" type="button" @click="reset"><X :size="16" /> Cancelar</button>
      </div>
    </form>
    <section class="panel">
      <div class="panel-heading"><Shield :size="18" /><h2>Roles del sistema</h2></div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Rol</th>
              <th>Permisos</th>
              <th>Estado</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="role in roles" :key="role.ROL_Rol">
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
  </section>
</template>
