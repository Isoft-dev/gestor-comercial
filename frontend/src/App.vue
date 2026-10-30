<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppDialog from './components/AppDialog.vue'
import AppNotifications from './components/AppNotifications.vue'
import { pendingMutations } from './lib/pageLoading'
import { esEntregaCierre, moduloEnEntrega } from './config/entrega'
import { pageLoading } from './lib/pageLoading'
import { prefetchModules } from './lib/prefetch'
import { useAuthStore } from './stores/auth'
import {
  Award,
  BarChart3,
  Building2,
  ClipboardList,
  Cpu,
  House,
  IdCard,
  LayoutDashboard,
  LogOut,
  Menu,
  Package,
  Shield,
  ShieldCheck,
  Tags,
  Truck,
  Users,
  Wallet,
  Warehouse,
} from '@lucide/vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const menuOpen = ref(false)
const collapsed = ref(false)

const groups = computed(() => {
  const links = [
    esEntregaCierre
      ? { group: 'General', to: '/', label: 'Dashboard', permission: 'REPORTES.CONSULTAR', icon: LayoutDashboard }
      : { group: 'General', to: '/', label: 'Inicio', icon: House },
    { group: 'Catálogo', to: '/clientes', label: 'Clientes', permission: 'CLIENTES.CONSULTAR', icon: IdCard },
    { group: 'Catálogo', to: '/productos', label: 'Productos', permission: 'CATALOGOS.CONSULTAR', icon: Package },
    { group: 'Catálogo', to: '/categorias', label: 'Categorías', permission: 'CATALOGOS.CONSULTAR', icon: Tags },
    { group: 'Catálogo', to: '/marcas', label: 'Marcas', permission: 'CATALOGOS.CONSULTAR', icon: Award },
    { group: 'Catálogo', to: '/proveedores', label: 'Proveedores', permission: 'CATALOGOS.CONSULTAR', icon: Building2 },
    { group: 'Administración', to: '/usuarios', label: 'Usuarios', permission: 'USUARIOS.CONSULTAR', icon: Users },
    { group: 'Administración', to: '/roles', label: 'Roles', permission: 'USUARIOS.CONSULTAR', icon: Shield },
    { group: 'Operación', to: '/compras', label: 'Compras', permission: 'COMPRAS.REGISTRAR', icon: Truck },
    { group: 'Operación', to: '/inventario', label: 'Inventario', permission: ['INVENTARIO.AJUSTAR', 'COMPRAS.REGISTRAR', 'PEDIDOS.REGISTRAR', 'VENTAS.REGISTRAR', 'REPORTES.CONSULTAR'], icon: Warehouse },
    { group: 'Operación', to: '/pedidos', label: 'Pedidos', permission: ['PEDIDOS.REGISTRAR', 'PEDIDOS.APROBAR'], icon: ClipboardList },
    { group: 'Operación', to: '/ventas', label: 'Ventas', permission: ['VENTAS.REGISTRAR', 'PAGOS.REGISTRAR', 'REPORTES.CONSULTAR'], icon: Wallet },
    { group: 'Información', to: '/reportes', label: 'Reportes', permission: 'REPORTES.CONSULTAR', icon: BarChart3 },
    { group: 'Información', to: '/auditoria', label: 'Bitácora', permission: 'AUDITORIA.CONSULTAR', icon: ShieldCheck },
  ].filter((link) => moduloEnEntrega(link.to) && (!link.permission || auth.can(link.permission)))

  return ['General', 'Catálogo', 'Administración', 'Operación', 'Información']
    .map((name) => ({ name, links: links.filter((link) => link.group === name) }))
    .filter((group) => group.links.length)
})

watch(() => route.fullPath, () => {
  menuOpen.value = false
})

function onResize() {
  if (window.innerWidth <= 1179) {
    collapsed.value = false
    return
  }
  menuOpen.value = false
}

onMounted(() => {
  window.addEventListener('resize', onResize)
  if (auth.isAuthenticated) prefetchModules()
})
onUnmounted(() => window.removeEventListener('resize', onResize))

