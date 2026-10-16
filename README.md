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

## Arquitectura

```text
Vue 3 SPA → Laravel API REST → MySQL
```

El ambiente local usa **MySQL de XAMPP** y Apache en `http://techstore.local`. `npm run build` deja la SPA dentro de `backend/public`.

## Instalación

La guía para clonar y levantar el proyecto en otro equipo está en [`INSTALACION.md`](INSTALACION.md).

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
npm run build
```