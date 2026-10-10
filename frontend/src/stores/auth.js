import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { esEntregaCierre, moduloEnEntrega } from '../config/entrega'
import api from '../services/api'

export const useAuthStore = defineStore('auth', () => {
  const savedUser = localStorage.getItem('techstore_user')
  const user = ref(savedUser ? JSON.parse(savedUser) : null)
  const token = ref(localStorage.getItem('techstore_token'))
  const inicio = ref(null)
  const isAuthenticated = computed(() => Boolean(token.value))
  const role = computed(() => user.value?.rol || '')
  const permisos = computed(() => user.value?.permisos || [])

  function can(permission) {
    if (Array.isArray(permission)) return permission.some((item) => permisos.value.includes(item))
    return permisos.value.includes(permission)
  }

  function homePath() {
    if (!esEntregaCierre) return '/'

    const opciones = [
      { path: '/', permission: 'REPORTES.CONSULTAR' },
      { path: '/usuarios', permission: 'USUARIOS.GESTIONAR' },
      { path: '/clientes', permission: 'CLIENTES.GESTIONAR' },
      { path: '/productos', permission: 'CATALOGOS.GESTIONAR' },
      { path: '/pedidos', permission: ['PEDIDOS.APROBAR', 'PEDIDOS.REGISTRAR'] },
      { path: '/compras', permission: 'COMPRAS.REGISTRAR' },
      { path: '/reportes', permission: 'REPORTES.CONSULTAR' },
    ]

    return opciones.find((item) => moduloEnEntrega(item.path) && can(item.permission))?.path || '/clientes'
  }

  async function login(correo, contrasena) {
    const { data } = await api.post('/login', { correo, contrasena })
    token.value = data.token
    user.value = data.usuario
    inicio.value = data.inicio || null
    localStorage.setItem('techstore_token', data.token)
    localStorage.setItem('techstore_user', JSON.stringify(data.usuario))
  }

  async function refreshProfile() {
    const { data } = await api.get('/me')
    user.value = data
    localStorage.setItem('techstore_user', JSON.stringify(data))
  }

  async function logout() {
    try {
      await api.post('/logout')
    } finally {
      token.value = null
      user.value = null
      inicio.value = null
      localStorage.removeItem('techstore_token')
      localStorage.removeItem('techstore_user')
    }
  }

  return { user, token, inicio, role, permisos, can, homePath, isAuthenticated, login, refreshProfile, logout }
})
