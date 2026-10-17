<?php

namespace App\Http\Controllers;

use App\Support\Catalogos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryController extends ApiController
{
    public function stock(Request $request): JsonResponse
    {
        $this->requireAnyPermission($request, ['INVENTARIO.AJUSTAR', 'COMPRAS.REGISTRAR', 'PEDIDOS.REGISTRAR', 'VENTAS.REGISTRAR', 'REPORTES.CONSULTAR']);
        $query = DB::table('TEC_INVENTARIO as i')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'i.PRO_Producto')
            ->join('TEC_CATEGORIA as c', 'c.CAT_Categoria', '=', 'p.CAT_Categoria')
            ->select(
                'p.PRO_Producto',
                'p.PRO_SKU',
                'p.PRO_Nombre',
                'c.CAT_Nombre',
                'i.INV_Existencia',
                'p.PRO_Existencia_Minima',
                DB::raw('CASE WHEN i.INV_Existencia <= p.PRO_Existencia_Minima THEN 1 ELSE 0 END as bajo_minimo'),
            );

        if ($request->filled('buscar')) {
            $search = trim((string) $request->query('buscar'));
            $query->where(fn ($q) => $q
                ->where('p.PRO_SKU', 'like', "%{$search}%")
                ->orWhere('p.PRO_Nombre', 'like', "%{$search}%")
                ->when(ctype_digit($search), fn ($q) => $q->orWhere('p.PRO_Producto', (int) $search)));
        }
        if ($request->filled('categoria_id')) {
            $query->where('p.CAT_Categoria', $request->integer('categoria_id'));
        }
        if ($request->query('stock') === 'bajo') {
            $query->whereColumn('i.INV_Existencia', '<=', 'p.PRO_Existencia_Minima');
        }

        $stock = $this->orderedStock($query, $request)->paginate(min((int) $request->query('por_pagina', 50), 100));
        if (! $request->boolean('completo')) {
            return response()->json($stock);
        }

        return response()->json([
            'stock' => $stock,
            'movimientos' => $this->movementQuery($request)->orderByDesc('m.MOV_Fecha')->paginate(30),
            'alertas' => $this->activeAlerts(),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $this->requireAnyPermission($request, ['INVENTARIO.AJUSTAR', 'COMPRAS.REGISTRAR', 'REPORTES.CONSULTAR']);

        return response()->json($this->movementQuery($request)->orderByDesc('m.MOV_Fecha')->paginate(30));
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->requireAnyPermission($request, ['INVENTARIO.AJUSTAR', 'COMPRAS.REGISTRAR', 'REPORTES.CONSULTAR']);

        return response()->json($this->activeAlerts());
    }

    private function orderedStock($query, Request $request)
    {
        $sorts = [
            'id' => 'p.PRO_Producto',
            'sku' => 'p.PRO_SKU',
            'nombre' => 'p.PRO_Nombre',
            'categoria' => 'c.CAT_Nombre',
            'existencia' => 'i.INV_Existencia',
            'minimo' => 'p.PRO_Existencia_Minima',
        ];
        $orden = (string) $request->query('orden', 'id');
        $column = $sorts[$orden] ?? $sorts['id'];
        $direction = $request->query('direccion') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction);
        if ($orden !== 'id') {
            $query->orderBy('p.PRO_Producto');
        }

        return $query;
    }

    private function movementQuery(Request $request)
    {
        $query = DB::table('TEC_MOVIMIENTO_INVENTARIO as m')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'm.PRO_Producto')
            ->join('TEC_USUARIO as u', 'u.USU_Usuario', '=', 'm.USU_Usuario')
            ->join('TEC_TIPO_MOVIMIENTO as t', 't.TIM_Tipo_Movimiento', '=', 'm.TIM_Tipo_Movimiento')
            ->select(
                'm.*',
                't.TIM_Codigo as MOV_Tipo',
                'p.PRO_SKU',
                'p.PRO_Nombre',
                'u.USU_Primer_Nombre',
                'u.USU_Primer_Apellido',
            );

        if ($request->filled('producto_id')) {
            $query->where('m.PRO_Producto', $request->integer('producto_id'));
        }
        if ($request->filled('tipo')) {
            $query->where('t.TIM_Codigo', $request->query('tipo'));
        }
        if ($request->filled('desde')) {
            $query->where('m.MOV_Fecha', '>=', $request->query('desde').' 00:00:00');
        }
        if ($request->filled('hasta')) {
            $query->where('m.MOV_Fecha', '<=', $request->query('hasta').' 23:59:59');
        }

        return $query;
    }

    private function activeAlerts()
    {
        return DB::table('TEC_ALERTA as a')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'a.PRO_Producto')
            ->join('TEC_INVENTARIO as i', 'i.PRO_Producto', '=', 'p.PRO_Producto')
            ->where('a.ALE_Estado', 'ACTIVA')
            ->select('a.*', 'p.PRO_SKU', 'p.PRO_Nombre', 'i.INV_Existencia', 'p.PRO_Existencia_Minima')
            ->orderBy('p.PRO_Producto')
            ->get();
    }

    public function adjust(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'INVENTARIO.AJUSTAR');
        $data = $request->validate([
            'producto_id' => ['required', 'integer', 'exists:TEC_PRODUCTO,PRO_Producto'],
            'tipo' => ['required', Rule::in(['AJUSTE_ENTRADA', 'AJUSTE_SALIDA', 'DEVOLUCION'])],
            'cantidad' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string', 'max:1000'],
        ]);

        $movementId = DB::transaction(function () use ($request, $data) {
            $inventory = DB::table('TEC_INVENTARIO')
                ->where('PRO_Producto', $data['producto_id'])
                ->lockForUpdate()
                ->first();
            $before = (int) $inventory->INV_Existencia;
            $delta = $data['tipo'] === 'AJUSTE_SALIDA' ? -$data['cantidad'] : $data['cantidad'];
            $after = $before + $delta;
            abort_if($after < 0, 422, 'El ajuste produciría inventario negativo.');

            DB::table('TEC_INVENTARIO')->where('INV_Inventario', $inventory->INV_Inventario)->update([
                'INV_Existencia' => $after,
                'updated_at' => now(),
            ]);

            return DB::table('TEC_MOVIMIENTO_INVENTARIO')->insertGetId([
                'PRO_Producto' => $data['producto_id'],
                'USU_Usuario' => $request->user()->USU_Usuario,
                'TIM_Tipo_Movimiento' => Catalogos::tipoMovimiento(
                    $data['tipo'] === 'DEVOLUCION' ? 'DEVOLUCION' : 'AJUSTE'
                ),
                'MOV_Cantidad' => $delta,
                'MOV_Existencia_Anterior' => $before,
                'MOV_Existencia_Nueva' => $after,
                'MOV_Documento_Tipo' => 'AJUSTE',
                'MOV_Documento' => 0,
                'MOV_Motivo' => $data['motivo'],
                'MOV_Fecha' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ], 'MOV_Movimiento_Inventario');
        });

        $this->syncAlert($data['producto_id']);
        $this->audit($request, 'INVENTARIO', $data['tipo'], $movementId, $data);

        return response()->json(['id' => $movementId, 'message' => 'Movimiento registrado.'], 201);
    }
}
