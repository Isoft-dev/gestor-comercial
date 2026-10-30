<?php

namespace App\Http\Controllers;

use App\Support\Billing;
use App\Support\Catalogos;
use App\Support\FieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SaleController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAnyPermission($request, ['VENTAS.REGISTRAR', 'PAGOS.REGISTRAR', 'REPORTES.CONSULTAR']);
        $query = DB::table('TEC_VENTA as v')
            ->join('TEC_PEDIDO as p', 'p.PED_Pedido', '=', 'v.PED_Pedido')
            ->join('TEC_CLIENTE as c', 'c.CLI_Cliente', '=', 'p.CLI_Cliente')
            ->join('TEC_USUARIO as seller', 'seller.USU_Usuario', '=', 'v.USU_Usuario')
            ->join('TEC_ESTADO_PEDIDO as order_state', 'order_state.ESP_Estado_Pedido', '=', 'p.ESP_Estado_Pedido')
            ->join('TEC_ESTADO_PAGO as ep', 'ep.EPA_Estado_Pago', '=', 'v.EPA_Estado_Pago')
            ->join('TEC_ESTADO_VENTA as ev', 'ev.ESV_Estado_Venta', '=', 'v.ESV_Estado_Venta')
            ->leftJoin('TEC_FACTURA as f', 'f.VEN_Venta', '=', 'v.VEN_Venta')
            ->select(
                'v.*',
                'f.FAC_Factura',
                'p.PED_Fecha',
                'p.PED_Subtotal',
                'p.PED_Descuento_Porcentaje',
                'p.PED_Descuento_Monto',
                'p.PED_Total',
                'order_state.ESP_Codigo as PED_Estado',
                'ep.EPA_Codigo as VEN_Estado_Pago',
                'ev.ESV_Codigo as VEN_Estado',
                'c.CLI_Identificacion',
                'c.CLI_Primer_Nombre',
                'c.CLI_Segundo_Nombre',
                'c.CLI_Primer_Apellido',
                'c.CLI_Segundo_Apellido',
                'c.CLI_Correo',
                'c.CLI_Telefono',
                'seller.USU_Primer_Nombre as VEN_Vendedor_Nombre',
                'seller.USU_Primer_Apellido as VEN_Vendedor_Apellido',
            );

        if ($request->filled('cliente_id')) {
            $query->where('p.CLI_Cliente', $request->integer('cliente_id'));
        }
        if ($request->filled('estado_pago')) {
            $query->where('ep.EPA_Codigo', $request->query('estado_pago'));
        }
        if ($request->filled('desde')) {
            $query->where('v.VEN_Fecha', '>=', $request->query('desde').' 00:00:00');
        }
        if ($request->filled('hasta')) {
            $query->where('v.VEN_Fecha', '<=', $request->query('hasta').' 23:59:59');
        }
        if ($request->filled('buscar')) {
            $search = trim((string) $request->query('buscar'));
            $query->where(function ($inner) use ($search) {
                $inner->where('c.CLI_Identificacion', 'like', "%{$search}%")
                    ->orWhere('c.CLI_Primer_Nombre', 'like', "%{$search}%")
                    ->orWhere('c.CLI_Primer_Apellido', 'like', "%{$search}%")
                    ->orWhere('c.CLI_Segundo_Apellido', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $inner->orWhere('v.VEN_Venta', (int) $search)
                        ->orWhere('v.PED_Pedido', (int) $search);
                }
            });
        }

        $sales = $query->orderByDesc('v.VEN_Fecha')->orderByDesc('v.VEN_Venta')->paginate(20);
        $ids = collect($sales->items())->pluck('VEN_Venta');
        $payments = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_PAGO as pg')
                ->join('TEC_METODO_PAGO as mp', 'mp.MPA_Metodo_Pago', '=', 'pg.MPA_Metodo_Pago')
                ->join('TEC_USUARIO as payment_user', 'payment_user.USU_Usuario', '=', 'pg.USU_Usuario')
                ->whereIn('pg.VEN_Venta', $ids)
                ->select(
                    'pg.*',
                    'mp.MPA_Codigo as PAG_Metodo',
                    'mp.MPA_Nombre as metodo_nombre',
                    'payment_user.USU_Primer_Nombre as PAG_Usuario_Nombre',
                    'payment_user.USU_Primer_Apellido as PAG_Usuario_Apellido',
                )
                ->orderByDesc('pg.PAG_Fecha')
                ->get()
                ->groupBy('VEN_Venta');
        $this->attachGrouped($sales, 'VEN_Venta', 'pagos', $payments);
        $lines = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_DETALLE_VENTA as d')
                ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
                ->whereIn('d.VEN_Venta', $ids)
                ->select('d.*', 'p.PRO_SKU', 'p.PRO_Nombre')
                ->orderBy('p.PRO_Nombre')
                ->get()
                ->groupBy('VEN_Venta');
        $this->attachGrouped($sales, 'VEN_Venta', 'detalles', $lines);

        if (! $request->boolean('con_opciones')) {
            return response()->json($sales);
        }

        $payload = $sales->toArray();
        $payload['metodos_pago'] = DB::table('TEC_METODO_PAGO')
            ->where('MPA_Estado', true)
            ->orderBy('MPA_Nombre')
            ->get(['MPA_Metodo_Pago', 'MPA_Codigo', 'MPA_Nombre']);

        return response()->json($payload);
    }

    public function storeFromOrder(Request $request, int $orderId): JsonResponse
    {
        $this->requirePermission($request, 'VENTAS.REGISTRAR');

        $saleId = DB::transaction(function () use ($request, $orderId) {
            $order = DB::table('TEC_PEDIDO as p')
                ->join('TEC_ESTADO_PEDIDO as e', 'e.ESP_Estado_Pedido', '=', 'p.ESP_Estado_Pedido')
                ->where('p.PED_Pedido', $orderId)
                ->lockForUpdate()
                ->select('p.*', 'e.ESP_Codigo as PED_Estado')
                ->first();
            abort_unless($order, 404, 'Pedido no encontrado.');
            abort_unless(in_array($order->PED_Estado, ['APROBADO', 'EN_PROCESO'], true), 409, 'El pedido no está aprobado.');
            abort_if(DB::table('TEC_VENTA')->where('PED_Pedido', $orderId)->exists(), 409, 'El pedido ya fue vendido.');

            $details = DB::table('TEC_DETALLE_PEDIDO as d')
                ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
                ->where('d.PED_Pedido', $orderId)
                ->select('d.*', 'p.PRO_Nombre')
                ->get();
            abort_if($details->isEmpty(), 422, 'No se puede completar una venta sin productos.');

            $saleId = DB::table('TEC_VENTA')->insertGetId([
                'PED_Pedido' => $orderId,
                'USU_Usuario' => $request->user()->USU_Usuario,
                'VEN_Fecha' => now(),
                'VEN_Total' => $order->PED_Total,
                'VEN_Pagado' => 0,
                'VEN_Saldo' => $order->PED_Total,
                'EPA_Estado_Pago' => Catalogos::estadoPago('PENDIENTE'),
                'ESV_Estado_Venta' => Catalogos::estadoVenta('FINALIZADA'),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'VEN_Venta');

            foreach ($details as $detail) {
                $stock = DB::table('TEC_INVENTARIO')
                    ->where('PRO_Producto', $detail->PRO_Producto)
                    ->lockForUpdate()
                    ->first();
                $needed = (int) $detail->DPE_Cantidad;
                $before = (int) ($stock->INV_Existencia ?? 0);
                abort_if(
                    ! $stock || $before < $needed,
                    409,
                    "No se puede vender más de la existencia disponible de {$detail->PRO_Nombre}. Hay {$before} y el pedido pide {$needed}.",
                );
                $after = $before - $needed;
                DB::table('TEC_INVENTARIO')->where('INV_Inventario', $stock->INV_Inventario)->update([
                    'INV_Existencia' => $after,
                    'updated_at' => now(),
                ]);
                DB::table('TEC_DETALLE_VENTA')->insert([
                    'VEN_Venta' => $saleId,
                    'PRO_Producto' => $detail->PRO_Producto,
                    'DVE_Cantidad' => $detail->DPE_Cantidad,
                    'DVE_Precio_Unitario' => $detail->DPE_Precio_Unitario,
                    'DVE_Subtotal' => $detail->DPE_Subtotal,
                ]);
                DB::table('TEC_MOVIMIENTO_INVENTARIO')->insert([
                    'PRO_Producto' => $detail->PRO_Producto,
                    'USU_Usuario' => $request->user()->USU_Usuario,
                    'TIM_Tipo_Movimiento' => Catalogos::tipoMovimiento('SALIDA'),
                    'MOV_Cantidad' => -$detail->DPE_Cantidad,
                    'MOV_Existencia_Anterior' => $before,
                    'MOV_Existencia_Nueva' => $after,
                    'MOV_Documento_Tipo' => 'VENTA',
                    'MOV_Documento' => $saleId,
                    'MOV_Motivo' => 'Venta finalizada',
                    'MOV_Fecha' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->syncAlert((int) $detail->PRO_Producto);
            }

            DB::table('TEC_PEDIDO')->where('PED_Pedido', $orderId)->update([
                'ESP_Estado_Pedido' => Catalogos::estadoPedido('COMPLETADO'),
                'updated_at' => now(),
            ]);
            DB::table('TEC_HISTORIAL_ESTADO')->insert([
                'PED_Pedido' => $orderId,
                'USU_Usuario' => $request->user()->USU_Usuario,
                'HES_Estado_Anterior' => $order->PED_Estado,
                'HES_Estado_Nuevo' => 'COMPLETADO',
                'HES_Motivo' => 'Venta finalizada',
                'HES_Fecha' => now(),
            ]);

            return $saleId;
        });

        $this->audit($request, 'VENTAS', 'FINALIZAR', $saleId, ['pedido' => $orderId]);

        return response()->json(['id' => $saleId, 'message' => 'Venta finalizada e inventario actualizado. La factura se emitirá al completar el pago.'], 201);
    }

    public function payment(Request $request, int $saleId): JsonResponse
    {
        $this->requirePermission($request, 'PAGOS.REGISTRAR');
        $data = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'metodo' => ['required', 'string', Rule::exists('TEC_METODO_PAGO', 'MPA_Codigo')->where('MPA_Estado', true)],
            'referencia' => ['required_if:metodo,TRANSFERENCIA,TARJETA', 'nullable', 'string', 'max:80'],
        ], FieldRules::messages());

        [$paymentId, $invoiceId] = DB::transaction(function () use ($request, $saleId, $data) {
            $sale = DB::table('TEC_VENTA')->where('VEN_Venta', $saleId)->lockForUpdate()->first();
            abort_unless($sale, 404, 'Venta no encontrada.');
            abort_if(
                round((float) $data['monto'], 2) > round((float) $sale->VEN_Saldo, 2),
                422,
                'El monto no puede superar el saldo pendiente de Q '.number_format((float) $sale->VEN_Saldo, 2).'.',
            );

            $paid = (float) $sale->VEN_Pagado + (float) $data['monto'];
            $balance = round((float) $sale->VEN_Total - $paid, 2);
            $invoiceId = $balance <= 0 ? $this->issueInvoice($sale) : null;
            DB::table('TEC_VENTA')->where('VEN_Venta', $saleId)->update([
                'VEN_Pagado' => $paid,
                'VEN_Saldo' => $balance,
                'EPA_Estado_Pago' => Catalogos::estadoPago($balance <= 0 ? 'PAGADA' : 'PARCIAL'),
                'updated_at' => now(),
            ]);

            $paymentId = DB::table('TEC_PAGO')->insertGetId([
                'VEN_Venta' => $saleId,
                'USU_Usuario' => $request->user()->USU_Usuario,
                'PAG_Monto' => $data['monto'],
                'MPA_Metodo_Pago' => Catalogos::metodoPago($data['metodo']),
                'PAG_Fecha' => now(),
                'PAG_Referencia' => $data['referencia'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'PAG_Pago');

            return [$paymentId, $invoiceId];
        });

        $this->audit($request, 'PAGOS', 'REGISTRAR', $paymentId, ['venta' => $saleId, 'monto' => $data['monto']]);
        if ($invoiceId) {
            $this->audit($request, 'VENTAS', 'FACTURAR', $invoiceId, ['venta' => $saleId]);
        }

        return response()->json([
            'id' => $paymentId,
            'factura_id' => $invoiceId,
            'message' => $invoiceId ? 'Pago completado y factura emitida.' : 'Pago registrado.',
        ], 201);
    }

    private function issueInvoice(object $sale): int
    {
        $existing = DB::table('TEC_FACTURA')->where('VEN_Venta', $sale->VEN_Venta)->value('FAC_Factura');
        if ($existing) {
            return (int) $existing;
        }
        $order = DB::table('TEC_PEDIDO')->where('PED_Pedido', $sale->PED_Pedido)->first();
        $client = DB::table('TEC_CLIENTE')->where('CLI_Cliente', $order->CLI_Cliente)->first();
        $invoiceClient = $client ? Billing::invoiceClient($client) : null;
        abort_unless(
            $invoiceClient,
            422,
            'Para facturar, el cliente debe tener nombre completo, correo y teléfono.',
        );
        $details = DB::table('TEC_DETALLE_PEDIDO as d')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
            ->where('d.PED_Pedido', $order->PED_Pedido)
            ->orderBy('p.PRO_Nombre')
            ->get(['p.PRO_SKU', 'p.PRO_Nombre', 'd.DPE_Cantidad', 'd.DPE_Precio_Unitario', 'd.DPE_Subtotal']);

        return DB::table('TEC_FACTURA')->insertGetId([
            'VEN_Venta' => $sale->VEN_Venta,
            'FAC_Fecha' => now(),
            'FAC_Datos' => json_encode([
                'pedido' => $order->PED_Pedido,
                'cliente' => $invoiceClient,
                'detalles' => $details->map(fn ($line) => (array) $line)->all(),
                'subtotal' => $order->PED_Subtotal,
                'descuento_porcentaje' => $order->PED_Descuento_Porcentaje,
                'descuento' => $order->PED_Descuento_Monto,
                'total' => $order->PED_Total,
            ], JSON_THROW_ON_ERROR),
        ], 'FAC_Factura');
    }
}
