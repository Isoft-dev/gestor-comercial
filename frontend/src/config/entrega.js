/** Módulos visibles. 1 = catálogo y acceso; 2 = compras; 3 = ventas; 4 = reportes. */
export const ENTREGA_ACTUAL = Number(import.meta.env.VITE_ENTREGA_ACTUAL ?? 1)

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

const PERMISO_DESDE = {
  'USUARIOS.CONSULTAR': 1,
  'USUARIOS.GESTIONAR': 1,
  'CLIENTES.CONSULTAR': 1,
  'CLIENTES.GESTIONAR': 1,
  'CATALOGOS.CONSULTAR': 1,
  'CATALOGOS.GESTIONAR': 1,
  'CATALOGOS.INACTIVAR': 1,
  'COMPRAS.REGISTRAR': 2,
  'INVENTARIO.AJUSTAR': 2,
  'PEDIDOS.REGISTRAR': 3,
  'PEDIDOS.APROBAR': 3,
  'VENTAS.REGISTRAR': 3,
  'PAGOS.REGISTRAR': 3,
  'AUDITORIA.CONSULTAR': 3,
  'REPORTES.CONSULTAR': 4,
}

export function moduloEnEntrega(path) {
  const desde = DESDE_ENTREGA[path] ?? 1
  return desde <= ENTREGA_ACTUAL
}

export function permisoEnEntrega(codigo) {
  return (PERMISO_DESDE[codigo] ?? 1) <= ENTREGA_ACTUAL
}
