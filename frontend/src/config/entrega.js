/** Módulos visibles. 1 = catálogo y acceso; 2 = compras; 3 = ventas; 4 = reportes. */
export const ENTREGA_ACTUAL = 1

export const esEntregaCierre = ENTREGA_ACTUAL >= 4

const DESDE_ENTREGA = {
  '/reportes': 4,
  '/proveedores': 2,
  '/compras': 2,
  '/inventario': 2,
  '/pedidos': 3,
  '/ventas': 3,
  '/auditoria': 3,
  '/tienda': 99,
}

export function moduloEnEntrega(path) {
  const desde = DESDE_ENTREGA[path] ?? 1
  return desde <= ENTREGA_ACTUAL
}
