<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Catalogos;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    private array $products;

    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('USU_Correo', 'admin@techstore.test')->firstOrFail(), 'sanctum');
        $this->client = DB::table('TEC_CLIENTE')->value('CLI_Cliente');
        $this->products = DB::table('TEC_PRODUCTO')->orderBy('PRO_Producto')->limit(3)->pluck('PRO_Producto')->all();
        foreach ($this->products as $index => $product) {
            DB::table('TEC_PRODUCTO')->where('PRO_Producto', $product)->update(['PRO_Precio' => [225, 155, 100][$index]]);
            DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product)->update(['INV_Existencia' => 20]);
        }
    }

    public function test_discount_applies_only_to_its_product_and_survives_sale_and_invoice(): void
    {
        $id = $this->createOrder([0, 11], [1, 10]);
        $this->assertDatabaseHas('TEC_PEDIDO', [
            'PED_Pedido' => $id,
            'PED_Subtotal' => 1775,
            'PED_Descuento_Porcentaje' => 0,
            'PED_Descuento_Monto' => 170.50,
            'PED_Total' => 1604.50,
        ]);
        $details = $this->details($id);
        $this->assertEquals(0, $details[0]->DPE_Descuento_Monto);
        $this->assertEquals(170.50, $details[1]->DPE_Descuento_Monto);
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->assertDatabaseCount('TEC_APROBACION', 0);
        $this->assertDatabaseCount('TEC_APROBACION_PRODUCTO', 1);
        $this->assertDatabaseMissing('TEC_APROBACION_PRODUCTO', ['DPE_Detalle_Pedido' => $details[0]->DPE_Detalle_Pedido]);
        $this->postJson("/api/pedidos/{$id}/vender")->assertConflict();
        $this->decide($id, $details[1]->DPE_Detalle_Pedido, 'APROBAR')->assertOk()->assertJsonPath('estado', 'APROBADO');
        foreach ($this->products as $product) {
            $this->assertDatabaseHas('TEC_INVENTARIO', ['PRO_Producto' => $product, 'INV_Existencia' => 20]);
        }
        $sale = $this->postJson("/api/pedidos/{$id}/vender")->assertCreated()->json('id');
        $this->assertDatabaseHas('TEC_DETALLE_VENTA', ['VEN_Venta' => $sale, 'PRO_Producto' => $this->products[0], 'DVE_Descuento_Monto' => 0]);
        $this->assertDatabaseHas('TEC_DETALLE_VENTA', ['VEN_Venta' => $sale, 'PRO_Producto' => $this->products[1], 'DVE_Descuento_Monto' => 170.50]);
        $this->assertDatabaseHas('TEC_INVENTARIO', ['PRO_Producto' => $this->products[1], 'INV_Existencia' => 10]);
        $this->postJson("/api/ventas/{$sale}/pagos", ['monto' => 1604.50, 'metodo' => 'EFECTIVO'])->assertCreated();
        $snapshot = json_decode(DB::table('TEC_FACTURA')->where('VEN_Venta', $sale)->value('FAC_Datos'), true, 512, JSON_THROW_ON_ERROR);
        $lines = collect($snapshot['detalles'])->keyBy('DPE_Precio_Unitario');
        $this->assertEquals(170.50, $lines[155]['DPE_Descuento_Monto']);
        $this->assertEquals(0, $lines[225]['DPE_Descuento_Monto']);
        $this->assertEquals(1604.50, $snapshot['total']);
        $this->get("/api/ventas/{$sale}/factura")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", ['detalle_id' => $details[1]->DPE_Detalle_Pedido, 'descuento_porcentaje' => 0])->assertConflict();
    }

    public function test_each_exceptional_product_discount_requires_its_own_decision(): void
    {
        $id = $this->createOrder([11, 15, 0]);
        $details = $this->details($id);
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->assertDatabaseCount('TEC_APROBACION_PRODUCTO', 2);
        $this->decide($id, null, 'APROBAR')->assertConflict();
        $this->decide($id, $details[2]->DPE_Detalle_Pedido, 'APROBAR')->assertConflict();
        $this->decide($id, $details[0]->DPE_Detalle_Pedido, 'APROBAR')->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->postJson("/api/pedidos/{$id}/vender")->assertConflict();
        $this->decide($id, $details[0]->DPE_Detalle_Pedido, 'RECHAZAR')->assertConflict();
        $this->decide($id, $details[1]->DPE_Detalle_Pedido, 'APROBAR')->assertOk()->assertJsonPath('estado', 'APROBADO');
        $this->getJson("/api/pedidos?buscar={$id}")->assertOk()->assertJsonCount(3, 'data.0.detalles')
            ->assertJsonFragment(['APR_Estado' => 'APROBADA']);
    }

    public function test_rejected_discount_can_be_removed_without_affecting_other_products(): void
    {
        $id = $this->createOrder([11, 15, 0]);
        $details = $this->details($id);
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk();
        $this->decide($id, $details[0]->DPE_Detalle_Pedido, 'APROBAR')->assertOk();
        $this->decide($id, $details[1]->DPE_Detalle_Pedido, 'RECHAZAR')->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->assertDatabaseHas('TEC_APROBACION_PRODUCTO', ['DPE_Detalle_Pedido' => $details[1]->DPE_Detalle_Pedido, 'APR_Estado' => 'RECHAZADA']);
        $this->postJson("/api/pedidos/{$id}/vender")->assertConflict();
        $this->getJson("/api/pedidos?buscar={$id}")->assertOk()->assertJsonFragment(['APR_Estado' => 'RECHAZADA', 'APR_Motivo' => 'Decision comercial']);
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", [
            'detalle_id' => $details[1]->DPE_Detalle_Pedido,
            'descuento_porcentaje' => 0,
        ])->assertOk()->assertJsonPath('estado', 'APROBADO');
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $id, 'PED_Total' => 455.25, 'PED_Descuento_Monto' => 24.75]);
        $this->assertDatabaseHas('TEC_DETALLE_PEDIDO', ['DPE_Detalle_Pedido' => $details[0]->DPE_Detalle_Pedido, 'DPE_Descuento_Porcentaje' => 11]);
        $this->assertDatabaseHas('TEC_DETALLE_PEDIDO', ['DPE_Detalle_Pedido' => $details[1]->DPE_Detalle_Pedido, 'DPE_Descuento_Porcentaje' => 0]);
        $this->assertDatabaseHas('TEC_HISTORIAL_ESTADO', ['PED_Pedido' => $id, 'HES_Motivo' => 'Producto del pedido #'.$details[1]->DPE_Detalle_Pedido.': Decision comercial']);
        $this->assertDatabaseHas('TEC_AUDITORIA', ['AUD_Modulo' => 'PEDIDOS', 'AUD_Accion' => 'AJUSTAR_DESCUENTO']);
    }

    public function test_replacement_above_limit_requires_new_individual_approval(): void
    {
        $id = $this->createOrder([20, 5]);
        $detail = $this->details($id)[0];
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk();
        $this->decide($id, $detail->DPE_Detalle_Pedido, 'RECHAZAR')->assertOk();
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", [
            'detalle_id' => $detail->DPE_Detalle_Pedido,
            'descuento_porcentaje' => 15,
        ])->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->assertDatabaseHas('TEC_APROBACION_PRODUCTO', ['DPE_Detalle_Pedido' => $detail->DPE_Detalle_Pedido, 'APR_Estado' => 'PENDIENTE', 'APR_Motivo' => null]);
        $this->postJson("/api/pedidos/{$id}/vender")->assertConflict();
        $this->decide($id, $detail->DPE_Detalle_Pedido, 'APROBAR')->assertOk()->assertJsonPath('estado', 'APROBADO');
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $id, 'PED_Total' => 338.50, 'PED_Descuento_Monto' => 41.50]);
    }

    public function test_replacement_at_limit_is_automatic_and_cannot_change_other_discounts(): void
    {
        $id = $this->createOrder([20, 15]);
        $details = $this->details($id);
        $other = $this->createOrder([15]);
        $foreign = $this->details($other)[0]->DPE_Detalle_Pedido;
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk();
        $this->postJson("/api/pedidos/{$other}/avanzar")->assertOk();
        $this->decide($id, $foreign, 'RECHAZAR')->assertConflict();
        $this->decide($id, $details[0]->DPE_Detalle_Pedido, 'RECHAZAR')->assertOk();
        foreach ([$foreign, $details[1]->DPE_Detalle_Pedido] as $invalid) {
            $this->postJson("/api/pedidos/{$id}/ajustar-descuento", ['detalle_id' => $invalid, 'descuento_porcentaje' => 0])->assertConflict();
        }
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", ['detalle_id' => $details[0]->DPE_Detalle_Pedido, 'descuento_porcentaje' => 10])
            ->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->assertDatabaseHas('TEC_APROBACION_PRODUCTO', ['DPE_Detalle_Pedido' => $details[0]->DPE_Detalle_Pedido, 'APR_Estado' => 'APROBADA']);
        $this->decide($id, $details[1]->DPE_Detalle_Pedido, 'APROBAR')->assertOk()->assertJsonPath('estado', 'APROBADO');
    }

    public function test_general_discount_is_exclusive_and_uses_existing_approval_flow(): void
    {
        $this->postJson('/api/pedidos', [
            'cliente_id' => $this->client,
            'descuento_porcentaje' => 5,
            'detalles' => [['producto_id' => $this->products[0], 'cantidad' => 1, 'descuento_porcentaje' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('descuento_porcentaje');
        $this->assertDatabaseCount('TEC_PEDIDO', 0);
        $id = $this->createOrder([0, 0], [1, 1], 11);
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $id, 'PED_Subtotal' => 380, 'PED_Descuento_Monto' => 41.80, 'PED_Total' => 338.20]);
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->assertDatabaseCount('TEC_APROBACION_PRODUCTO', 0);
        $this->assertDatabaseCount('TEC_APROBACION', 1);
        $this->decide($id, null, 'RECHAZAR')->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", ['descuento_porcentaje' => 15])
            ->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->decide($id, null, 'RECHAZAR')->assertOk();
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", ['descuento_porcentaje' => 0])
            ->assertOk()->assertJsonPath('estado', 'APROBADO');
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $id, 'PED_Descuento_Porcentaje' => 0, 'PED_Total' => 380]);
    }

    public function test_product_discount_limits_and_rounding(): void
    {
        foreach ([-1, 100.01, 10.555, 'abc'] as $percentage) {
            $this->postJson('/api/pedidos', [
                'cliente_id' => $this->client,
                'detalles' => [['producto_id' => $this->products[0], 'cantidad' => 1, 'descuento_porcentaje' => $percentage]],
            ])->assertUnprocessable()->assertJsonValidationErrors('detalles.0.descuento_porcentaje');
        }
        $id = $this->createOrder([0.29, 10]);
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk()->assertJsonPath('estado', 'APROBADO');
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $id, 'PED_Descuento_Monto' => 16.15, 'PED_Total' => 363.85]);
        $this->assertDatabaseCount('TEC_APROBACION_PRODUCTO', 0);
        $free = $this->createOrder([100, 0]);
        $this->postJson("/api/pedidos/{$free}/avanzar")->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $free, 'PED_Total' => 155]);
    }

    public function test_cancelled_order_cannot_accept_decisions_or_corrections(): void
    {
        $id = $this->createOrder([11]);
        $detail = $this->details($id)[0]->DPE_Detalle_Pedido;
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk();
        $this->decide($id, $detail, 'RECHAZAR')->assertOk();
        $this->postJson("/api/pedidos/{$id}/cancelar", ['motivo' => 'Cancelado por cliente'])->assertOk();
        $this->assertDatabaseHas('TEC_APROBACION_PRODUCTO', ['DPE_Detalle_Pedido' => $detail, 'APR_Estado' => 'CANCELADA']);
        $this->decide($id, $detail, 'APROBAR')->assertConflict();
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", ['detalle_id' => $detail, 'descuento_porcentaje' => 0])->assertConflict();
        $this->assertDatabaseHas('TEC_PEDIDO', ['PED_Pedido' => $id, 'ESP_Estado_Pedido' => Catalogos::estadoPedido('CANCELADO')]);
    }

    public function test_operator_can_correct_rejection_but_cannot_approve_it(): void
    {
        $id = $this->createOrder([11]);
        $detail = $this->details($id)[0]->DPE_Detalle_Pedido;
        $this->postJson("/api/pedidos/{$id}/avanzar")->assertOk();
        $this->decide($id, $detail, 'RECHAZAR')->assertOk();
        $this->actingAs(User::where('USU_Correo', 'operador@techstore.test')->firstOrFail(), 'sanctum');
        $this->decide($id, $detail, 'APROBAR')->assertForbidden();
        $this->postJson("/api/pedidos/{$id}/ajustar-descuento", ['detalle_id' => $detail, 'descuento_porcentaje' => 5])
            ->assertOk()->assertJsonPath('estado', 'APROBADO');
    }

    private function createOrder(array $percentages, array $quantities = [], float $general = 0): int
    {
        $details = [];
        foreach ($percentages as $index => $percentage) {
            $details[] = [
                'producto_id' => $this->products[$index],
                'cantidad' => $quantities[$index] ?? 1,
                'descuento_porcentaje' => $percentage,
            ];
        }

        return $this->postJson('/api/pedidos', [
            'cliente_id' => $this->client,
            'descuento_porcentaje' => $general,
            'detalles' => $details,
        ])->assertCreated()->json('id');
    }

    private function details(int $id): Collection
    {
        return DB::table('TEC_DETALLE_PEDIDO')->where('PED_Pedido', $id)->orderBy('PRO_Producto')->get();
    }

    private function decide(int $id, ?int $detail, string $decision): TestResponse
    {
        return $this->postJson("/api/pedidos/{$id}/decidir", [
            'detalle_id' => $detail,
            'decision' => $decision,
            'motivo' => 'Decision comercial',
        ]);
    }
}
