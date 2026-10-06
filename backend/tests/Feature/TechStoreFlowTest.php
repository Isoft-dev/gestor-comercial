<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Catalogos;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TechStoreFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_login_rejects_invalid_credentials_and_accepts_active_user(): void
    {
        $this->postJson('/api/login', [
            'correo' => 'admin@techstore.test',
            'contrasena' => 'incorrecta',
        ])->assertUnprocessable();

        $this->postJson('/api/login', [
            'correo' => 'admin@techstore.test',
            'contrasena' => 'TechStore2026!',
        ])->assertOk()->assertJsonStructure(['token', 'usuario' => ['id', 'correo', 'rol', 'permisos']]);
    }

    public function test_complete_commercial_flow_updates_inventory_and_balance(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $provider = DB::table('TEC_PROVEEDOR')->value('PRV_Proveedor');
        $client = DB::table('TEC_CLIENTE')->value('CLI_Cliente');
        $product = DB::table('TEC_PRODUCTO')->value('PRO_Producto');

        $purchaseId = $this->postJson('/api/compras', [
            'proveedor_id' => $provider,
            'detalles' => [[
                'producto_id' => $product,
                'cantidad' => 10,
                'costo_unitario' => 20,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/compras/{$purchaseId}/recibir")
            ->assertOk();
        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => 10,
        ]);

        $orderId = $this->postJson('/api/pedidos', [
            'cliente_id' => $client,
            'descuento_porcentaje' => 5,
            'detalles' => [[
                'producto_id' => $product,
                'cantidad' => 2,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/pedidos/{$orderId}/avanzar")
            ->assertOk()
            ->assertJsonPath('estado', 'APROBADO');

        $saleId = $this->postJson("/api/pedidos/{$orderId}/vender")
            ->assertCreated()
            ->json('id');
        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => 8,
        ]);

        $balance = (float) DB::table('TEC_VENTA')->where('VEN_Venta', $saleId)->value('VEN_Saldo');
        $this->postJson("/api/ventas/{$saleId}/pagos", [
            'monto' => $balance,
            'metodo' => 'EFECTIVO',
        ])->assertCreated();
        $this->assertDatabaseHas('TEC_VENTA', [
            'VEN_Venta' => $saleId,
            'EPA_Estado_Pago' => Catalogos::estadoPago('PAGADA'),
        ]);
        $this->assertDatabaseHas('TEC_AUDITORIA', [
            'AUD_Modulo' => 'VENTAS',
            'AUD_Accion' => 'FINALIZAR',
        ]);
    }

    public function test_sale_with_insufficient_inventory_is_rejected_without_partial_changes(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $client = DB::table('TEC_CLIENTE')->value('CLI_Cliente');
        $product = DB::table('TEC_PRODUCTO')->value('PRO_Producto');

        $orderId = $this->postJson('/api/pedidos', [
            'cliente_id' => $client,
            'detalles' => [['producto_id' => $product, 'cantidad' => 1]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/pedidos/{$orderId}/avanzar")->assertOk();
        $this->postJson("/api/pedidos/{$orderId}/vender")->assertConflict();

        $this->assertDatabaseMissing('TEC_VENTA', ['PED_Pedido' => $orderId]);
        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => 0,
        ]);
    }

    public function test_payment_cannot_exceed_pending_balance(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $saleId = DB::table('TEC_VENTA')->insertGetId([
            'PED_Pedido' => $this->createApprovedOrder($admin),
            'USU_Usuario' => $admin->USU_Usuario,
            'VEN_Fecha' => now(),
            'VEN_Total' => 100,
            'VEN_Pagado' => 0,
            'VEN_Saldo' => 100,
            'EPA_Estado_Pago' => Catalogos::estadoPago('PENDIENTE'),
            'ESV_Estado_Venta' => Catalogos::estadoVenta('FINALIZADA'),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'VEN_Venta');

        $this->postJson("/api/ventas/{$saleId}/pagos", [
            'monto' => 101,
            'metodo' => 'EFECTIVO',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('TEC_PAGO', 0);
    }

    public function test_user_email_must_be_unique(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $role = DB::table('TEC_ROL')->where('ROL_Nombre', 'OPERADOR')->value('ROL_Rol');

        $this->postJson('/api/usuarios', [
            'rol_id' => $role,
            'primer_nombre' => 'Otro',
            'primer_apellido' => 'Usuario',
            'correo' => 'admin@techstore.test',
            'contrasena' => 'Password2026!',
        ])->assertUnprocessable();
    }

    public function test_admin_can_create_role_and_update_permissions(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $permissionIds = DB::table('TEC_PERMISO')
            ->whereIn('PER_Codigo', ['CLIENTES.GESTIONAR', 'PEDIDOS.REGISTRAR'])
            ->pluck('PER_Permiso')
            ->all();

        $roleId = $this->postJson('/api/roles', [
            'nombre' => 'CONSULTOR',
            'permisos' => $permissionIds,
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('TEC_ROL', ['ROL_Nombre' => 'CONSULTOR']);
        $this->assertDatabaseHas('TEC_ROL_PERMISO', [
            'ROL_Rol' => $roleId,
            'PER_Permiso' => $permissionIds[0],
        ]);
        $this->assertDatabaseHas('TEC_ROL_PERMISO', [
            'ROL_Rol' => $roleId,
            'PER_Permiso' => $permissionIds[1],
        ]);

        $this->putJson("/api/roles/{$roleId}", [
            'nombre' => 'CONSULTOR',
            'permisos' => [$permissionIds[0]],
        ])->assertOk();

        $this->assertDatabaseHas('TEC_ROL_PERMISO', [
            'ROL_Rol' => $roleId,
            'PER_Permiso' => $permissionIds[0],
        ]);
        $this->assertDatabaseMissing('TEC_ROL_PERMISO', [
            'ROL_Rol' => $roleId,
            'PER_Permiso' => $permissionIds[1],
        ]);
    }

    public function test_administrator_role_cannot_be_inactivated(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $roleId = DB::table('TEC_ROL')->where('ROL_Nombre', 'ADMINISTRADOR')->value('ROL_Rol');

        $this->patchJson("/api/roles/{$roleId}/estado")->assertUnprocessable();
    }

    public function test_operator_cannot_access_dashboard_or_audit(): void
    {
        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($operator, 'sanctum');

        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/auditoria')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
        $this->postJson('/api/roles', ['nombre' => 'INVITADO', 'permisos' => [1]])->assertForbidden();
    }

    public function test_reference_catalogs_are_available_to_authenticated_users(): void
    {
        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($operator, 'sanctum');

        $this->getJson('/api/referencias')
            ->assertOk()
            ->assertJsonStructure([
                'estados_pedido',
                'estados_compra',
                'metodos_pago',
                'tipos_movimiento',
            ])
            ->assertJsonFragment(['ESP_Codigo' => 'CREADO'])
            ->assertJsonFragment(['MPA_Codigo' => 'EFECTIVO']);
    }

    public function test_exceptional_discount_requires_supervisor_approval(): void
    {
        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($operator, 'sanctum');
        $orderId = $this->postJson('/api/pedidos', [
            'cliente_id' => DB::table('TEC_CLIENTE')->value('CLI_Cliente'),
            'descuento_porcentaje' => 15,
            'detalles' => [[
                'producto_id' => DB::table('TEC_PRODUCTO')->value('PRO_Producto'),
                'cantidad' => 1,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/pedidos/{$orderId}/avanzar")
            ->assertOk()
            ->assertJsonPath('estado', 'PENDIENTE_APROBACION');

        $supervisor = User::where('USU_Correo', 'supervisor@techstore.test')->firstOrFail();
        $this->actingAs($supervisor, 'sanctum');
        $this->postJson("/api/pedidos/{$orderId}/decidir", [
            'decision' => 'APROBAR',
            'motivo' => 'Descuento comercial autorizado.',
        ])->assertOk()->assertJsonPath('estado', 'APROBADO');
    }

    public function test_cancelled_order_cannot_be_sold(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $orderId = $this->postJson('/api/pedidos', [
            'cliente_id' => DB::table('TEC_CLIENTE')->value('CLI_Cliente'),
            'detalles' => [[
                'producto_id' => DB::table('TEC_PRODUCTO')->value('PRO_Producto'),
                'cantidad' => 1,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/pedidos/{$orderId}/cancelar", ['motivo' => 'Solicitud del cliente'])
            ->assertOk();
        $this->postJson("/api/pedidos/{$orderId}/vender")->assertConflict();
    }

    public function test_inventory_adjustment_cannot_create_negative_stock(): void
    {
        $supervisor = User::where('USU_Correo', 'supervisor@techstore.test')->firstOrFail();
        $this->actingAs($supervisor, 'sanctum');

        $this->postJson('/api/inventario/ajustes', [
            'producto_id' => DB::table('TEC_PRODUCTO')->value('PRO_Producto'),
            'tipo' => 'AJUSTE_SALIDA',
            'cantidad' => 1,
            'motivo' => 'Prueba controlada',
        ])->assertUnprocessable();
    }

    public function test_reports_provide_four_required_datasets(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/reportes')
            ->assertOk()
            ->assertJsonStructure([
                'ventas_por_fecha',
                'productos_mas_vendidos',
                'inventario_bajo',
                'compras_por_proveedor',
            ]);
    }

    public function test_client_can_be_created_updated_and_searched(): void
    {
        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($operator, 'sanctum');

        $id = $this->postJson('/api/catalogos/clientes', [
            'identificacion' => 'DPI-908070',
            'primer_nombre' => 'Laura',
            'primer_apellido' => 'Méndez',
            'correo' => 'laura@example.test',
        ])->assertCreated()->json('id');

        $this->putJson("/api/catalogos/clientes/{$id}", [
            'identificacion' => 'DPI-908070',
            'primer_nombre' => 'Laura',
            'primer_apellido' => 'Morales',
            'telefono' => '5555-3333',
        ])->assertOk();

        $this->getJson('/api/catalogos/clientes?buscar=Morales')
            ->assertOk()
            ->assertJsonFragment(['CLI_Primer_Apellido' => 'Morales']);
    }

    public function test_product_can_be_created_and_updated_without_changing_stock(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $category = DB::table('TEC_CATEGORIA')->value('CAT_Categoria');
        $brand = DB::table('TEC_MARCA')->value('MAR_Marca');

        $id = $this->postJson('/api/catalogos/productos', [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-HUB-001',
            'nombre' => 'Hub USB',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 4,
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $id,
            'INV_Existencia' => 0,
        ]);

        $this->putJson("/api/catalogos/productos/{$id}", [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-HUB-001',
            'nombre' => 'Hub USB 4 puertos',
            'costo' => 11,
            'precio' => 19,
            'existencia_minima' => 4,
        ])->assertOk();

        $this->assertDatabaseHas('TEC_PRODUCTO', [
            'PRO_Producto' => $id,
            'PRO_Nombre' => 'Hub USB 4 puertos',
        ]);
        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $id,
            'INV_Existencia' => 0,
        ]);
    }

    public function test_dashboard_exposes_five_indicators_and_report_pdf_is_downloadable(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'indicadores' => [
                    'ventas',
                    'saldo_pendiente',
                    'pedidos_por_aprobar',
                    'productos_bajo_minimo',
                    'compras',
                ],
                'ventas_por_fecha',
                'productos_mas_vendidos',
            ]);

        $this->get('/api/reportes/ventas.pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_login_locks_after_five_failed_attempts_and_admin_can_unlock(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/login', [
                'correo' => 'operador@techstore.test',
                'contrasena' => 'incorrecta',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'correo' => 'operador@techstore.test',
            'contrasena' => 'TechStore2026!',
        ])->assertStatus(423);

        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $this->patchJson("/api/usuarios/{$operator->USU_Usuario}/desbloquear")->assertOk();

        $this->postJson('/api/login', [
            'correo' => 'operador@techstore.test',
            'contrasena' => 'TechStore2026!',
        ])->assertOk();
    }

    public function test_password_must_be_strong_and_users_cannot_be_deleted(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $role = DB::table('TEC_ROL')->where('ROL_Nombre', 'OPERADOR')->value('ROL_Rol');

        $this->postJson('/api/usuarios', [
            'rol_id' => $role,
            'primer_nombre' => 'Debil',
            'primer_apellido' => 'Clave',
            'correo' => 'debil@techstore.test',
            'contrasena' => 'clave',
        ])->assertUnprocessable();

        $this->deleteJson('/api/usuarios/1')->assertStatus(405);
    }

    public function test_public_catalog_is_available_and_supervisor_cannot_query_clients(): void
    {
        $this->getJson('/api/tienda/productos')
            ->assertOk()
            ->assertJsonFragment(['sku' => 'TEC-TEC-001']);

        $product = DB::table('TEC_PRODUCTO')->where('PRO_SKU', 'TEC-TEC-001')->first();
        DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product->PRO_Producto)->update(['INV_Existencia' => 4]);

        $orderId = $this->postJson('/api/tienda/pedidos', [
            'identificacion' => 'DPI-888001',
            'primer_nombre' => 'Ana',
            'primer_apellido' => 'Cliente',
            'detalles' => [['producto_id' => $product->PRO_Producto, 'cantidad' => 2]],
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $orderId, 'PED_Total' => 70]);

        $this->postJson('/api/tienda/pedidos', [
            'identificacion' => 'DPI-888001',
            'primer_nombre' => 'Ana',
            'primer_apellido' => 'Cliente',
            'detalles' => [['producto_id' => $product->PRO_Producto, 'cantidad' => 9]],
        ])->assertUnprocessable();

        $supervisor = User::where('USU_Correo', 'supervisor@techstore.test')->firstOrFail();
        $this->actingAs($supervisor, 'sanctum');
        $this->getJson('/api/catalogos/clientes')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
    }

    private function createApprovedOrder(User $user): int
    {
        return DB::table('TEC_PEDIDO')->insertGetId([
            'CLI_Cliente' => DB::table('TEC_CLIENTE')->value('CLI_Cliente'),
            'USU_Usuario' => $user->USU_Usuario,
            'PED_Fecha' => now(),
            'ESP_Estado_Pedido' => Catalogos::estadoPedido('COMPLETADO'),
            'PED_Subtotal' => 100,
            'PED_Descuento_Porcentaje' => 0,
            'PED_Descuento_Monto' => 0,
            'PED_Total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'PED_Pedido');
    }
}
