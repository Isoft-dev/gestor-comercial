<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('USU_Correo', 'admin@techstore.test')->firstOrFail(), 'sanctum');
    }

    public function test_fiscal_fields_are_optional_but_contact_is_required(): void
    {
        $client = [
            'identificacion' => '2514254140199',
            'primer_nombre' => 'Ana',
            'primer_apellido' => 'Lopez',
        ];
        $this->postJson('/api/catalogos/clientes', $client)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['correo', 'telefono'])
            ->assertJsonMissingValidationErrors(['nit', 'razon_social', 'direccion_fiscal']);

        $client += ['correo' => 'ana@example.com', 'telefono' => '55551234'];
        $this->postJson('/api/catalogos/clientes', $client + ['nit' => 'INVALIDO'])
            ->assertUnprocessable()->assertJsonValidationErrors('nit');
        $id = $this->postJson('/api/catalogos/clientes', $client + ['nit' => 'cf', 'direccion_fiscal' => 'Zona 1'])->assertCreated()->json('id');
        $this->assertDatabaseHas('TEC_CLIENTE', ['CLI_Cliente' => $id, 'CLI_NIT' => 'CF', 'CLI_Direccion_Fiscal' => 'Zona 1', 'CLI_Razon_Social' => null]);
    }

    public function test_multiple_lines_discount_approval_and_immutable_invoice_pdf(): void
    {
        $products = DB::table('TEC_PRODUCTO')->limit(2)->get();
        foreach ($products as $product) {
            DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product->PRO_Producto)->update(['INV_Existencia' => 10]);
        }
        $clientId = DB::table('TEC_CLIENTE')->value('CLI_Cliente');
        $order = $this->postJson('/api/pedidos', [
            'cliente_id' => $clientId,
            'descuento_porcentaje' => 11,
            'detalles' => $products->map(fn ($product) => ['producto_id' => $product->PRO_Producto, 'cantidad' => 2])->all(),
        ])->assertCreated()->json('id');
        $this->getJson("/api/pedidos?buscar={$order}")->assertOk()->assertJsonCount(2, 'data.0.detalles');
        $this->postJson("/api/pedidos/{$order}/vender")->assertConflict();
        $this->assertDatabaseCount('TEC_FACTURA', 0);
        $this->postJson("/api/pedidos/{$order}/avanzar")->assertOk()->assertJsonPath('estado', 'PENDIENTE_APROBACION');
        $this->postJson("/api/pedidos/{$order}/decidir", ['decision' => 'APROBAR', 'motivo' => 'Descuento autorizado'])->assertOk();
        $this->postJson("/api/pedidos/{$order}/avanzar")->assertConflict();
        $sale = $this->postJson("/api/pedidos/{$order}/vender")->assertCreated()->json('id');
        $this->postJson("/api/pedidos/{$order}/vender")->assertConflict();
        $this->assertDatabaseCount('TEC_FACTURA', 0);
        $this->getJson("/api/ventas/{$sale}/factura")->assertNotFound();
        $total = (float) DB::table('TEC_VENTA')->where('VEN_Venta', $sale)->value('VEN_Total');
        $this->postJson("/api/ventas/{$sale}/pagos", ['monto' => 10, 'metodo' => 'EFECTIVO'])
            ->assertCreated()->assertJsonPath('factura_id', null);
        $this->assertDatabaseCount('TEC_FACTURA', 0);
        $invoiceId = $this->postJson("/api/ventas/{$sale}/pagos", ['monto' => round($total - 10, 2), 'metodo' => 'EFECTIVO'])
            ->assertCreated()->assertJsonPath('message', 'Pago completado y factura emitida.')->json('factura_id');
        $this->assertDatabaseCount('TEC_FACTURA', 1);
        foreach ($products as $product) {
            $this->assertDatabaseHas('TEC_INVENTARIO', ['PRO_Producto' => $product->PRO_Producto, 'INV_Existencia' => 8]);
        }
        $invoice = DB::table('TEC_FACTURA')->where('VEN_Venta', $sale)->first();
        $this->assertSame($invoiceId, $invoice->FAC_Factura);
        $snapshot = json_decode($invoice->FAC_Datos, true, 512, JSON_THROW_ON_ERROR);
        $subtotal = $products->sum(fn ($product) => 2 * (float) $product->PRO_Precio);
        $this->assertCount(2, $snapshot['detalles']);
        $this->assertEquals($subtotal - round($subtotal * .11, 2), $snapshot['total']);

        DB::table('TEC_CLIENTE')->where('CLI_Cliente', $clientId)->update(['CLI_NIT' => '1234567-8', 'CLI_Razon_Social' => 'Otro nombre']);
        DB::table('TEC_PRODUCTO')->where('PRO_Producto', $products[0]->PRO_Producto)->update(['PRO_Nombre' => 'Otro producto', 'PRO_Precio' => 999]);
        $this->assertSame($invoice->FAC_Datos, DB::table('TEC_FACTURA')->where('VEN_Venta', $sale)->value('FAC_Datos'));
        $pdf = $this->get("/api/ventas/{$sale}/factura")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->getJson('/api/ventas?con_opciones=1')->assertOk()->assertJsonPath('data.0.FAC_Factura', $invoice->FAC_Factura);

        $this->actingAs(User::where('USU_Correo', 'supervisor@techstore.test')->firstOrFail(), 'sanctum');
        $this->getJson("/api/ventas/{$sale}/factura")->assertOk();
        $supervisor = User::where('USU_Correo', 'supervisor@techstore.test')->firstOrFail();
        DB::table('TEC_ROL_PERMISO')->where('ROL_Rol', $supervisor->ROL_Rol)->delete();
        \Illuminate\Support\Facades\Cache::flush();
        $this->getJson("/api/ventas/{$sale}/factura")->assertForbidden();
    }

    public function test_identity_contact_discount_and_payment_limits_are_validated(): void
    {
        $base = [
            'primer_nombre' => 'Ana',
            'primer_apellido' => 'Lopez',
            'correo' => 'ana.limites@example.com',
            'telefono' => '55551234',
        ];
        foreach (['123456789012', '12345678901234', 'DPI-1234567890'] as $dpi) {
            $this->postJson('/api/catalogos/clientes', $base + ['identificacion' => $dpi])
                ->assertUnprocessable()->assertJsonValidationErrors('identificacion');
        }
        foreach (['15551234', '85551234', '5555123', '555512345'] as $phone) {
            $this->postJson('/api/catalogos/clientes', ['identificacion' => '2514254140150', 'telefono' => $phone] + $base)
                ->assertUnprocessable()->assertJsonValidationErrors('telefono');
        }
        foreach (['123456789', '1234567-8', 'K1234567', 'ABC'] as $nit) {
            $this->postJson('/api/catalogos/clientes', $base + ['identificacion' => '2514254140150', 'nit' => $nit])
                ->assertUnprocessable()->assertJsonValidationErrors('nit');
        }
        $id = $this->postJson('/api/catalogos/clientes', $base + ['identificacion' => '2514254140150', 'nit' => '1234567k'])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('TEC_CLIENTE', ['CLI_Cliente' => $id, 'CLI_NIT' => '1234567K']);
        $cf = $this->postJson('/api/catalogos/clientes', ['identificacion' => '2514254140151', 'correo' => 'cf@example.com'] + $base)
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('TEC_CLIENTE', ['CLI_Cliente' => $cf, 'CLI_NIT' => 'CF']);

        $product = DB::table('TEC_PRODUCTO')->first();
        DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product->PRO_Producto)->update(['INV_Existencia' => 10]);
        $order = ['cliente_id' => $id, 'detalles' => [['producto_id' => $product->PRO_Producto, 'cantidad' => 1]]];
        foreach ([100.01, 150, -1, 10.555] as $discount) {
            $this->postJson('/api/pedidos', $order + ['descuento_porcentaje' => $discount])
                ->assertUnprocessable()->assertJsonValidationErrors('descuento_porcentaje');
        }
        $orderId = $this->postJson('/api/pedidos', $order)->assertCreated()->json('id');
        $this->postJson("/api/pedidos/{$orderId}/avanzar")->assertOk();
        $sale = $this->postJson("/api/pedidos/{$orderId}/vender")->assertCreated()->json('id');
        $total = (float) DB::table('TEC_VENTA')->where('VEN_Venta', $sale)->value('VEN_Total');
        $this->postJson("/api/ventas/{$sale}/pagos", ['monto' => $total + 0.01, 'metodo' => 'EFECTIVO'])
            ->assertUnprocessable()->assertJsonPath('message', 'El monto no puede superar el saldo pendiente de Q '.number_format($total, 2).'.');
        $this->postJson("/api/ventas/{$sale}/pagos", ['monto' => 1.005, 'metodo' => 'EFECTIVO'])
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->assertDatabaseCount('TEC_PAGO', 0);
    }

    public function test_invoice_uses_defaults_and_requires_contact_data(): void
    {
        $product = DB::table('TEC_PRODUCTO')->first();
        DB::table('TEC_INVENTARIO')->where('PRO_Producto', $product->PRO_Producto)->update(['INV_Existencia' => 10]);
        $clientId = DB::table('TEC_CLIENTE')->value('CLI_Cliente');
        DB::table('TEC_CLIENTE')->where('CLI_Cliente', $clientId)->update([
            'CLI_NIT' => null, 'CLI_Razon_Social' => null, 'CLI_Direccion_Fiscal' => null, 'CLI_Telefono' => null,
        ]);
        $order = $this->postJson('/api/pedidos', [
            'cliente_id' => $clientId,
            'detalles' => [['producto_id' => $product->PRO_Producto, 'cantidad' => 1]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/pedidos/{$order}/avanzar")->assertOk();
        $sale = $this->postJson("/api/pedidos/{$order}/vender")->assertCreated()->json('id');
        $total = (float) DB::table('TEC_VENTA')->where('VEN_Venta', $sale)->value('VEN_Total');
        $this->postJson("/api/ventas/{$sale}/pagos", ['monto' => $total, 'metodo' => 'EFECTIVO'])
            ->assertUnprocessable()->assertJsonPath('message', 'Para facturar, el cliente debe tener nombre completo, correo y teléfono.');
        $this->assertDatabaseCount('TEC_PAGO', 0);
        $this->assertDatabaseCount('TEC_FACTURA', 0);
        $this->assertDatabaseHas('TEC_VENTA', ['VEN_Venta' => $sale, 'VEN_Pagado' => 0]);

        DB::table('TEC_CLIENTE')->where('CLI_Cliente', $clientId)->update(['CLI_Telefono' => '55551234', 'CLI_Correo' => 'cliente@example.com']);
        $this->postJson("/api/ventas/{$sale}/pagos", ['monto' => $total, 'metodo' => 'EFECTIVO'])->assertCreated();
        $client = DB::table('TEC_CLIENTE')->where('CLI_Cliente', $clientId)->first();
        $snapshot = json_decode(DB::table('TEC_FACTURA')->where('VEN_Venta', $sale)->value('FAC_Datos'), true);
        $this->assertSame('CF', $snapshot['cliente']['nit']);
        $this->assertSame('Ciudad', $snapshot['cliente']['direccion_fiscal']);
        $this->assertStringContainsString($client->CLI_Primer_Nombre, $snapshot['cliente']['razon_social']);
        $this->assertStringContainsString($client->CLI_Primer_Apellido, $snapshot['cliente']['razon_social']);
        $this->assertStringStartsWith('%PDF-', $this->get("/api/ventas/{$sale}/factura")->assertOk()->getContent());
    }
}
