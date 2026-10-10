import { createRouter, createWebHistory } from 'vue-router'
import { esEntregaCierre, moduloEnEntrega } from './config/entrega'
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
  { path: '/clientes', component: () => import('./views/ClientsView.vue'), meta: { permission: 'CLIENTES.GESTIONAR' } },
  { path: '/productos', component: () => import('./views/ProductsView.vue'), meta: { permission: 'CATALOGOS.GESTIONAR' } },
  { path: '/categorias', component: () => import('./views/CatalogView.vue'), meta: { permission: 'CATALOGOS.GESTIONAR', catalog: 'categorias' } },
  { path: '/marcas', component: () => import('./views/CatalogView.vue'), meta: { permission: 'CATALOGOS.GESTIONAR', catalog: 'marcas' } },
  { path: '/proveedores', component: () => import('./views/CatalogView.vue'), meta: { permission: 'CATALOGOS.GESTIONAR', catalog: 'proveedores' } },
  { path: '/catalogos/:type?', redirect: (to) => `/${to.params.type || 'proveedores'}` },
  { path: '/usuarios', component: () => import('./views/UsersView.vue'), meta: { permission: 'USUARIOS.GESTIONAR' } },
  { path: '/roles', component: () => import('./views/RolesView.vue'), meta: { permission: 'USUARIOS.GESTIONAR' } },
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

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (!moduloEnEntrega(to.path)) return auth.isAuthenticated ? auth.homePath() : '/login'
  if (!to.meta.public && !auth.isAuthenticated) return '/login'
  if (to.path === '/login' && auth.isAuthenticated) return auth.homePath()
  if (!allowed(auth, to.meta.permission)) return '/acceso-denegado'
  return true
})

export default router
