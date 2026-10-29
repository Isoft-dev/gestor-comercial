const modules = [
  () => import('../views/DashboardView.vue'),
  () => import('../views/ClientsView.vue'),
  () => import('../views/ProductsView.vue'),
  () => import('../views/CatalogView.vue'),
  () => import('../views/UsersView.vue'),
  () => import('../views/RolesView.vue'),
  () => import('../views/PurchasesView.vue'),
  () => import('../views/InventoryView.vue'),
  () => import('../views/OrdersView.vue'),
  () => import('../views/SalesView.vue'),
  () => import('../views/ReportsView.vue'),
  () => import('../views/AuditView.vue'),
]

let started = false

export function prefetchModules() {
  if (started) return
  started = true
  let index = 0
  const step = () => {
    if (index >= modules.length) return
    modules[index++]().catch(() => {})
    window.setTimeout(step, 150)
  }
  window.setTimeout(step, 400)
}
