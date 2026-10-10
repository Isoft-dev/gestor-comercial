<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import {
  CircleAlert,
  House,
  IdCard,
  Package,
  Shield,
  Sparkles,
  Tags,
  UserPlus,
  Users,
} from '@lucide/vue'

const auth = useAuthStore()
const error = ref('')
const counts = ref(auth.inicio && typeof auth.inicio === 'object' ? auth.inicio : {})

const greeting = computed(() => {
  const hour = new Date().getHours()
  if (hour < 12) return 'Buenos días'
  if (hour < 19) return 'Buenas tardes'
  return 'Buenas noches'
})

const name = computed(() => {
  const first = auth.user?.primer_nombre || ''
  const last = auth.user?.primer_apellido || ''
  return `${first} ${last}`.trim() || 'usuario'
})

const metrics = computed(() => [
  { key: 'usuarios', label: 'Usuarios', value: counts.value.usuarios, icon: Users, tone: 'metric-sales', show: auth.can('USUARIOS.CONSULTAR') },
  { key: 'roles', label: 'Roles', value: counts.value.roles, icon: Shield, tone: 'metric-pending', show: auth.can('USUARIOS.CONSULTAR') },
  { key: 'clientes', label: 'Clientes', value: counts.value.clientes, icon: IdCard, tone: 'metric-balance', show: auth.can('CLIENTES.CONSULTAR') },
  { key: 'productos', label: 'Productos', value: counts.value.productos, icon: Package, tone: 'metric-purchases', show: auth.can('CATALOGOS.CONSULTAR') },
].filter((item) => item.show))

const actions = computed(() => [
  { to: '/usuarios', label: 'Gestionar usuarios', detail: 'Crear cuentas y asignar un rol.', icon: UserPlus, show: auth.can('USUARIOS.GESTIONAR') },
  { to: '/roles', label: 'Revisar roles', detail: 'Definir qué puede hacer cada perfil.', icon: Shield, show: auth.can('USUARIOS.GESTIONAR') },
  { to: '/usuarios', label: 'Ver usuarios', detail: 'Consultar cuentas y roles asignados.', icon: Users, show: auth.can('USUARIOS.CONSULTAR') && !auth.can('USUARIOS.GESTIONAR') },
  { to: '/clientes', label: 'Registrar cliente', detail: 'Alta, consulta, edición e inactivación.', icon: IdCard, show: auth.can('CLIENTES.GESTIONAR') },
  { to: '/clientes', label: 'Ver clientes', detail: 'Consultar el padrón de clientes.', icon: IdCard, show: auth.can('CLIENTES.CONSULTAR') && !auth.can('CLIENTES.GESTIONAR') },
  { to: '/productos', label: 'Registrar producto', detail: 'SKU, categoría, marca, costo y precio.', icon: Package, show: auth.can('CATALOGOS.GESTIONAR') },
  { to: '/categorias', label: 'Clasificar catálogo', detail: 'Categorías y marcas de los productos.', icon: Tags, show: auth.can('CATALOGOS.GESTIONAR') },
  { to: '/productos', label: 'Ver productos', detail: 'Consultar el catálogo sin modificarlo.', icon: Package, show: auth.can('CATALOGOS.CONSULTAR') && !auth.can('CATALOGOS.GESTIONAR') },
].filter((item) => item.show))

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/inicio')
    counts.value = data && typeof data === 'object' ? data : {}
  } catch {
    counts.value = {}
  }
}

onMounted(load)
</script>

<template>
  <header class="page-header">
    <div>
      <p class="eyebrow">Panel principal</p>
      <h1 class="page-title"><House :size="26" :stroke-width="2" /> {{ greeting }}, {{ name }}</h1>
    </div>
    <span class="status">{{ auth.role }}</span>
  </header>

  <section class="panel home-hero">
    <div class="panel-heading"><Sparkles :size="18" /><h2>Bienvenido a TechStore</h2></div>
    <p>Desde aquí puede consultar el estado del catálogo y entrar a las funciones de su rol.</p>
  </section>

  <p v-if="error" class="alert error"><CircleAlert :size="16" /> {{ error }}</p>

  <section v-if="metrics.length" class="metric-grid cols-4">
    <article v-for="metric in metrics" :key="metric.key" class="metric" :class="metric.tone">
      <div class="metric-icon"><component :is="metric.icon" :size="18" /></div>
      <span>{{ metric.label }}</span>
      <strong>{{ metric.value ?? '—' }}</strong>
    </article>
  </section>

  <section v-else class="panel">
    <p class="muted">Su usuario no administra catálogos ni personal. Use el menú para las funciones que sí tiene asignadas.</p>
  </section>

  <section v-if="actions.length" class="panel">
    <div class="panel-heading"><House :size="18" /><h2>Accesos rápidos</h2></div>
    <div class="home-actions">
      <RouterLink v-for="action in actions" :key="action.to" :to="action.to" class="home-action">
        <component :is="action.icon" :size="18" :stroke-width="2" />
        <strong>{{ action.label }}</strong>
        <span>{{ action.detail }}</span>
      </RouterLink>
    </div>
  </section>
</template>
