<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::get('/tienda/productos', [ShopController::class, 'products']);
Route::post('/tienda/pedidos', [ShopController::class, 'order']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/inicio', [AuthController::class, 'home']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/usuarios', [CatalogController::class, 'users']);
    Route::post('/usuarios', [CatalogController::class, 'storeUser']);
    Route::put('/usuarios/{id}', [CatalogController::class, 'updateUser']);
    Route::patch('/usuarios/{id}/estado', [CatalogController::class, 'toggleUser']);
    Route::patch('/usuarios/{id}/desbloquear', [CatalogController::class, 'unlockUser']);
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::put('/roles/{id}', [RoleController::class, 'update']);
    Route::patch('/roles/{id}/estado', [RoleController::class, 'toggle']);
    Route::get('/permisos', [RoleController::class, 'permissions']);
    Route::get('/referencias', [CatalogController::class, 'references']);

    Route::get('/catalogos/{type}', [CatalogController::class, 'index']);
    Route::post('/catalogos/{type}', [CatalogController::class, 'store']);
    Route::put('/catalogos/{type}/{id}', [CatalogController::class, 'update']);
    Route::delete('/catalogos/{type}/{id}', [CatalogController::class, 'destroy']);
    Route::patch('/catalogos/{type}/{id}/estado', [CatalogController::class, 'activate']);

    Route::get('/compras', [PurchaseController::class, 'index']);
    Route::post('/compras', [PurchaseController::class, 'store']);
    Route::post('/compras/{id}/recibir', [PurchaseController::class, 'receive']);
    Route::get('/recepciones', [PurchaseController::class, 'receipts']);
    Route::get('/recepciones/{id}', [PurchaseController::class, 'receipt']);

    Route::get('/inventario', [InventoryController::class, 'stock']);
    Route::get('/inventario/movimientos', [InventoryController::class, 'movements']);
    Route::get('/inventario/alertas', [InventoryController::class, 'alerts']);
    Route::post('/inventario/ajustes', [InventoryController::class, 'adjust']);

    Route::get('/pedidos', [OrderController::class, 'index']);
    Route::post('/pedidos', [OrderController::class, 'store']);
    Route::post('/pedidos/{id}/avanzar', [OrderController::class, 'advance']);
    Route::post('/pedidos/{id}/decidir', [OrderController::class, 'decide']);
    Route::post('/pedidos/{id}/cancelar', [OrderController::class, 'cancel']);

    Route::get('/ventas', [SaleController::class, 'index']);
    Route::post('/pedidos/{id}/vender', [SaleController::class, 'storeFromOrder']);
    Route::post('/ventas/{id}/pagos', [SaleController::class, 'payment']);

    Route::get('/auditoria', [AuditController::class, 'index']);
    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reportes', [ReportController::class, 'reports']);
    Route::get('/reportes/ventas.csv', [ReportController::class, 'exportSales']);
    Route::get('/reportes/{tipo}.pdf', [ReportController::class, 'exportPdf']);
});
