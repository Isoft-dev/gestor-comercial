<?php

namespace App\Http\Controllers;

use App\Support\Catalogos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends ApiController
{
    private const DISCOUNT_LIMIT = 10;

    public function index(Request $request): JsonResponse
    {
        $this->requireAnyPermission($request, ['PEDIDOS.REGISTRAR', 'PEDIDOS.APROBAR']);
        $query = DB::table('TEC_PEDIDO as p')
            ->join('TEC_CLIENTE as c', 'c.CLI_Cliente', '=', 'p.CLI_Cliente')
            ->join('TEC_ESTADO_PEDIDO as e', 'e.ESP_Estado_Pedido', '=', 'p.ESP_Estado_Pedido')
            ->select(
                'p.*',
                'e.ESP_Codigo as PED_Estado',
                'c.CLI_Identificacion',
                'c.CLI_Primer_Nombre',
                'c.CLI_Primer_Apellido',
            );

        if ($request->filled('estado')) {
            $query->where('e.ESP_Codigo', $request->query('estado'));
        }
        if ($request->filled('cliente_id')) {
            $query->where('p.CLI_Cliente', $request->integer('cliente_id'));
        }
        if ($request->filled('buscar')) {
            $search = trim((string) $request->query('buscar'));
            $query->where(function ($inner) use ($search) {
                $inner->where('c.CLI_Identificacion', 'like', "%{$search}%")
                    ->orWhere('c.CLI_Primer_Nombre', 'like', "%{$search}%")
                    ->orWhere('c.CLI_Primer_Apellido', 'like', "%{$search}%")
                    ->orWhere('c.CLI_Segundo_Apellido', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $inner->orWhere('p.PED_Pedido', (int) $search);
                }
            });
        }

        $orders = $query->orderByDesc('p.PED_Fecha')->paginate(20);
        $ids = collect($orders->items())->pluck('PED_Pedido');
        $details = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_DETALLE_PEDIDO as d')
                ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
                ->whereIn('d.PED_Pedido', $ids)
                ->leftJoin('TEC_INVENTARIO as i', 'i.PRO_Producto', '=', 'd.PRO_Producto')
                ->select('d.*', 'p.PRO_SKU', 'p.PRO_Nombre', 'i.INV_Existencia')
                ->orderBy('p.PRO_Nombre')
                ->get()
                ->groupBy('PED_Pedido');
        $this->attachGrouped($orders, 'PED_Pedido', 'detalles', $details);
        $history = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_HISTORIAL_ESTADO')
                ->whereIn('PED_Pedido', $ids)
                ->orderBy('HES_Fecha')
                ->get()
                ->groupBy('PED_Pedido');
        $this->attachGrouped($orders, 'PED_Pedido', 'historial', $history);

        if (! $request->boolean('con_opciones')) {
            return response()->json($orders);
        }

        $payload = $orders->toArray();
        $payload['clientes'] = DB::table('TEC_CLIENTE')
            ->where('CLI_Estado', true)
            ->orderBy('CLI_Primer_Nombre')
            ->get([
                'CLI_Cliente',
                'CLI_Identificacion',
                'CLI_Primer_Nombre',
                'CLI_Segundo_Nombre',
                'CLI_Primer_Apellido',
                'CLI_Segundo_Apellido',
            ]);
        $payload['productos'] = $this->productOptions();

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'PEDIDOS.REGISTRAR');
        $data = $request->validate([
            'cliente_id' => ['required', 'integer', 'exists:TEC_CLIENTE,CLI_Cliente'],
            'descuento_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => ['required', 'integer', 'distinct', 'exists:TEC_PRODUCTO,PRO_Producto'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1'],
        ], [
            'descuento_porcentaje.max' => 'El descuento no puede ser mayor a 100 %.',
            'descuento_porcentaje.min' => 'El descuento no puede ser negativo.',
            'descuento_porcentaje.decimal' => 'El descuento admite como máximo 2 decimales.',
        ]);

        $lines = collect($data['detalles'])->map(function (array $detail) {
            $product = DB::table('TEC_PRODUCTO')
                ->where('PRO_Producto', $detail['producto_id'])
                ->where('PRO_Estado', true)
                ->first();
            abort_unless($product, 422, 'Uno de los productos no está disponible.');

            return [
                'producto_id' => $detail['producto_id'],
                'cantidad' => $detail['cantidad'],
                'precio' => (float) $product->PRO_Precio,
                'subtotal' => $detail['cantidad'] * (float) $product->PRO_Precio,
            ];
        });
        $subtotal = $lines->sum('subtotal');
        $percentage = (float) ($data['descuento_porcentaje'] ?? 0);
        $discount = round($subtotal * $percentage / 100, 2);
        $total = $subtotal - $discount;

        $id = DB::transaction(function () use ($request, $data, $lines, $subtotal, $percentage, $discount, $total) {
            $id = DB::table('TEC_PEDIDO')->insertGetId([
                'CLI_Cliente' => $data['cliente_id'],
                'USU_Usuario' => $request->user()->USU_Usuario,
                'PED_Fecha' => now(),
                'ESP_Estado_Pedido' => Catalogos::estadoPedido('CREADO'),
                'PED_Subtotal' => $subtotal,
                'PED_Descuento_Porcentaje' => $percentage,
                'PED_Descuento_Monto' => $discount,
                'PED_Total' => $total,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'PED_Pedido');

            foreach ($lines as $line) {
                DB::table('TEC_DETALLE_PEDIDO')->insert([
                    'PED_Pedido' => $id,
                    'PRO_Producto' => $line['producto_id'],
                    'DPE_Cantidad' => $line['cantidad'],
                    'DPE_Precio_Unitario' => $line['precio'],
                    'DPE_Subtotal' => $line['subtotal'],
                ]);
            }
            $this->history($request, $id, null, 'CREADO', 'Pedido registrado');

            return $id;
        });

        $this->audit($request, 'PEDIDOS', 'CREAR', $id, ['total' => $total]);

        return response()->json(['id' => $id, 'total' => $total, 'message' => 'Pedido creado.'], 201);
    }

    public function advance(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'PEDIDOS.REGISTRAR');
        $order = $this->orderWithState($id);
        abort_unless($order, 404, 'Pedido no encontrado.');

        $next = match ($order->PED_Estado) {
            'CREADO' => (float) $order->PED_Descuento_Porcentaje > self::DISCOUNT_LIMIT
                ? 'PENDIENTE_APROBACION'
                : 'APROBADO',
            default => null,
        };
        abort_unless($next, 409, 'El pedido no puede avanzar desde su estado actual.');

        DB::transaction(function () use ($request, $order, $next, $id) {
            DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update([
                'ESP_Estado_Pedido' => Catalogos::estadoPedido($next),
                'updated_at' => now(),
            ]);
            $this->history($request, $id, $order->PED_Estado, $next, 'Avance de pedido');
            if ($next === 'PENDIENTE_APROBACION') {
                DB::table('TEC_APROBACION')->insert([
                    'PED_Pedido' => $id,
                    'APR_Estado' => 'PENDIENTE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
        $this->audit($request, 'PEDIDOS', 'CAMBIAR_ESTADO', $id, ['estado' => $next]);

        return response()->json(['estado' => $next, 'message' => 'Pedido actualizado.']);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'PEDIDOS.APROBAR');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APROBAR', 'RECHAZAR'])],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);
        $order = $this->orderWithState($id);
        abort_unless($order, 404, 'Pedido no encontrado.');
        abort_unless($order->PED_Estado === 'PENDIENTE_APROBACION', 409, 'El pedido no espera aprobación.');
        $next = $data['decision'] === 'APROBAR' ? 'APROBADO' : 'CANCELADO';

        DB::transaction(function () use ($request, $data, $id, $order, $next) {
            DB::table('TEC_APROBACION')->where('PED_Pedido', $id)->update([
                'USU_Usuario' => $request->user()->USU_Usuario,
                'APR_Estado' => $data['decision'] === 'APROBAR' ? 'APROBADA' : 'RECHAZADA',
                'APR_Motivo' => $data['motivo'],
                'APR_Fecha' => now(),
                'updated_at' => now(),
            ]);
            DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update([
                'ESP_Estado_Pedido' => Catalogos::estadoPedido($next),
                'PED_Motivo_Cancelacion' => $next === 'CANCELADO' ? $data['motivo'] : null,
                'updated_at' => now(),
            ]);
            $this->history($request, $id, $order->PED_Estado, $next, $data['motivo']);
        });
        $this->audit($request, 'PEDIDOS', $data['decision'], $id, ['motivo' => $data['motivo']]);

        return response()->json(['estado' => $next, 'message' => 'Decisión registrada.']);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'PEDIDOS.REGISTRAR');
        $data = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);
        $order = $this->orderWithState($id);
        abort_unless($order, 404, 'Pedido no encontrado.');
        abort_if(in_array($order->PED_Estado, ['COMPLETADO', 'CANCELADO'], true), 409, 'El pedido ya está cerrado.');

        DB::transaction(function () use ($request, $order, $data, $id) {
            DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update([
                'ESP_Estado_Pedido' => Catalogos::estadoPedido('CANCELADO'),
                'PED_Motivo_Cancelacion' => $data['motivo'],
                'updated_at' => now(),
            ]);
            $this->history($request, $id, $order->PED_Estado, 'CANCELADO', $data['motivo']);
        });
        $this->audit($request, 'PEDIDOS', 'CANCELAR', $id, ['motivo' => $data['motivo']]);

        return response()->json(['message' => 'Pedido cancelado.']);
    }

    private function history(
        Request $request,
        int $orderId,
        ?string $before,
        string $after,
        ?string $reason,
    ): void {
        DB::table('TEC_HISTORIAL_ESTADO')->insert([
            'PED_Pedido' => $orderId,
            'USU_Usuario' => $request->user()->USU_Usuario,
            'HES_Estado_Anterior' => $before,
            'HES_Estado_Nuevo' => $after,
            'HES_Motivo' => $reason,
            'HES_Fecha' => now(),
        ]);
    }

    private function orderWithState(int $id): ?object
    {
        return DB::table('TEC_PEDIDO as p')
            ->join('TEC_ESTADO_PEDIDO as e', 'e.ESP_Estado_Pedido', '=', 'p.ESP_Estado_Pedido')
            ->where('p.PED_Pedido', $id)
            ->select('p.*', 'e.ESP_Codigo as PED_Estado')
            ->first();
    }
}
