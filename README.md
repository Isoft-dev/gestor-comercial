# TechStore

Sistema académico de gestión para una tienda de accesorios y periféricos tecnológicos.

## Funciones

- Autenticación con tokens.
- Usuarios y roles.
- Módulos de clientes y productos con alta, consulta, edición e inactivación.
- Compras y recepción transaccional.
- Inventario, movimientos, ajustes y alertas.
- Pedidos, estados y aprobación de descuentos.
- Ventas, pagos y control de saldos.
- Auditoría.
- Dashboard con cinco indicadores, dos gráficas y filtro por fechas.
- Cuatro reportes, filtro por fechas y exportación CSV o PDF.
- Datos fiscales de clientes (NIT o CF, nombre/razón social y dirección fiscal).
- Pedidos con varios productos, detalle de importes e historial.
- Factura PDF emitida automáticamente cuando el pago deja el saldo en cero, con copia inmutable de los datos del cliente y los productos.
- Indicador de procesamiento y avisos inferiores para las operaciones.

### Facturación y flujo de pedidos

Ejecuta `php -c ..\php.ini artisan migrate` desde `backend` al actualizar.
Los clientes existentes conservan sus datos. Para facturar basta con que el
cliente tenga nombre completo, correo y teléfono (obligatorios en clientes nuevos).
NIT, razón social y dirección fiscal son opcionales: si faltan, la factura usa
`CF`, el nombre completo del cliente y "Ciudad", respectivamente.

El pedido avanza una sola vez: de CREADO a APROBADO, o a
PENDIENTE_APROBACION si algún descuento supera 10 %. Los descuentos del detalle
se aplican exclusivamente a su producto, se redondean a centavos por línea y se
aprueban individualmente. Los descuentos de hasta 10 % no necesitan supervisor.
El descuento general es opcional y solo puede usarse si ningún producto tiene
descuento; la API también impide combinar ambos tipos. Los pedidos existentes
conservan su descuento general.

Rechazar un descuento no cancela el pedido: el usuario que registra pedidos
recibe una pregunta por cada descuento rechazado. Puede proponer otro porcentaje
(si supera 10 %, vuelve a aprobación) o elegir **No** para conservar el producto
a precio normal. Mientras haya aprobaciones o correcciones pendientes, no se
puede vender. Las decisiones y los cambios quedan en el historial y la auditoría.
La misma corrección aplica a un descuento general rechazado.

El resumen de productos permanece desplegado en pedidos creados o pendientes de
aprobación. Después de aprobar o vender, se consulta desde **Ver Detalle**, con
descuentos por producto, estados y motivos de aprobación, importes e historial.

Después de la aprobación,
**Vender** finaliza la venta y descuenta inventario. Los pedidos antiguos
EN_PROCESO aún pueden venderse. Los pagos parciales no facturan; el pago que
deja el saldo en cero emite la factura en la misma transacción; el PDF se
descarga cuando se necesite con el botón **Factura PDF** en Ventas. Si el cliente no tiene
correo o teléfono, ese pago final se rechaza sin registrarse.
Las ventas pagadas antes de esta actualización no reciben facturas retroactivas.
Los importes y datos fiscales emitidos no cambian si luego se edita al cliente
o al producto. Esta facturación es interna, sin integración ni certificación FEL.

Actualmente crear o aprobar un pedido **no reserva existencias**. La venta valida
el stock con bloqueo transaccional y lo descuenta; si ya no alcanza, se rechaza
sin cambios parciales. No se han agregado reservas en esta actualización.

### Validaciones de datos

- DPI: exactamente 13 dígitos.
- NIT: hasta 8 caracteres (dígitos y verificador final opcional `K`), o `CF` si se deja vacío.
- Teléfono: 8 dígitos y debe iniciar con 2–7 (formato de Guatemala).
- Tarjeta (demostración): 16 dígitos con verificación Luhn, vencimiento futuro y CVV.
- Descuento por producto o general (excluyentes): entre 0 % y 100 %, con hasta 2 decimales.
- Pago: mayor a cero, hasta 2 decimales, y sin superar el saldo pendiente.

Los clientes existentes que tengan teléfonos o DPI con el formato anterior deben
corregirse la próxima vez que se editen.

## Arquitectura

```text
Vue 3 SPA → Laravel API REST → MySQL
```

El ambiente local usa **MySQL de XAMPP** y Apache en `http://techstore.local`. `npm run build` deja la SPA dentro de `backend/public`.

## Instalación

La guía para clonar y levantar el proyecto en otro equipo está en [`docs/INSTALACION.md`](docs/INSTALACION.md).

## Requisitos

- PHP 8.3 o superior. En este equipo se usa `C:\PHP` (8.5), porque XAMPP trae 8.2.
- Composer.
- Node.js 20 o superior.
- XAMPP con MySQL y Apache.

El repositorio incluye `composer.phar` y un `php.ini` local para `C:\PHP`.

## Base de datos

En phpMyAdmin o en la consola de XAMPP:

```sql
CREATE DATABASE techstore
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

`backend/.env` ya apunta a MySQL (`root` sin contraseña). Si tu MySQL tiene clave, cámbiala en `DB_PASSWORD`.

Si la base de datos ya existe, aplica la nueva columna de foto sin borrar los datos y crea el enlace para servir las imágenes:

```powershell
cd backend
php -c ..\php.ini artisan migrate
php -c ..\php.ini artisan storage:link
```

```powershell
cd backend
php -c ..\php.ini artisan migrate:fresh --seed
```

La foto de los productos es opcional. Los archivos se guardan en `backend/storage/app/public/products`; el enlace `backend/public/storage` permite mostrarlos en el sistema.

## Publicar frontend y Apache

```powershell
cd frontend
npm install
npm run build
```

El virtual host está en `apache/techstore.local.conf`. Debe incluirse desde `C:\xampp\apache\conf\extra\httpd-vhosts.conf` y el archivo hosts debe tener:

```text
127.0.0.1 techstore.local
```

Reinicia Apache y abre `http://techstore.local`.

## Desarrollo con Vite

Si quieres recarga en caliente, sin Apache:

Antes de iniciar el backend, inicia **MySQL** desde el panel de XAMPP. El servidor PHP debe cargarse con el `php.ini` del proyecto para habilitar `pdo_mysql`.

```powershell
# Terminal 1: API (el directorio actual debe ser backend\public)
cd backend\public
& "C:\PHP\php.exe" -c "..\..\php.ini" -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
```

En otra terminal, desde la raíz del repositorio:

```powershell
cd frontend
npm run dev
```

- Frontend: `http://localhost:5173`
- API: `http://127.0.0.1:8000/api`

## Pruebas

```powershell
cd backend
php -c ..\php.ini vendor\bin\phpunit

cd ..\frontend
node --test src\lib\feedback.test.js
npm run build
```