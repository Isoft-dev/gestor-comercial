<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Catalogos;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
        ])->assertUnprocessable()->assertJson([
            'message' => 'Credenciales inválidas o cuenta inexistente.',
        ]);

        $this->postJson('/api/login', [
            'correo' => 'nadie@techstore.test',
            'contrasena' => 'incorrecta',
        ])->assertUnprocessable()->assertJson([
            'message' => 'Credenciales inválidas o cuenta inexistente.',
        ]);

        $login = $this->postJson('/api/login', [
            'correo' => 'admin@techstore.test',
            'contrasena' => 'TechStore2026!',
        ])->assertOk()->assertJsonStructure(['token', 'usuario' => ['id', 'correo', 'rol', 'permisos'], 'inicio']);

        $this->withToken($login->json('token'))
            ->getJson('/api/inicio')
            ->assertOk()
            ->assertJsonStructure(['usuarios', 'clientes', 'productos']);
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

        $saleDetails = $this->getJson("/api/ventas?con_opciones=1&buscar={$saleId}")
            ->assertOk()
            ->assertJsonPath('data.data.0.PED_Pedido', $orderId)
            ->assertJsonStructure([
                'data' => [
                    'data' => [[
                        'VEN_Fecha',
                        'PED_Fecha',
                        'PED_Subtotal',
                        'PED_Descuento_Porcentaje',
                        'PED_Descuento_Monto',
                        'PED_Total',
                        'PED_Estado',
                        'CLI_Identificacion',
                        'CLI_Correo',
                        'CLI_Telefono',
                        'VEN_Vendedor_Nombre',
                        'VEN_Vendedor_Apellido',
                        'detalles' => [[
                            'DVE_Cantidad',
                            'DVE_Precio_Unitario',
                            'DVE_Subtotal',
                            'PRO_SKU',
                            'PRO_Nombre',
                        ]],
                        'pagos' => [[
                            'PAG_Monto',
                            'PAG_Fecha',
                            'PAG_Referencia',
                            'PAG_Metodo',
                            'metodo_nombre',
                            'PAG_Usuario_Nombre',
                            'PAG_Usuario_Apellido',
                        ]],
                    ]],
                ],
            ]);
        $this->assertEquals(5, (float) $saleDetails->json('data.data.0.PED_Descuento_Porcentaje'));
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

    public function test_transfer_payment_requires_payment_reference(): void
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
            'monto' => 100,
            'metodo' => 'TRANSFERENCIA',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['referencia'])
            ->assertJsonFragment(['referencia' => ['El campo referencia es obligatorio cuando método de pago sea TRANSFERENCIA.']]);

        $this->assertDatabaseCount('TEC_PAGO', 0);
    }

    public function test_sales_payment_options_return_active_methods_without_stale_cache(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        cache()->put('tec_metodos_pago', collect(), 300);

        $this->getJson('/api/ventas?con_opciones=1')
            ->assertOk()
            ->assertJsonCount(3, 'metodos_pago')
            ->assertJsonFragment(['MPA_Codigo' => 'EFECTIVO'])
            ->assertJsonFragment(['MPA_Codigo' => 'TARJETA'])
            ->assertJsonFragment(['MPA_Codigo' => 'TRANSFERENCIA']);
    }

    public function test_card_demo_payment_requires_and_records_only_demo_reference(): void
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
            'monto' => 100,
            'metodo' => 'TARJETA',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['referencia']);

        $this->postJson("/api/ventas/{$saleId}/pagos", [
            'monto' => 100,
            'metodo' => 'TARJETA',
            'referencia' => 'DEMO-12345',
        ])->assertCreated();

        $this->assertDatabaseHas('TEC_PAGO', [
            'VEN_Venta' => $saleId,
            'PAG_Referencia' => 'DEMO-12345',
        ]);
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
                'opciones' => ['estados_pago', 'categorias', 'proveedores'],
            ]);

        $this->getJson('/api/reportes?estado_pago=PAGADA&categoria_id=1&proveedor_id=1')
            ->assertOk()
            ->assertJsonPath('filtros.estado_pago', 'PAGADA');
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
            'telefono' => '55553333',
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

        $this->putJson("/api/catalogos/productos/{$id}", [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-HUB-001',
            'nombre' => 'Hub USB 4 puertos',
            'costo' => 11,
            'precio' => 19,
            'existencia_minima' => 4,
            'existencia' => 12,
        ])->assertOk();

        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $id,
            'INV_Existencia' => 12,
        ]);
    }

    public function test_editing_a_product_does_not_reactivate_it(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $category = DB::table('TEC_CATEGORIA')->value('CAT_Categoria');
        $brand = DB::table('TEC_MARCA')->value('MAR_Marca');

        $id = $this->postJson('/api/catalogos/productos', [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-OFF-001',
            'nombre' => 'Mouse inactivo',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 0,
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/catalogos/productos/{$id}")->assertOk();
        $this->assertDatabaseHas('TEC_PRODUCTO', [
            'PRO_Producto' => $id,
            'PRO_Estado' => false,
        ]);

        $this->putJson("/api/catalogos/productos/{$id}", [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-OFF-001',
            'nombre' => 'Mouse inactivo editado',
            'costo' => 12,
            'precio' => 20,
            'existencia_minima' => 0,
        ])->assertOk();

        $this->assertDatabaseHas('TEC_PRODUCTO', [
            'PRO_Producto' => $id,
            'PRO_Nombre' => 'Mouse inactivo editado',
            'PRO_Estado' => false,
        ]);
    }

    public function test_operator_cannot_change_stock_from_product_form(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $category = DB::table('TEC_CATEGORIA')->value('CAT_Categoria');
        $brand = DB::table('TEC_MARCA')->value('MAR_Marca');

        $id = $this->postJson('/api/catalogos/productos', [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-OP-001',
            'nombre' => 'Teclado operador',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 0,
        ])->assertCreated()->json('id');

        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($operator, 'sanctum');
        $this->putJson("/api/catalogos/productos/{$id}", [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-OP-001',
            'nombre' => 'Teclado operador',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 0,
            'existencia' => 40,
        ])->assertOk();

        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $id,
            'INV_Existencia' => 0,
        ]);
    }

    public function test_product_price_must_be_greater_than_cost_with_a_clear_validation_message(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $category = DB::table('TEC_CATEGORIA')->value('CAT_Categoria');
        $brand = DB::table('TEC_MARCA')->value('MAR_Marca');

        $this->postJson('/api/catalogos/productos', [
            'categoria_id' => $category,
            'marca_id' => $brand,
            'sku' => 'TEC-EQUAL-001',
            'nombre' => 'Producto con precio igual al costo',
            'costo' => 150,
            'precio' => 150,
            'existencia_minima' => 0,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.precio.0', 'El precio de venta debe ser mayor que el costo del producto.');

        $this->assertDatabaseMissing('TEC_PRODUCTO', ['PRO_SKU' => 'TEC-EQUAL-001']);
    }

    public function test_product_id_and_optional_photo_are_available_in_the_catalog(): void
    {
        Storage::fake('public');
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $id = $this->post('/api/catalogos/productos', [
            'categoria_id' => DB::table('TEC_CATEGORIA')->value('CAT_Categoria'),
            'marca_id' => DB::table('TEC_MARCA')->value('MAR_Marca'),
            'sku' => 'TEC-PHOTO-001',
            'nombre' => 'Producto con foto',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 0,
            'foto' => $this->jpegUpload('producto.jpg'),
        ])->assertCreated()->json('id');

        $photoPath = DB::table('TEC_PRODUCTO')->where('PRO_Producto', $id)->value('PRO_Foto');
        $this->assertNotEmpty($photoPath);
        Storage::disk('public')->assertExists($photoPath);

        $this->getJson('/api/catalogos/productos?buscar='.$id)
            ->assertOk()
            ->assertJsonFragment([
                'PRO_Producto' => $id,
                'PRO_Foto' => $photoPath,
                'PRO_Foto_URL' => url('storage/'.$photoPath),
            ]);

        $this->post('/api/catalogos/productos', [
            'categoria_id' => DB::table('TEC_CATEGORIA')->value('CAT_Categoria'),
            'marca_id' => DB::table('TEC_MARCA')->value('MAR_Marca'),
            'sku' => 'TEC-PHOTO-LARGE',
            'nombre' => 'Producto con foto grande',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 0,
            'foto' => $this->jpegUpload('grande.jpg', 3 * 1024),
        ])->assertUnprocessable()
            ->assertJsonPath('errors.foto.0', 'La foto no puede superar los 2 MB.');

        $this->assertDatabaseMissing('TEC_PRODUCTO', ['PRO_SKU' => 'TEC-PHOTO-LARGE']);
    }

    public function test_product_catalog_defaults_to_id_order_and_filters_by_category(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $categories = DB::table('TEC_CATEGORIA')->orderBy('CAT_Categoria')->pluck('CAT_Categoria');
        $brand = DB::table('TEC_MARCA')->value('MAR_Marca');

        $first = $this->postJson('/api/catalogos/productos', [
            'categoria_id' => $categories[0],
            'marca_id' => $brand,
            'sku' => 'TEC-ORD-Z',
            'nombre' => 'Zeta orden',
            'costo' => 10,
            'precio' => 20,
            'existencia_minima' => 1,
        ])->assertCreated()->json('id');

        $second = $this->postJson('/api/catalogos/productos', [
            'categoria_id' => $categories[1],
            'marca_id' => $brand,
            'sku' => 'TEC-ORD-A',
            'nombre' => 'Alfa orden',
            'costo' => 10,
            'precio' => 20,
            'existencia_minima' => 1,
        ])->assertCreated()->json('id');

        $ids = collect($this->getJson('/api/catalogos/productos?por_pagina=100')->assertOk()->json('data'))
            ->pluck('PRO_Producto')
            ->all();
        $this->assertLessThan(array_search($second, $ids, true), array_search($first, $ids, true));

        $names = collect($this->getJson('/api/catalogos/productos?orden=nombre&direccion=desc&por_pagina=100')->assertOk()->json('data'))
            ->pluck('PRO_Nombre')
            ->all();
        $this->assertLessThan(array_search('Alfa orden', $names, true), array_search('Zeta orden', $names, true));

        $filtered = collect($this->getJson('/api/catalogos/productos?categoria_id='.$categories[1].'&por_pagina=100')->assertOk()->json('data'))
            ->pluck('PRO_Producto')
            ->all();
        $this->assertContains($second, $filtered);
        $this->assertNotContains($first, $filtered);
    }

    public function test_product_photo_can_be_removed_when_editing(): void
    {
        Storage::fake('public');
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $id = $this->postJson('/api/catalogos/productos', [
            'categoria_id' => DB::table('TEC_CATEGORIA')->value('CAT_Categoria'),
            'marca_id' => DB::table('TEC_MARCA')->value('MAR_Marca'),
            'sku' => 'TEC-PHOTO-DEL',
            'nombre' => 'Producto para quitar foto',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 0,
        ])->assertCreated()->json('id');

        Storage::disk('public')->put('products/old.jpg', 'foto');
        DB::table('TEC_PRODUCTO')->where('PRO_Producto', $id)->update(['PRO_Foto' => 'products/old.jpg']);

        $this->post("/api/catalogos/productos/{$id}", [
            '_method' => 'PUT',
            'categoria_id' => DB::table('TEC_CATEGORIA')->value('CAT_Categoria'),
            'marca_id' => DB::table('TEC_MARCA')->value('MAR_Marca'),
            'sku' => 'TEC-PHOTO-DEL',
            'nombre' => 'Producto para quitar foto',
            'costo' => 10,
            'precio' => 18,
            'existencia_minima' => 0,
            'existencia' => 0,
            'quitar_foto' => 1,
        ])->assertOk();

        $this->assertNull(DB::table('TEC_PRODUCTO')->where('PRO_Producto', $id)->value('PRO_Foto'));
        Storage::disk('public')->assertMissing('products/old.jpg');
    }

    public function test_demo_data_seeder_is_repeatable_and_keeps_existing_roles_unchanged(): void
    {
        $roleIds = DB::table('TEC_ROL')->orderBy('ROL_Rol')->pluck('ROL_Nombre', 'ROL_Rol')->all();
        $countsBefore = [
            'clientes' => DB::table('TEC_CLIENTE')->count(),
            'categorias' => DB::table('TEC_CATEGORIA')->count(),
            'marcas' => DB::table('TEC_MARCA')->count(),
            'productos' => DB::table('TEC_PRODUCTO')->count(),
            'usuarios' => DB::table('TEC_USUARIO')->count(),
        ];

        $this->seed(DemoDataSeeder::class);
        $countsAfterFirstRun = [
            'clientes' => DB::table('TEC_CLIENTE')->count(),
            'categorias' => DB::table('TEC_CATEGORIA')->count(),
            'marcas' => DB::table('TEC_MARCA')->count(),
            'productos' => DB::table('TEC_PRODUCTO')->count(),
            'usuarios' => DB::table('TEC_USUARIO')->count(),
        ];
        $this->seed(DemoDataSeeder::class);

        $this->assertSame($roleIds, DB::table('TEC_ROL')->orderBy('ROL_Rol')->pluck('ROL_Nombre', 'ROL_Rol')->all());
        $this->assertSame($countsAfterFirstRun, [
            'clientes' => DB::table('TEC_CLIENTE')->count(),
            'categorias' => DB::table('TEC_CATEGORIA')->count(),
            'marcas' => DB::table('TEC_MARCA')->count(),
            'productos' => DB::table('TEC_PRODUCTO')->count(),
            'usuarios' => DB::table('TEC_USUARIO')->count(),
        ]);
        $this->assertSame(12, $countsAfterFirstRun['clientes'] - $countsBefore['clientes']);
        $this->assertSame(3, $countsAfterFirstRun['categorias'] - $countsBefore['categorias']);
        $this->assertSame(5, $countsAfterFirstRun['marcas'] - $countsBefore['marcas']);
        $this->assertSame(15, $countsAfterFirstRun['productos'] - $countsBefore['productos']);
        $this->assertSame(6, $countsAfterFirstRun['usuarios'] - $countsBefore['usuarios']);
        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => DB::table('TEC_PRODUCTO')->where('PRO_SKU', 'DEMO-AUD-001')->value('PRO_Producto'),
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

        foreach (['ventas', 'productos', 'inventario', 'compras'] as $tipo) {
            $pdf = $this->get("/api/reportes/{$tipo}.pdf")
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');

            $this->assertStringStartsWith('%PDF-', $pdf->getContent());

            $withChart = $this->get("/api/reportes/{$tipo}.pdf?grafica=1")
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $withChart->getContent());
        }
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
        ])->assertUnprocessable()->assertJson([
            'message' => 'Inténtelo más tarde.',
        ]);

        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $this->patchJson("/api/usuarios/{$operator->USU_Usuario}/desbloquear")->assertOk();

        $this->postJson('/api/login', [
            'correo' => 'operador@techstore.test',
            'contrasena' => 'TechStore2026!',
        ])->assertOk();
    }

    public function test_receiving_a_purchase_is_atomic_and_receipts_can_be_searched_and_viewed(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $provider = DB::table('TEC_PROVEEDOR')->value('PRV_Proveedor');
        $product = DB::table('TEC_PRODUCTO')->value('PRO_Producto');
        $sku = DB::table('TEC_PRODUCTO')->where('PRO_Producto', $product)->value('PRO_SKU');
        $initialStock = (int) DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product)->value('INV_Existencia');

        $purchaseId = $this->postJson('/api/compras', [
        'proveedor_id' => $provider,
        'detalles' => [[
            'producto_id' => $product,
            'cantidad' => 4,
            'costo_unitario' => 12,
        ]],
        ])->assertCreated()->json('id');

        $receiptId = $this->postJson("/api/compras/{$purchaseId}/recibir")
        ->assertOk()
        ->json('id');

        $this->postJson("/api/compras/{$purchaseId}/recibir")
        ->assertStatus(409);

        $this->assertDatabaseHas('TEC_INVENTARIO', [
        'PRO_Producto' => $product,
        'INV_Existencia' => $initialStock + 4,
        ]);
        $this->assertDatabaseCount('TEC_RECEPCION', 1);

        $this->getJson("/api/recepciones?buscar={$sku}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.REC_Recepcion', $receiptId)
        ->assertJsonPath('data.0.COM_Compra', $purchaseId);

        $this->getJson("/api/recepciones/{$receiptId}")
        ->assertOk()
        ->assertJsonPath('REC_Recepcion', $receiptId)
        ->assertJsonPath('COM_Compra', $purchaseId)
        ->assertJsonPath('detalles.0.PRO_SKU', $sku)
        ->assertJsonPath('detalles.0.DCO_Cantidad', 4);
    }

    public function test_damaged_units_stay_out_of_stock_and_open_an_incident(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $product = DB::table('TEC_PRODUCTO')->value('PRO_Producto');
        $initialStock = (int) DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product)->value('INV_Existencia');
        $purchaseId = $this->postJson('/api/compras', [
            'proveedor_id' => DB::table('TEC_PROVEEDOR')->value('PRV_Proveedor'),
            'detalles' => [[
                'producto_id' => $product,
                'cantidad' => 6,
                'costo_unitario' => 10,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/compras/{$purchaseId}/recibir", [
            'lineas' => [[
                'producto_id' => $product,
                'aceptada' => 4,
                'danada' => 2,
                'faltante' => 0,
                'motivo' => 'Empaque roto',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => $initialStock + 4,
        ]);
        $this->assertDatabaseHas('TEC_INCIDENCIA', [
            'COM_Compra' => $purchaseId,
            'INC_Cantidad' => 2,
            'INC_Estado' => 'PENDIENTE',
        ]);
        $this->assertDatabaseHas('TEC_COMPRA', [
            'COM_Compra' => $purchaseId,
            'ESC_Estado_Compra' => Catalogos::estadoCompra('RECIBIDA'),
        ]);
    }

    public function test_missing_units_keep_the_purchase_pending_until_a_later_receipt(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $product = DB::table('TEC_PRODUCTO')->value('PRO_Producto');
        $initialStock = (int) DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product)->value('INV_Existencia');
        $purchaseId = $this->postJson('/api/compras', [
            'proveedor_id' => DB::table('TEC_PROVEEDOR')->value('PRV_Proveedor'),
            'detalles' => [[
                'producto_id' => $product,
                'cantidad' => 5,
                'costo_unitario' => 8,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/compras/{$purchaseId}/recibir", [
            'lineas' => [[
                'producto_id' => $product,
                'aceptada' => 3,
                'danada' => 0,
                'faltante' => 2,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => $initialStock + 3,
        ]);
        $this->assertDatabaseHas('TEC_COMPRA', [
            'COM_Compra' => $purchaseId,
            'ESC_Estado_Compra' => Catalogos::estadoCompra('PARCIAL'),
        ]);

        $this->postJson("/api/compras/{$purchaseId}/recibir")->assertOk();
        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => $initialStock + 5,
        ]);
        $this->assertDatabaseHas('TEC_COMPRA', [
            'COM_Compra' => $purchaseId,
            'ESC_Estado_Compra' => Catalogos::estadoCompra('RECIBIDA'),
        ]);
    }

    public function test_indivisible_lot_with_damage_does_not_change_inventory(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $product = DB::table('TEC_PRODUCTO')->value('PRO_Producto');
        $initialStock = (int) DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product)->value('INV_Existencia');
        $purchaseId = $this->postJson('/api/compras', [
            'proveedor_id' => DB::table('TEC_PROVEEDOR')->value('PRV_Proveedor'),
            'detalles' => [[
                'producto_id' => $product,
                'cantidad' => 4,
                'costo_unitario' => 9,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/compras/{$purchaseId}/recibir", [
            'lote_indivisible' => true,
            'lineas' => [[
                'producto_id' => $product,
                'aceptada' => 3,
                'danada' => 1,
                'faltante' => 0,
                'motivo' => 'Golpe',
            ]],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => $initialStock,
        ]);
        $this->assertDatabaseMissing('TEC_RECEPCION', ['COM_Compra' => $purchaseId]);
        $this->assertDatabaseCount('TEC_INCIDENCIA', 0);
    }

    public function test_replacing_damaged_units_increases_stock_once(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $product = DB::table('TEC_PRODUCTO')->value('PRO_Producto');
        $initialStock = (int) DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product)->value('INV_Existencia');
        $purchaseId = $this->postJson('/api/compras', [
            'proveedor_id' => DB::table('TEC_PROVEEDOR')->value('PRV_Proveedor'),
            'detalles' => [[
                'producto_id' => $product,
                'cantidad' => 2,
                'costo_unitario' => 11,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/compras/{$purchaseId}/recibir", [
            'lineas' => [[
                'producto_id' => $product,
                'aceptada' => 0,
                'danada' => 2,
                'faltante' => 0,
                'motivo' => 'No encienden',
            ]],
        ])->assertOk();

        $incidentId = DB::table('TEC_INCIDENCIA')->where('COM_Compra', $purchaseId)->value('INC_Incidencia');
        $this->postJson("/api/incidencias/{$incidentId}/resolver", [
            'gestion' => 'REEMPLAZO',
            'resolucion' => 'El proveedor envió unidades nuevas.',
        ])->assertOk();

        $this->assertDatabaseHas('TEC_INVENTARIO', [
            'PRO_Producto' => $product,
            'INV_Existencia' => $initialStock + 2,
        ]);
        $this->assertDatabaseHas('TEC_INCIDENCIA', [
            'INC_Incidencia' => $incidentId,
            'INC_Estado' => 'REEMPLAZO',
        ]);
        $this->postJson("/api/incidencias/{$incidentId}/resolver", [
            'gestion' => 'DEVOLUCION',
            'resolucion' => 'Intento duplicado',
        ])->assertStatus(409);
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

    public function test_admin_can_update_user_name_without_changing_password(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $operator = User::where('USU_Correo', 'operador@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $this->putJson("/api/usuarios/{$operator->USU_Usuario}", [
            'rol_id' => $operator->ROL_Rol,
            'primer_nombre' => 'Oscar',
            'primer_apellido' => 'Operador',
            'correo' => 'operador@techstore.test',
        ])->assertOk();

        $this->assertDatabaseHas('TEC_USUARIO', [
            'USU_Usuario' => $operator->USU_Usuario,
            'USU_Primer_Nombre' => 'Oscar',
        ]);
        $this->assertTrue(
            Hash::check('TechStore2026!', $operator->fresh()->USU_Contrasena)
        );
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

    public function test_consult_permission_allows_read_but_not_write(): void
    {
        $consultId = DB::table('TEC_PERMISO')->where('PER_Codigo', 'CLIENTES.CONSULTAR')->value('PER_Permiso');
        $roleId = DB::table('TEC_ROL')->insertGetId([
            'ROL_Nombre' => 'SOLO_LECTURA',
            'ROL_Estado' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'ROL_Rol');
        DB::table('TEC_ROL_PERMISO')->insert([
            'ROL_Rol' => $roleId,
            'PER_Permiso' => $consultId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::create([
            'ROL_Rol' => $roleId,
            'USU_Primer_Nombre' => 'Lector',
            'USU_Primer_Apellido' => 'Catalogo',
            'USU_Correo' => 'lector@techstore.test',
            'USU_Contrasena' => 'Password2026!',
            'USU_Estado' => true,
        ]);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/catalogos/clientes')->assertOk();
        $this->getJson('/api/catalogos/productos')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->postJson('/api/catalogos/clientes', [
            'identificacion' => 'DPI-333444',
            'primer_nombre' => 'Mario',
            'primer_apellido' => 'Lector',
        ])->assertForbidden();
    }

    public function test_category_can_be_inactivated(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $id = $this->postJson('/api/catalogos/categorias', [
            'nombre' => 'Accesorios',
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/catalogos/categorias/{$id}")->assertOk();
        $this->assertDatabaseHas('TEC_CATEGORIA', [
            'CAT_Categoria' => $id,
            'CAT_Estado' => false,
        ]);

        $this->patchJson("/api/catalogos/categorias/{$id}/estado")->assertOk();
        $this->assertDatabaseHas('TEC_CATEGORIA', [
            'CAT_Categoria' => $id,
            'CAT_Estado' => true,
        ]);
    }

    public function test_category_with_products_cannot_be_inactivated(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $category = DB::table('TEC_CATEGORIA')->value('CAT_Categoria');

        $this->deleteJson("/api/catalogos/categorias/{$category}")
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'No se puede inactivar esta categoría porque tiene productos asociados. Reasigne primero los productos a otra categoría.',
            ]);
        $this->assertDatabaseHas('TEC_CATEGORIA', [
            'CAT_Categoria' => $category,
            'CAT_Estado' => true,
        ]);
    }

    public function test_catalog_validation_errors_are_friendly_and_in_spanish(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/catalogos/productos', [
            'categoria_id' => 'invalida',
            'sku' => 'SKU CON ESPACIOS',
            'nombre' => '',
            'costo' => 'texto',
            'precio' => 0,
            'existencia_minima' => -1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['categoria_id', 'marca_id', 'sku', 'nombre', 'costo', 'precio'])
            ->assertJsonFragment(['categoria_id' => ['El campo categoría debe ser un número entero.']])
            ->assertJsonFragment(['nombre' => ['El campo nombre es obligatorio.']]);

        $this->postJson('/api/catalogos/proveedores', [
            'identificacion_fiscal' => 'NIT-INVALIDO',
            'razon_social' => 'Proveedor válido',
            'correo' => 'correo-invalido',
        ])
            ->assertUnprocessable()
            ->assertJsonFragment(['correo' => ['El correo debe tener un formato válido, por ejemplo nombre@dominio.com.']]);
    }

    public function test_person_name_rejects_symbols(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $role = DB::table('TEC_ROL')->where('ROL_Nombre', 'OPERADOR')->value('ROL_Rol');

        $this->postJson('/api/usuarios', [
            'rol_id' => $role,
            'primer_nombre' => 'Juan@',
            'primer_apellido' => 'Pérez',
            'correo' => 'juan.simbolos@techstore.test',
            'contrasena' => 'Password2026!',
        ])->assertUnprocessable();

        $this->postJson('/api/catalogos/clientes', [
            'identificacion' => 'DPI-111222',
            'primer_nombre' => 'Ana#',
            'primer_apellido' => 'Lopez',
        ])->assertUnprocessable();
    }

    public function test_phone_cannot_exceed_eight_characters(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $this->postJson('/api/catalogos/clientes', [
            'identificacion' => 'DPI-333444',
            'primer_nombre' => 'Laura',
            'primer_apellido' => 'Méndez',
            'telefono' => '555533339',
        ])->assertUnprocessable();

        $this->postJson('/api/catalogos/proveedores', [
            'identificacion_fiscal' => 'NIT-PHONE-001',
            'razon_social' => 'Proveedor Demo',
            'telefono' => '5555-3333',
        ])->assertUnprocessable();
    }

    public function test_provider_catalog_searches_contact_details_and_filters_by_state(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $providerId = DB::table('TEC_PROVEEDOR')->value('PRV_Proveedor');

        $this->getJson('/api/catalogos/proveedores?buscar=Digital&con_opciones=1')
            ->assertOk()
            ->assertJsonPath('data.0.PRV_Identificacion_Fiscal', 'NIT-TECH-001')
            ->assertJsonPath('data.0.PRV_Razon_Social', 'Distribuidora Digital, S. A.')
            ->assertJsonPath('data.0.PRV_Contacto', 'María Proveedora')
            ->assertJsonPath('data.0.PRV_Correo', 'ventas@distribuidora.test')
            ->assertJsonPath('data.0.PRV_Telefono', '55550202')
            ->assertJsonPath('opciones.0.PRV_Razon_Social', 'Distribuidora Digital, S. A.');

        DB::table('TEC_PROVEEDOR')->where('PRV_Proveedor', $providerId)->update(['PRV_Estado' => false]);

        $this->getJson('/api/catalogos/proveedores?estado=activos')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/catalogos/proveedores?estado=inactivos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.PRV_Estado', 0);
    }

    public function test_email_must_follow_valid_structure(): void
    {
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');
        $role = DB::table('TEC_ROL')->where('ROL_Nombre', 'OPERADOR')->value('ROL_Rol');

        $this->postJson('/api/catalogos/clientes', [
            'identificacion' => 'DPI-555666',
            'primer_nombre' => 'Laura',
            'primer_apellido' => 'Méndez',
            'correo' => 'correo-invalido',
        ])->assertUnprocessable();

        $this->postJson('/api/usuarios', [
            'rol_id' => $role,
            'primer_nombre' => 'Juan',
            'primer_apellido' => 'Pérez',
            'correo' => 'juan.sin-dominio',
            'contrasena' => 'Password2026!',
        ])->assertUnprocessable();
    }

    public function test_role_permissions_are_limited_to_current_sprint(): void
    {
        config(['entrega.actual' => 1]);
        $admin = User::where('USU_Correo', 'admin@techstore.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/roles?con_permisos=1')
            ->assertOk()
            ->assertJsonFragment(['PER_Codigo' => 'CLIENTES.CONSULTAR'])
            ->assertJsonFragment(['PER_Codigo' => 'CLIENTES.GESTIONAR'])
            ->assertJsonMissing(['PER_Codigo' => 'REPORTES.CONSULTAR']);

        $userPermission = DB::table('TEC_PERMISO')->where('PER_Codigo', 'USUARIOS.GESTIONAR')->value('PER_Permiso');
        $reportPermission = DB::table('TEC_PERMISO')->where('PER_Codigo', 'REPORTES.CONSULTAR')->value('PER_Permiso');
        $adminRole = DB::table('TEC_ROL')->where('ROL_Nombre', 'ADMINISTRADOR')->value('ROL_Rol');

        $this->postJson('/api/roles', [
            'nombre' => 'REPORTERO',
            'permisos' => [$reportPermission],
        ])->assertUnprocessable();

        $this->putJson("/api/roles/{$adminRole}", [
            'nombre' => 'ADMINISTRADOR',
            'permisos' => [$userPermission],
        ])->assertOk();

        $this->assertDatabaseHas('TEC_ROL_PERMISO', [
            'ROL_Rol' => $adminRole,
            'PER_Permiso' => $reportPermission,
        ]);
        $this->assertDatabaseHas('TEC_ROL_PERMISO', [
            'ROL_Rol' => $adminRole,
            'PER_Permiso' => $userPermission,
        ]);
    }

    private function jpegUpload(string $filename, int $kilobytes = 8): UploadedFile
    {
        $jpeg = base64_decode(''
            .'/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEB'
            .'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEB'
            .'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDAREA'
            .'AhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMB'
            .'AAIQAxAAAAGf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPwB//9k=');
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('techstore_', true).'.jpg';
        file_put_contents($path, $jpeg.str_repeat("\0", max(0, ($kilobytes * 1024) - strlen($jpeg))));

        return new UploadedFile($path, $filename, 'image/jpeg', null, true);
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
