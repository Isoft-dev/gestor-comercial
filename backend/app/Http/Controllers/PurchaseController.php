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
            $query->where('c.COM_Fecha', '>=', $request->query('desde').' 00:00:00');
        }
        if ($request->filled('hasta')) {
            $query->where('c.COM_Fecha', '<=', $request->query('hasta').' 23:59:59');
        }

        $purchases = $query->orderByDesc('c.COM_Fecha')->paginate(20);
        $ids = collect($purchases->items())->pluck('COM_Compra');
        $details = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_DETALLE_COMPRA as d')
                ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
                ->whereIn('d.COM_Compra', $ids)
                ->select('d.*', 'p.PRO_SKU', 'p.PRO_Nombre')
                ->orderBy('p.PRO_Nombre')
                ->get()
                ->groupBy('COM_Compra');
        $this->attachGrouped($purchases, 'COM_Compra', 'detalles', $details);

        if (! $request->boolean('con_opciones')) {
            return response()->json($purchases);
        }

        $payload = $purchases->toArray();
        $payload['proveedores'] = DB::table('TEC_PROVEEDOR')
            ->where('PRV_Estado', true)
            ->orderBy('PRV_Razon_Social')
            ->get(['PRV_Proveedor', 'PRV_Razon_Social']);
        $payload['productos'] = $this->productOptions();

        return response()->json($payload);
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

        $receiptId = DB::transaction(function () use ($request, $id, $data) {
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
            abort_if($details->isEmpty(), 422, 'La compra no tiene productos para recibir.');

            foreach ($details as $detail) {
                $inventory = DB::table('TEC_INVENTARIO')
                    ->where('PRO_Producto', $detail->PRO_Producto)
                    ->lockForUpdate()
                    ->first();
                abort_unless($inventory, 409, 'No existe inventario para uno de los productos de la compra.');

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

            return $receipt;
        });

        $this->audit($request, 'COMPRAS', 'RECIBIR', $id);

        return response()->json([
            'id' => $receiptId,
            'message' => 'Compra recibida e inventario actualizado.',
        ]);
    }

    public function receipts(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'COMPRAS.REGISTRAR');
        $filters = $request->validate([
            'buscar' => ['sometimes', 'nullable', 'string', 'max:120'],
            'desde' => ['sometimes', 'date_format:Y-m-d'],
            'hasta' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $query = DB::table('TEC_RECEPCION as r')
            ->join('TEC_COMPRA as c', 'c.COM_Compra', '=', 'r.COM_Compra')
            ->join('TEC_PROVEEDOR as p', 'p.PRV_Proveedor', '=', 'c.PRV_Proveedor')
            ->join('TEC_USUARIO as u', 'u.USU_Usuario', '=', 'r.USU_Usuario')
            ->select(
                'r.REC_Recepcion',
                'r.COM_Compra',
                'r.REC_Fecha',
                'r.REC_Observacion',
                'c.COM_Total',
                'p.PRV_Razon_Social',
                'p.PRV_Identificacion_Fiscal',
                'u.USU_Primer_Nombre',
                'u.USU_Primer_Apellido',
            );

        if (($filters['buscar'] ?? '') !== '') {
            $search = trim($filters['buscar']);
            $query->where(function ($builder) use ($search) {
                $builder->where('p.PRV_Razon_Social', 'like', "%{$search}%")
                    ->orWhere('p.PRV_Identificacion_Fiscal', 'like', "%{$search}%")
                    ->orWhere('r.REC_Observacion', 'like', "%{$search}%")
                    ->orWhere('u.USU_Primer_Nombre', 'like', "%{$search}%")
                    ->orWhere('u.USU_Segundo_Nombre', 'like', "%{$search}%")
                    ->orWhere('u.USU_Primer_Apellido', 'like', "%{$search}%")
                    ->orWhere('u.USU_Segundo_Apellido', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $builder->orWhere('r.REC_Recepcion', (int) $search)
                        ->orWhere('r.COM_Compra', (int) $search);
                }
                $builder->orWhereExists(function ($details) use ($search) {
                    $details->selectRaw('1')
                        ->from('TEC_DETALLE_COMPRA as d')
                        ->join('TEC_PRODUCTO as pr', 'pr.PRO_Producto', '=', 'd.PRO_Producto')
                        ->whereColumn('d.COM_Compra', 'r.COM_Compra')
                        ->where(function ($productSearch) use ($search) {
                            $productSearch->where('pr.PRO_SKU', 'like', "%{$search}%")
                                ->orWhere('pr.PRO_Nombre', 'like', "%{$search}%");
                        });
                });
            });
        }

        if (isset($filters['desde'])) {
            $query->where('r.REC_Fecha', '>=', $filters['desde'].' 00:00:00');
        }
        if (isset($filters['hasta'])) {
            $query->where('r.REC_Fecha', '<=', $filters['hasta'].' 23:59:59');
        }

        return response()->json($query->orderByDesc('r.REC_Fecha')->paginate(20));
    }

    public function receipt(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'COMPRAS.REGISTRAR');
        $receipt = DB::table('TEC_RECEPCION as r')
            ->join('TEC_COMPRA as c', 'c.COM_Compra', '=', 'r.COM_Compra')
            ->join('TEC_PROVEEDOR as p', 'p.PRV_Proveedor', '=', 'c.PRV_Proveedor')
            ->join('TEC_USUARIO as u', 'u.USU_Usuario', '=', 'r.USU_Usuario')
            ->where('r.REC_Recepcion', $id)
            ->select(
                'r.REC_Recepcion',
                'r.COM_Compra',
                'r.REC_Fecha',
                'r.REC_Observacion',
                'c.COM_Fecha',
                'c.COM_Total',
                'c.COM_Observacion',
                'p.PRV_Identificacion_Fiscal',
                'p.PRV_Razon_Social',
                'p.PRV_Contacto',
                'p.PRV_Correo',
                'p.PRV_Telefono',
                'u.USU_Primer_Nombre',
                'u.USU_Segundo_Nombre',
                'u.USU_Primer_Apellido',
                'u.USU_Segundo_Apellido',
            )
            ->first();
        abort_unless($receipt, 404, 'Recepción no encontrada.');

        $receipt->detalles = DB::table('TEC_DETALLE_COMPRA as d')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
            ->where('d.COM_Compra', $receipt->COM_Compra)
            ->orderBy('p.PRO_Nombre')
            ->get([
                'd.DCO_Detalle_Compra',
                'd.PRO_Producto',
                'p.PRO_SKU',
                'p.PRO_Nombre',
                'd.DCO_Cantidad',
                'd.DCO_Costo_Unitario',
                'd.DCO_Subtotal',
            ]);

        return response()->json($receipt);
    }
}
