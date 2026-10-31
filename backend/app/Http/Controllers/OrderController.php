<?php

namespace App\Http\Controllers;

use App\Support\Catalogos;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $orders = $query->orderByDesc('p.PED_Fecha')->orderByDesc('p.PED_Pedido')->paginate(20);
        $ids = collect($orders->items())->pluck('PED_Pedido');
        $details = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_DETALLE_PEDIDO as d')
                ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
                ->whereIn('d.PED_Pedido', $ids)
                ->leftJoin('TEC_INVENTARIO as i', 'i.PRO_Producto', '=', 'd.PRO_Producto')
                ->leftJoin('TEC_APROBACION_PRODUCTO as a', 'a.DPE_Detalle_Pedido', '=', 'd.DPE_Detalle_Pedido')
                ->select('d.*', 'p.PRO_SKU', 'p.PRO_Nombre', 'i.INV_Existencia', 'a.APR_Estado', 'a.APR_Motivo', 'a.APR_Fecha')
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
        $approvals = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_APROBACION')->whereIn('PED_Pedido', $ids)->get()->groupBy('PED_Pedido');
        $this->attachGrouped($orders, 'PED_Pedido', 'aprobaciones', $approvals);

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
            'detalles.*.descuento_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ], [
            'descuento_porcentaje.max' => 'El descuento no puede ser mayor a 100 %.',
            'descuento_porcentaje.min' => 'El descuento no puede ser negativo.',
            'descuento_porcentaje.decimal' => 'El descuento admite como máximo 2 decimales.',
        ]);

        $percentage = (float) ($data['descuento_porcentaje'] ?? 0);
        if ($percentage > 0 && collect($data['detalles'])->contains(fn (array $detail) => (float) ($detail['descuento_porcentaje'] ?? 0) > 0)) {
            throw ValidationException::withMessages([
                'descuento_porcentaje' => 'No se puede aplicar un descuento general si algún producto tiene descuento.',
            ]);
        }
        $lines = collect($data['detalles'])->map(function (array $detail) {
            $product = DB::table('TEC_PRODUCTO')
                ->where('PRO_Producto', $detail['producto_id'])
                ->where('PRO_Estado', true)
                ->first();
            abort_unless($product, 422, 'Uno de los productos no está disponible.');

            $subtotal = round($detail['cantidad'] * (float) $product->PRO_Precio, 2);
            $percentage = (float) ($detail['descuento_porcentaje'] ?? 0);

            return [
                'producto_id' => $detail['producto_id'],
                'cantidad' => $detail['cantidad'],
                'precio' => (float) $product->PRO_Precio,
                'subtotal' => $subtotal,
                'porcentaje' => $percentage,
                'descuento' => round($subtotal * $percentage / 100, 2),
            ];
        });
        $subtotal = $lines->sum('subtotal');
        $discount = $percentage > 0 ? round($subtotal * $percentage / 100, 2) : $lines->sum('descuento');
        $total = round($subtotal - $discount, 2);

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
                    'DPE_Descuento_Porcentaje' => $line['porcentaje'],
                    'DPE_Descuento_Monto' => $line['descuento'],
                ]);
            }
            $this->history($request, $id, null, 'CREADO', 'Pedido registrado');

            return $id;
        });

        $this->audit($request, 'PEDIDOS', 'CREAR', $id, ['total' => $total]);

        $message = "Pedido #{$id} registrado correctamente.";
        if ($percentage > self::DISCOUNT_LIMIT || $lines->contains(fn (array $line) => $line['porcentaje'] > self::DISCOUNT_LIMIT)) {
            $message .= ' El descuento supera 10 % y requerirá aprobación.';
        }

        return response()->json(['id' => $id, 'total' => $total, 'message' => $message], 201);
    }

    public function advance(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'PEDIDOS.REGISTRAR');
        $next = DB::transaction(function () use ($request, $id) {
            $order = $this->orderWithState($id);
            abort_unless($order, 404, 'Pedido no encontrado.');
            abort_unless($order->PED_Estado === 'CREADO', 409, 'El pedido no puede avanzar desde su estado actual.');
            $details = DB::table('TEC_DETALLE_PEDIDO')->where('PED_Pedido', $id)
                ->where('DPE_Descuento_Porcentaje', '>', self::DISCOUNT_LIMIT)->get();
            $general = (float) $order->PED_Descuento_Porcentaje > self::DISCOUNT_LIMIT;
            $next = $general || $details->isNotEmpty() ? 'PENDIENTE_APROBACION' : 'APROBADO';
            DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update([
                'ESP_Estado_Pedido' => Catalogos::estadoPedido($next),
                'updated_at' => now(),
            ]);
            $this->history($request, $id, $order->PED_Estado, $next, 'Avance de pedido');
            if ($general) {
                DB::table('TEC_APROBACION')->insert([
                    'PED_Pedido' => $id,
                    'APR_Estado' => 'PENDIENTE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ($details as $detail) {
                DB::table('TEC_APROBACION_PRODUCTO')->insert([
                    'PED_Pedido' => $id,
                    'DPE_Detalle_Pedido' => $detail->DPE_Detalle_Pedido,
                    'APR_Estado' => 'PENDIENTE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $next;
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
            'detalle_id' => ['nullable', 'integer'],
        ]);
        $next = DB::transaction(function () use ($request, $data, $id) {
            $order = $this->orderWithState($id);
            abort_unless($order, 404, 'Pedido no encontrado.');
            abort_unless($order->PED_Estado === 'PENDIENTE_APROBACION', 409, 'El pedido no espera aprobación.');
            $approval = $this->approvalQuery($id, $data['detalle_id'] ?? null);
            abort_unless((clone $approval)->where('APR_Estado', 'PENDIENTE')->exists(), 409, 'Este descuento no espera aprobación. Seleccione el producto correspondiente.');
            $approval->update([
                'USU_Usuario' => $request->user()->USU_Usuario,
                'APR_Estado' => $data['decision'] === 'APROBAR' ? 'APROBADA' : 'RECHAZADA',
                'APR_Motivo' => $data['motivo'],
                'APR_Fecha' => now(),
                'updated_at' => now(),
            ]);
            $next = $this->refreshApprovalState($id);
            $this->history($request, $id, $order->PED_Estado, $next, $this->discountReason($data));

            return $next;
        });
        $this->audit($request, 'PEDIDOS', $data['decision'], $id, $data);

        return response()->json(['estado' => $next, 'message' => 'Decisión registrada.']);
    }

    public function adjustDiscount(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'PEDIDOS.REGISTRAR');
        $data = $request->validate([
            'detalle_id' => ['nullable', 'integer'],
            'descuento_porcentaje' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ]);
        $next = DB::transaction(function () use ($request, $data, $id) {
            $order = $this->orderWithState($id);
            abort_unless($order, 404, 'Pedido no encontrado.');
            abort_unless($order->PED_Estado === 'PENDIENTE_APROBACION', 409, 'El pedido no tiene descuentos por corregir.');
            $detailId = $data['detalle_id'] ?? null;
            $approval = $this->approvalQuery($id, $detailId);
            abort_unless((clone $approval)->where('APR_Estado', 'RECHAZADA')->exists(), 409, 'Solo se puede cambiar un descuento rechazado.');
            $percentage = (float) $data['descuento_porcentaje'];
            if ($detailId !== null) {
                abort_if((float) $order->PED_Descuento_Porcentaje > 0, 422, 'No se pueden combinar descuentos por producto y general.');
                $detail = DB::table('TEC_DETALLE_PEDIDO')->where('PED_Pedido', $id)->where('DPE_Detalle_Pedido', $detailId)->first();
                abort_unless($detail, 404, 'Producto del pedido no encontrado.');
                DB::table('TEC_DETALLE_PEDIDO')->where('DPE_Detalle_Pedido', $detailId)->update([
                    'DPE_Descuento_Porcentaje' => $percentage,
                    'DPE_Descuento_Monto' => round((float) $detail->DPE_Subtotal * $percentage / 100, 2),
                ]);
            } else {
                abort_if(DB::table('TEC_DETALLE_PEDIDO')->where('PED_Pedido', $id)->where('DPE_Descuento_Porcentaje', '>', 0)->exists(), 422, 'No se puede aplicar un descuento general si algún producto tiene descuento.');
                DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update(['PED_Descuento_Porcentaje' => $percentage]);
            }
            $approval->update([
                'APR_Estado' => $percentage > self::DISCOUNT_LIMIT ? 'PENDIENTE' : ($percentage > 0 ? 'APROBADA' : 'SIN_DESCUENTO'),
                'USU_Usuario' => null,
                'APR_Motivo' => null,
                'APR_Fecha' => null,
                'updated_at' => now(),
            ]);
            $this->recalculateDiscount($id);
            $next = $this->refreshApprovalState($id);
            $this->history($request, $id, $order->PED_Estado, $next, $this->discountReason($data));

            return $next;
        });
        $this->audit($request, 'PEDIDOS', 'AJUSTAR_DESCUENTO', $id, $data);

        return response()->json(['estado' => $next, 'message' => 'Descuento actualizado.']);
    }

    private function approvalQuery(int $id, ?int $detailId): Builder
    {
        $query = DB::table($detailId === null ? 'TEC_APROBACION' : 'TEC_APROBACION_PRODUCTO')->where('PED_Pedido', $id);

        return $detailId === null ? $query : $query->where('DPE_Detalle_Pedido', $detailId);
    }

    private function recalculateDiscount(int $id): void
    {
        $order = DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->first();
        $discount = (float) $order->PED_Descuento_Porcentaje > 0
            ? round((float) $order->PED_Subtotal * (float) $order->PED_Descuento_Porcentaje / 100, 2)
            : round((float) DB::table('TEC_DETALLE_PEDIDO')->where('PED_Pedido', $id)->sum('DPE_Descuento_Monto'), 2);
        DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update([
            'PED_Descuento_Monto' => $discount,
            'PED_Total' => round((float) $order->PED_Subtotal - $discount, 2),
            'updated_at' => now(),
        ]);
    }

    private function refreshApprovalState(int $id): string
    {
        $unresolved = DB::table('TEC_APROBACION')->where('PED_Pedido', $id)->whereIn('APR_Estado', ['PENDIENTE', 'RECHAZADA'])->exists()
            || DB::table('TEC_APROBACION_PRODUCTO')->where('PED_Pedido', $id)->whereIn('APR_Estado', ['PENDIENTE', 'RECHAZADA'])->exists();
        $next = $unresolved ? 'PENDIENTE_APROBACION' : 'APROBADO';
        DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update([
            'ESP_Estado_Pedido' => Catalogos::estadoPedido($next),
            'updated_at' => now(),
        ]);

        return $next;
    }

    private function discountReason(array $data): string
    {
        $scope = isset($data['detalle_id']) ? 'Producto del pedido #'.$data['detalle_id'] : 'Descuento general';

        return $scope.': '.($data['motivo'] ?? 'Porcentaje actualizado a '.$data['descuento_porcentaje'].' %.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'PEDIDOS.REGISTRAR');
        $data = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $data, $id) {
            $order = $this->orderWithState($id);
            abort_unless($order, 404, 'Pedido no encontrado.');
            abort_if(in_array($order->PED_Estado, ['COMPLETADO', 'CANCELADO'], true), 409, 'El pedido ya está cerrado.');
            DB::table('TEC_PEDIDO')->where('PED_Pedido', $id)->update([
                'ESP_Estado_Pedido' => Catalogos::estadoPedido('CANCELADO'),
                'PED_Motivo_Cancelacion' => $data['motivo'],
                'updated_at' => now(),
            ]);
            foreach (['TEC_APROBACION', 'TEC_APROBACION_PRODUCTO'] as $table) {
                DB::table($table)->where('PED_Pedido', $id)->whereIn('APR_Estado', ['PENDIENTE', 'RECHAZADA'])->update([
                    'APR_Estado' => 'CANCELADA',
                    'updated_at' => now(),
                ]);
            }
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
            ->lockForUpdate()
            ->select('p.*', 'e.ESP_Codigo as PED_Estado')
            ->first();
    }
}
