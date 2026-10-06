import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api from '../services/api'

export const useAuthStore = defineStore('auth', () => {
  const savedUser = localStorage.getItem('techstore_user')
  const user = ref(savedUser ? JSON.parse(savedUser) : null)
  const token = ref(localStorage.getItem('techstore_token'))
  const isAuthenticated = computed(() => Boolean(token.value))
  const role = computed(() => user.value?.rol || '')
  const permisos = computed(() => user.value?.permisos || [])

  function can(permission) {
    if (Array.isArray(permission)) return permission.some((item) => permisos.value.includes(item))
    return permisos.value.includes(permission)
  }

  function homePath() {
    if (can('REPORTES.CONSULTAR')) return '/'
    if (can('PEDIDOS.APROBAR') || can('PEDIDOS.REGISTRAR')) return '/pedidos'
    if (can('CLIENTES.GESTIONAR')) return '/clientes'
    return '/'
  }

  async function login(correo, contrasena) {
    const { data } = await api.post('/login', { correo, contrasena })
    token.value = data.token
    user.value = data.usuario
    localStorage.setItem('techstore_token', data.token)
    localStorage.setItem('techstore_user', JSON.stringify(data.usuario))
  }

  async function logout() {
    try {
      await api.post('/logout')
    } finally {
      token.value = null
      user.value = null
      localStorage.removeItem('techstore_token')
      localStorage.removeItem('techstore_user')
    }
  }

  return { user, token, role, permisos, can, homePath, isAuthenticated, login, logout }
})
