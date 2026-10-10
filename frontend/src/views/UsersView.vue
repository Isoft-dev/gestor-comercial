<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { CircleAlert, CircleCheck, Pencil, Save, UserPlus, Users, X } from '@lucide/vue'

const auth = useAuthStore()
const users = ref([])
const roles = ref([])
const error = ref('')
const message = ref('')
const editingId = ref(null)
const form = reactive(emptyForm())

function emptyForm() {
  return {
    rol_id: '',
    primer_nombre: '',
    segundo_nombre: '',
    primer_apellido: '',
    segundo_apellido: '',
    correo: '',
    contrasena: '',
  }
}

const selectableRoles = computed(() => roles.value.filter((role) => role.ROL_Estado || role.ROL_Rol == form.rol_id))

function reset() {
  editingId.value = null
  Object.assign(form, emptyForm())
}

async function load() {
  try {
    const [usersResponse, rolesResponse] = await Promise.all([
      api.get('/usuarios'),
      api.get('/roles', { params: { compact: 1 } }),
    ])
    users.value = usersResponse.data
    roles.value = rolesResponse.data
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function submit() {
  message.value = ''
  error.value = ''
  try {
    const payload = { ...form }
    if (editingId.value && !payload.contrasena) delete payload.contrasena
    if (editingId.value) {
      await api.put(`/usuarios/${editingId.value}`, payload)
      message.value = 'Usuario actualizado.'
      if (editingId.value === auth.user?.id) await auth.refreshProfile()
    } else {
      await api.post('/usuarios', payload)
      message.value = 'Usuario creado.'
    }
    reset()
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function edit(user) {
  editingId.value = user.id
  Object.assign(form, {
    rol_id: user.rol_id,
    primer_nombre: user.primer_nombre,
    segundo_nombre: user.segundo_nombre || '',
    primer_apellido: user.primer_apellido,
    segundo_apellido: user.segundo_apellido || '',
    correo: user.correo,
    contrasena: '',
  })
}

async function toggle(user) {
  error.value = ''
  try {
    await api.patch(`/usuarios/${user.id}/estado`)
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

async function unlock(user) {
  error.value = ''
  try {
    await api.patch(`/usuarios/${user.id}/desbloquear`)
    message.value = 'Usuario desbloqueado.'
    await load()
  } catch (requestError) {
    error.value = requestError.userMessage
  }
}

function isLocked(user) {
  return Boolean(user.bloqueado_hasta && new Date(user.bloqueado_hasta) > new Date())
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Administración</p>
      <h1 class="page-title"><Users :size="26" :stroke-width="2" /> Usuarios</h1>
    </div>
  </header>
  <p v-if="message" class="alert success"><CircleCheck :size="16" /> {{ message }}</p>
  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>
  <section class="two-column">
    <form class="panel" @submit.prevent="submit">
      <div class="panel-heading">
        <UserPlus :size="18" />
        <h2>{{ editingId ? 'Modificar usuario' : 'Nuevo usuario' }}</h2>
      </div>
      <div class="form-grid">
        <label>Rol
          <select v-model="form.rol_id" required>
            <option value="">Seleccione</option>
            <option v-for="role in selectableRoles" :key="role.ROL_Rol" :value="role.ROL_Rol">{{ role.ROL_Nombre }}</option>
          </select>
        </label>
        <label>Primer nombre<input v-model="form.primer_nombre" required /></label>
        <label>Segundo nombre<input v-model="form.segundo_nombre" /></label>
        <label>Primer apellido<input v-model="form.primer_apellido" required /></label>
        <label>Segundo apellido<input v-model="form.segundo_apellido" /></label>
        <label>Correo<input v-model="form.correo" type="email" required /></label>
        <label>Contraseña
          <input v-model="form.contrasena" type="password" minlength="10" :required="!editingId" />
          <small class="muted">{{ editingId ? 'Déjela vacía para conservar la actual.' : 'Mínimo 10 caracteres, mayúscula, minúscula, número y símbolo.' }}</small>
        </label>
      </div>
      <div class="actions">
        <button class="button primary" type="submit">
          <Save v-if="editingId" :size="16" />
          <UserPlus v-else :size="16" />
          {{ editingId ? 'Guardar cambios' : 'Crear usuario' }}
        </button>
        <button v-if="editingId" class="button secondary" type="button" @click="reset"><X :size="16" /> Cancelar</button>
      </div>
    </form>
    <section class="panel">
      <div class="panel-heading"><Users :size="18" /><h2>Usuarios registrados</h2></div>
      <p class="muted">Los usuarios no se eliminan: solo se desactivan.</p>
      <div class="table-wrap"><table>
        <thead><tr><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Acceso</th><th>Acción</th></tr></thead>
        <tbody><tr v-for="user in users" :key="user.id">
          <td>{{ user.primer_nombre }} {{ user.primer_apellido }}</td>
          <td>{{ user.correo }}</td>
          <td>{{ user.rol }}</td>
          <td>
            <label class="switch" :class="{ disabled: user.id === auth.user?.id }">
              <input type="checkbox" :checked="user.estado" :disabled="user.id === auth.user?.id" @change="toggle(user)" />
              <span class="switch-ui"></span>
              <span class="switch-text">{{ user.estado ? 'Activo' : 'Inactivo' }}</span>
            </label>
          </td>
          <td>
            <span v-if="isLocked(user)" class="status off">Bloqueado</span>
            <button v-if="isLocked(user)" class="link" type="button" @click="unlock(user)">Desbloquear</button>
            <span v-else class="muted">Libre</span>
          </td>
          <td>
            <button class="link" type="button" @click="edit(user)"><Pencil :size="14" /> Editar</button>
          </td>
        </tr></tbody>
      </table></div>
    </section>
  </section>
</template>
