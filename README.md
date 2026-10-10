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

```powershell
cd backend
php -c ..\php.ini artisan migrate:fresh --seed
```

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

```powershell
cd backend
php -c ..\php.ini artisan serve

cd ..\frontend
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