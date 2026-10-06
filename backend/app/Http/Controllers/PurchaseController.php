<?php

namespace App\Http\Controllers;

use App\Support\Catalogos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'COMPRAS.REGISTRAR');
        $query = DB::table('TEC_COMPRA as c')
            ->join('TEC_PROVEEDOR as p', 'p.PRV_Proveedor', '=', 'c.PRV_Proveedor')
            ->join('TEC_ESTADO_COMPRA as e', 'e.ESC_Estado_Compra', '=', 'c.ESC_Estado_Compra')
            ->select('c.*', 'e.ESC_Codigo as COM_Estado', 'p.PRV_Razon_Social');

        if ($request->filled('estado')) {
            $query->where('e.ESC_Codigo', $request->query('estado'));
        }
        if ($request->filled('proveedor_id')) {
            $query->where('c.PRV_Proveedor', $request->integer('proveedor_id'));
        }
        if ($request->filled('desde')) {
            $query->whereDate('c.COM_Fecha', '>=', $request->query('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('c.COM_Fecha', '<=', $request->query('hasta'));
        }

        $purchases = $query->orderByDesc('c.COM_Fecha')->paginate(20);
        foreach ($purchases as $purchase) {
            $purchase->detalles = DB::table('TEC_DETALLE_COMPRA as d')
                ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
                ->where('d.COM_Compra', $purchase->COM_Compra)
                ->select('d.*', 'p.PRO_SKU', 'p.PRO_Nombre')
                ->orderBy('p.PRO_Nombre')
                ->get();
        }

        return response()->json($purchases);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'COMPRAS.REGISTRAR');
        $data = $request->validate([
            'proveedor_id' => ['required', 'integer', 'exists:TEC_PROVEEDOR,PRV_Proveedor'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => ['required', 'integer', 'distinct', 'exists:TEC_PRODUCTO,PRO_Producto'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1'],
            'detalles.*.costo_unitario' => ['required', 'numeric', 'gt:0'],
        ]);

        $total = collect($data['detalles'])->sum(
            fn (array $detail) => $detail['cantidad'] * $detail['costo_unitario']
        );

        $id = DB::transaction(function () use ($request, $data, $total) {
            $id = DB::table('TEC_COMPRA')->insertGetId([
                'PRV_Proveedor' => $data['proveedor_id'],
                'USU_Usuario' => $request->user()->USU_Usuario,
                'COM_Fecha' => now(),
                'ESC_Estado_Compra' => Catalogos::estadoCompra('PENDIENTE'),
                'COM_Total' => $total,
                'COM_Observacion' => $data['observacion'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'COM_Compra');

            foreach ($data['detalles'] as $detail) {
                DB::table('TEC_DETALLE_COMPRA')->insert([
                    'COM_Compra' => $id,
                    'PRO_Producto' => $detail['producto_id'],
                    'DCO_Cantidad' => $detail['cantidad'],
                    'DCO_Costo_Unitario' => $detail['costo_unitario'],
                    'DCO_Subtotal' => $detail['cantidad'] * $detail['costo_unitario'],
                ]);
            }

            return $id;
        });

        $this->audit($request, 'COMPRAS', 'CREAR', $id, ['total' => $total]);

        return response()->json(['id' => $id, 'total' => $total, 'message' => 'Compra registrada.'], 201);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'COMPRAS.REGISTRAR');
        $data = $request->validate(['observacion' => ['nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($request, $id, $data) {
            $purchase = DB::table('TEC_COMPRA as c')
                ->join('TEC_ESTADO_COMPRA as e', 'e.ESC_Estado_Compra', '=', 'c.ESC_Estado_Compra')
                ->where('c.COM_Compra', $id)
                ->lockForUpdate()
                ->select('c.*', 'e.ESC_Codigo as COM_Estado')
                ->first();
            abort_unless($purchase, 404, 'Compra no encontrada.');
            abort_unless($purchase->COM_Estado === 'PENDIENTE', 409, 'La compra ya fue recibida o cancelada.');

            $receipt = DB::table('TEC_RECEPCION')->insertGetId([
                'COM_Compra' => $id,
                'USU_Usuario' => $request->user()->USU_Usuario,
                'REC_Fecha' => now(),
                'REC_Observacion' => $data['observacion'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'REC_Recepcion');

            $details = DB::table('TEC_DETALLE_COMPRA')->where('COM_Compra', $id)->get();
            foreach ($details as $detail) {
                $inventory = DB::table('TEC_INVENTARIO')
                    ->where('PRO_Producto', $detail->PRO_Producto)
                    ->lockForUpdate()
                    ->first();
                $before = (int) $inventory->INV_Existencia;
                $after = $before + (int) $detail->DCO_Cantidad;
                DB::table('TEC_INVENTARIO')->where('INV_Inventario', $inventory->INV_Inventario)->update([
                    'INV_Existencia' => $after,
                    'updated_at' => now(),
                ]);
                DB::table('TEC_MOVIMIENTO_INVENTARIO')->insert([
                    'PRO_Producto' => $detail->PRO_Producto,
                    'USU_Usuario' => $request->user()->USU_Usuario,
                    'TIM_Tipo_Movimiento' => Catalogos::tipoMovimiento('ENTRADA'),
                    'MOV_Cantidad' => $detail->DCO_Cantidad,
                    'MOV_Existencia_Anterior' => $before,
                    'MOV_Existencia_Nueva' => $after,
                    'MOV_Documento_Tipo' => 'RECEPCION',
                    'MOV_Documento' => $receipt,
                    'MOV_Motivo' => 'Recepción de compra',
                    'MOV_Fecha' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->syncAlert((int) $detail->PRO_Producto);
            }

            DB::table('TEC_COMPRA')->where('COM_Compra', $id)->update([
                'ESC_Estado_Compra' => Catalogos::estadoCompra('RECIBIDA'),
                'updated_at' => now(),
            ]);
        });

        $this->audit($request, 'COMPRAS', 'RECIBIR', $id);

        return response()->json(['message' => 'Compra recibida e inventario actualizado.']);
    }
}