function toggleCollapse() {
  if (window.innerWidth <= 1179) {
    menuOpen.value = !menuOpen.value
    return
  }
  collapsed.value = !collapsed.value
}

async function signOut() {
  menuOpen.value = false
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <AppDialog />
  <AppNotifications />
  <Teleport to="body">
    <div v-if="pendingMutations > 0" class="mutation-loading" role="status" aria-live="polite" aria-label="Procesando cambios">
      <span class="spinner" aria-hidden="true"></span>
      <p>Procesando cambios…</p>
    </div>
  </Teleport>
  <div v-if="auth.isAuthenticated && !route.meta.public" class="app-shell" :class="{ collapsed }" :inert="pendingMutations > 0">
    <header class="topbar">
      <button class="icon-button" type="button" aria-label="Abrir menú" @click="menuOpen = true">
        <Menu :size="20" :stroke-width="2" />
      </button>
      <div class="brand">
        <span class="brand-mark"><Cpu :size="20" :stroke-width="2.2" /></span>
        <div class="brand-copy">
          <strong>TechStore</strong>
        </div>
      </div>
      <button class="icon-button" type="button" title="Cerrar sesión" @click="signOut">
        <LogOut :size="18" :stroke-width="2" />
      </button>
    </header>
    <div v-if="menuOpen" class="sidebar-backdrop" @click="menuOpen = false"></div>
    <aside class="sidebar" :class="{ open: menuOpen }">
      <div class="sidebar-head">
        <div class="brand">
          <span class="brand-mark"><Cpu :size="20" :stroke-width="2.2" /></span>
          <div class="brand-copy">
            <strong>TechStore</strong>
            <small>Gestión comercial</small>
          </div>
        </div>
        <button class="icon-button sidebar-toggle" type="button" :title="collapsed ? 'Expandir menú' : 'Compactar menú'" :aria-label="collapsed ? 'Expandir menú' : 'Compactar menú'" @click="toggleCollapse">
          <Menu :size="20" :stroke-width="2" />
        </button>
      </div>
      <nav>
        <template v-for="group in groups" :key="group.name">
          <p class="nav-group">{{ group.name }}</p>
          <RouterLink v-for="link in group.links" :key="link.to" :to="link.to" :title="link.label">
            <component :is="link.icon" :size="18" :stroke-width="1.9" />
            <span class="nav-label">{{ link.label }}</span>
          </RouterLink>
        </template>
      </nav>
      <div class="profile">
        <div class="profile-copy">
          <strong>{{ auth.user?.primer_nombre }} {{ auth.user?.primer_apellido }}</strong>
          <small>{{ auth.role }}</small>
        </div>
        <button class="button secondary" type="button" title="Cerrar sesión" @click="signOut">
          <LogOut :size="16" :stroke-width="2" />
          <span class="btn-label">Cerrar sesión</span>
        </button>
      </div>
    </aside>
    <main class="content">
      <RouterView v-slot="{ Component, route: current }">
        <Transition name="page" mode="out-in">
          <div :key="current.path" class="page-stage" :inert="pendingMutations > 0">
            <Suspense>
              <component :is="Component" />
              <template #fallback>
                <div class="page-loading" role="status">
                  <span class="spinner" aria-hidden="true"></span>
                  <p>Cargando…</p>
                </div>
              </template>
            </Suspense>
          </div>
        </Transition>
      </RouterView>
      <div v-if="pageLoading" class="page-loading" role="status" aria-live="polite">
        <span class="spinner" aria-hidden="true"></span>
        <p>Cargando datos…</p>
      </div>
    </main>
  </div>
  <RouterView v-else v-slot="{ Component, route: current }">
    <Transition name="page" mode="out-in">
      <div :key="current.path" class="page-stage">
        <Suspense>
          <component :is="Component" />
          <template #fallback><p class="muted">Cargando…</p></template>
        </Suspense>
      </div>
    </Transition>
  </RouterView>
</template>
