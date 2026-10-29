import { createRouter, createWebHistory } from 'vue-router'
import { esEntregaCierre, moduloEnEntrega } from './config/entrega'
import { settlePageLoading, startPageLoading, stopPageLoading } from './lib/pageLoading'
import { useAuthStore } from './stores/auth'
import HomeView from './views/HomeView.vue'
import LoginView from './views/LoginView.vue'

const routes = [
  { path: '/login', component: LoginView, meta: { public: true } },
  { path: '/tienda', component: () => import('./views/ShopView.vue'), meta: { public: true } },
  { path: '/acceso-denegado', component: () => import('./views/ForbiddenView.vue') },
  {
    path: '/',
    component: esEntregaCierre
      ? () => import('./views/DashboardView.vue')
      : HomeView,
    meta: esEntregaCierre ? { permission: 'REPORTES.CONSULTAR' } : {},
  },
  { path: '/clientes', component: () => import('./views/ClientsView.vue'), meta: { permission: 'CLIENTES.CONSULTAR' } },
  { path: '/productos', component: () => import('./views/ProductsView.vue'), meta: { permission: 'CATALOGOS.CONSULTAR' } },
  { path: '/categorias', component: () => import('./views/CatalogView.vue'), meta: { permission: 'CATALOGOS.CONSULTAR', catalog: 'categorias' } },
  { path: '/marcas', component: () => import('./views/CatalogView.vue'), meta: { permission: 'CATALOGOS.CONSULTAR', catalog: 'marcas' } },
  { path: '/proveedores', component: () => import('./views/CatalogView.vue'), meta: { permission: 'CATALOGOS.CONSULTAR', catalog: 'proveedores' } },
  { path: '/catalogos/:type?', redirect: (to) => `/${to.params.type || 'proveedores'}` },
  { path: '/usuarios', component: () => import('./views/UsersView.vue'), meta: { permission: 'USUARIOS.CONSULTAR' } },
  { path: '/roles', component: () => import('./views/RolesView.vue'), meta: { permission: 'USUARIOS.CONSULTAR' } },
  { path: '/compras', component: () => import('./views/PurchasesView.vue'), meta: { permission: 'COMPRAS.REGISTRAR' } },
  { path: '/inventario', component: () => import('./views/InventoryView.vue'), meta: { permission: ['INVENTARIO.AJUSTAR', 'COMPRAS.REGISTRAR', 'PEDIDOS.REGISTRAR', 'VENTAS.REGISTRAR', 'REPORTES.CONSULTAR'] } },
  { path: '/pedidos', component: () => import('./views/OrdersView.vue'), meta: { permission: ['PEDIDOS.REGISTRAR', 'PEDIDOS.APROBAR'] } },
  { path: '/ventas', component: () => import('./views/SalesView.vue'), meta: { permission: ['VENTAS.REGISTRAR', 'PAGOS.REGISTRAR', 'REPORTES.CONSULTAR'] } },
  { path: '/reportes', component: () => import('./views/ReportsView.vue'), meta: { permission: 'REPORTES.CONSULTAR' } },
  { path: '/auditoria', component: () => import('./views/AuditView.vue'), meta: { permission: 'AUDITORIA.CONSULTAR' } },
  { path: '/:pathMatch(.*)*', redirect: '/login' },
]

const router = createRouter({ history: createWebHistory(), routes })

function allowed(auth, permission) {
  if (!permission) return true
  return Array.isArray(permission) ? permission.some((item) => auth.can(item)) : auth.can(permission)
}

router.beforeEach(async (to, from) => {
  const auth = useAuthStore()
  if (to.fullPath !== from.fullPath && !to.meta.public) startPageLoading()
  if (!moduloEnEntrega(to.path)) return auth.isAuthenticated ? auth.homePath() : '/login'
  if (!to.meta.public && !auth.isAuthenticated) return '/login'
  if (to.path === '/login' && auth.isAuthenticated) return auth.homePath()
  if (auth.isAuthenticated && !to.meta.public) {
    auth.ensureFreshProfile().catch(() => {})
  }
  if (!allowed(auth, to.meta.permission)) return '/acceso-denegado'
  return true
})

router.afterEach((to) => {
  if (to.meta.public) stopPageLoading()
  else settlePageLoading()
})

export default router
