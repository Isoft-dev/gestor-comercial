<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppDialog from './components/AppDialog.vue'
import { esEntregaCierre, moduloEnEntrega } from './config/entrega'
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
const collapsed = ref(window.innerWidth <= 1179 && window.innerWidth > 767)

const links = computed(() => [
  esEntregaCierre
    ? { to: '/', label: 'Dashboard', permission: 'REPORTES.CONSULTAR', icon: LayoutDashboard }
    : { to: '/', label: 'Inicio', icon: House },
  { to: '/clientes', label: 'Clientes', permission: 'CLIENTES.CONSULTAR', icon: IdCard },
  { to: '/productos', label: 'Productos', permission: 'CATALOGOS.CONSULTAR', icon: Package },
  { to: '/categorias', label: 'Categorías', permission: 'CATALOGOS.CONSULTAR', icon: Tags },
  { to: '/marcas', label: 'Marcas', permission: 'CATALOGOS.CONSULTAR', icon: Award },
  { to: '/proveedores', label: 'Proveedores', permission: 'CATALOGOS.CONSULTAR', icon: Building2 },
  { to: '/usuarios', label: 'Usuarios', permission: 'USUARIOS.CONSULTAR', icon: Users },
  { to: '/roles', label: 'Roles', permission: 'USUARIOS.CONSULTAR', icon: Shield },
  { to: '/compras', label: 'Compras', permission: 'COMPRAS.REGISTRAR', icon: Truck },
  { to: '/inventario', label: 'Inventario', permission: ['INVENTARIO.AJUSTAR', 'COMPRAS.REGISTRAR', 'PEDIDOS.REGISTRAR', 'VENTAS.REGISTRAR', 'REPORTES.CONSULTAR'], icon: Warehouse },
  { to: '/pedidos', label: 'Pedidos', permission: ['PEDIDOS.REGISTRAR', 'PEDIDOS.APROBAR'], icon: ClipboardList },
  { to: '/ventas', label: 'Ventas', permission: ['VENTAS.REGISTRAR', 'PAGOS.REGISTRAR', 'REPORTES.CONSULTAR'], icon: Wallet },
  { to: '/reportes', label: 'Reportes', permission: 'REPORTES.CONSULTAR', icon: BarChart3 },
  { to: '/auditoria', label: 'Bitácora', permission: 'AUDITORIA.CONSULTAR', icon: ShieldCheck },
].filter((link) => moduloEnEntrega(link.to) && (!link.permission || auth.can(link.permission))))

watch(() => route.fullPath, () => {
  menuOpen.value = false
})

function onResize() {
  if (window.innerWidth <= 767) collapsed.value = false
}

onMounted(() => window.addEventListener('resize', onResize))
onUnmounted(() => window.removeEventListener('resize', onResize))

function toggleCollapse() {
  if (window.innerWidth <= 767) {
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
  <div v-if="auth.isAuthenticated && !route.meta.public" class="app-shell" :class="{ collapsed }">
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
        <RouterLink v-for="link in links" :key="link.to" :to="link.to" :title="link.label">
          <component :is="link.icon" :size="18" :stroke-width="1.9" />
          <span class="nav-label">{{ link.label }}</span>
        </RouterLink>
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
      <Suspense>
        <RouterView />
        <template #fallback><p class="muted">Cargando…</p></template>
      </Suspense>
    </main>
  </div>
  <Suspense v-else>
    <RouterView />
    <template #fallback><p class="muted">Cargando…</p></template>
  </Suspense>
</template>
