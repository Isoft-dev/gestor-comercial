# Datos de demostración adicionales

Este conjunto agrega datos de ejemplo para clientes, categorías, marcas, productos y usuarios. No elimina ni sobrescribe registros existentes y se puede ejecutar varias veces sin duplicar los datos de este listado.

## Qué agrega

- 12 clientes de prueba.
- 3 categorías: Audio y video, Componentes, Cables y adaptadores.
- 5 marcas: Dell, HP, Ugreen, Samsung y Anker.
- 15 productos relacionados con esas categorías y marcas.
- 6 usuarios: 2 por cada uno de los tres roles que ya existen.

Los productos se crean con existencia inicial de cero. Se agrega la fila correspondiente en inventario porque el sistema la necesita para mostrar y gestionar cada producto. Este script no registra compras, ventas, pedidos ni movimientos.

## Cómo ejecutarlo

Desde PowerShell:

```powershell
cd C:\gestor-comercial\backend
php artisan db:seed --class=DemoDataSeeder
```

El archivo ejecutable es `database/seeders/DemoDataSeeder.php`. El seeder requiere que existan los roles `ADMINISTRADOR`, `SUPERVISOR` y `OPERADOR`; los reutiliza, no crea roles ni cambia sus permisos.

## Usuarios agregados

La contraseña de las seis cuentas es `TechStore2026!`.

| Correo | Rol |
|---|---|
| `camila.navarro@techstore.test` | Administrador |
| `tomas.ibarra@techstore.test` | Administrador |
| `gabriela.salazar@techstore.test` | Supervisor |
| `esteban.cifuentes@techstore.test` | Supervisor |
| `lucia.paredes@techstore.test` | Operador |
| `mateo.calderon@techstore.test` | Operador |

Son cuentas de desarrollo local. Cambia sus contraseñas antes de usar datos reales o publicar la aplicación.

## Productos agregados

| SKU | Producto | Categoría | Marca | Costo | Precio |
|---|---|---|---|---:|---:|
| `DEMO-AUD-001` | Audífonos Bluetooth | Audio y video | Anker | 180 | 275 |
| `DEMO-AUD-002` | Audífonos USB-C | Audio y video | Samsung | 95 | 155 |
| `DEMO-AUD-003` | Bocina compacta USB | Audio y video | HP | 120 | 195 |
| `DEMO-AUD-004` | Webcam HD | Audio y video | HP | 210 | 315 |
| `DEMO-COM-001` | SSD SATA 480 GB | Componentes | Samsung | 310 | 445 |
| `DEMO-COM-002` | Memoria RAM DDR4 8 GB | Componentes | Dell | 185 | 265 |
| `DEMO-COM-003` | Mouse inalámbrico | Componentes | HP | 75 | 125 |
| `DEMO-COM-004` | Teclado USB español | Componentes | Dell | 105 | 165 |
| `DEMO-COM-005` | Memoria USB 128 GB | Componentes | Samsung | 70 | 115 |
| `DEMO-COM-006` | Base para laptop | Componentes | Dell | 130 | 205 |
| `DEMO-CAB-001` | Adaptador USB-C a HDMI | Cables y adaptadores | Ugreen | 145 | 225 |
| `DEMO-CAB-002` | Cable HDMI 2 m | Cables y adaptadores | Ugreen | 48 | 85 |
| `DEMO-CAB-003` | Cable USB-C 1 m | Cables y adaptadores | Anker | 35 | 65 |
| `DEMO-CAB-004` | Cargador USB-C 30 W | Cables y adaptadores | Anker | 160 | 245 |
| `DEMO-CAB-005` | Hub USB 4 puertos | Cables y adaptadores | Ugreen | 110 | 175 |

Los clientes de prueba usan identificaciones `DEMO-CLI-001` a `DEMO-CLI-012` y correos reservados bajo `.test`.
