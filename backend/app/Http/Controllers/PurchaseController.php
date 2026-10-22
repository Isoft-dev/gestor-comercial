<?php

namespace App\Http\Controllers;

use App\Support\Catalogos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

        $purchases = $this->orderedPurchases($query, $request)->paginate(20);
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
        $this->attachReceptionProgress($purchases);

        if (! $request->boolean('con_opciones')) {
            return response()->json($purchases);
        }

        $payload = $purchases->toArray();
        $payload['proveedores'] = DB::table('TEC_PROVEEDOR')
            ->where('PRV_Estado', true)
            ->orderBy('PRV_Razon_Social')
            ->get([
                'PRV_Proveedor',
                'PRV_Identificacion_Fiscal',
                'PRV_Razon_Social',
                'PRV_Contacto',
            ]);
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
        if (is_string($request->input('lineas'))) {
            $decoded = json_decode($request->input('lineas'), true);
            abort_unless(is_array($decoded), 422, 'El detalle de la recepción no es válido.');
            $request->merge(['lineas' => $decoded]);
        }

        $data = $request->validate([
            'observacion' => ['nullable', 'string', 'max:1000'],
            'lote_indivisible' => ['sometimes', 'boolean'],
            'lineas' => ['sometimes', 'array', 'min:1'],
            'lineas.*.producto_id' => ['required_with:lineas', 'integer', 'distinct'],
            'lineas.*.aceptada' => ['required_with:lineas', 'integer', 'min:0'],
            'lineas.*.danada' => ['required_with:lineas', 'integer', 'min:0'],
            'lineas.*.faltante' => ['required_with:lineas', 'integer', 'min:0'],
            'lineas.*.motivo' => ['nullable', 'string', 'max:1000'],
            'fotos' => ['sometimes', 'array'],
            'fotos.*' => ['image', 'mimes:jpeg,png,webp', 'max:2048'],
        ], [
            'fotos.*.image' => 'La evidencia de un producto dañado debe ser una imagen.',
            'fotos.*.mimes' => 'La foto debe estar en formato JPG, PNG o WebP.',
            'fotos.*.max' => 'La foto no puede superar los 2 MB.',
        ]);

        $storedPhotos = [];
        try {
            $result = DB::transaction(function () use ($request, $id, $data, &$storedPhotos) {
                $purchase = DB::table('TEC_COMPRA as c')
                    ->join('TEC_ESTADO_COMPRA as e', 'e.ESC_Estado_Compra', '=', 'c.ESC_Estado_Compra')
                    ->where('c.COM_Compra', $id)
                    ->lockForUpdate()
                    ->select('c.*', 'e.ESC_Codigo as COM_Estado')
                    ->first();
                abort_unless($purchase, 404, 'Compra no encontrada.');
                abort_unless(
                    in_array($purchase->COM_Estado, ['PENDIENTE', 'PARCIAL'], true),
                    409,
                    'La compra ya fue recibida o cancelada.',
                );

                $lines = $this->receptionLines($id, $data['lineas'] ?? null);
                $damagedOrMissing = collect($lines)->contains(
                    fn (array $line) => $line['danada'] > 0 || $line['faltante'] > 0
                );
                abort_if(
                    $request->boolean('lote_indivisible') && $damagedOrMissing,
                    422,
                    'Hay unidades dañadas o faltantes y el lote no se puede dividir. La compra sigue pendiente.',
                );

                $receipt = DB::table('TEC_RECEPCION')->insertGetId([
                    'COM_Compra' => $id,
                    'USU_Usuario' => $request->user()->USU_Usuario,
                    'REC_Fecha' => now(),
                    'REC_Observacion' => $data['observacion'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'REC_Recepcion');

                $acceptedTotal = 0;
                $damagedTotal = 0;
                foreach ($lines as $line) {
                    DB::table('TEC_DETALLE_RECEPCION')->insert([
                        'REC_Recepcion' => $receipt,
                        'PRO_Producto' => $line['producto_id'],
                        'DRE_Aceptada' => $line['aceptada'],
                        'DRE_Danada' => $line['danada'],
                        'DRE_Faltante' => $line['faltante'],
                        'DRE_Motivo' => $line['danada'] > 0 ? $line['motivo'] : null,
                    ]);

                    if ($line['aceptada'] > 0) {
                        $this->addStock(
                            $request,
                            (int) $line['producto_id'],
                            (int) $line['aceptada'],
                            $receipt,
                            'Recepción de compra',
                        );
                        $acceptedTotal += $line['aceptada'];
                    }

                    if ($line['danada'] > 0) {
                        $photo = $request->file('fotos.'.$line['producto_id']);
                        $path = null;
                        if ($photo) {
                            $path = $photo->store('receptions', 'public');
                            abort_unless(is_string($path), 422, 'No fue posible guardar la foto de la incidencia.');
                            $storedPhotos[] = $path;
                        }
                        DB::table('TEC_INCIDENCIA')->insert([
                            'COM_Compra' => $id,
                            'REC_Recepcion' => $receipt,
                            'PRO_Producto' => $line['producto_id'],
                            'USU_Usuario' => $request->user()->USU_Usuario,
                            'INC_Cantidad' => $line['danada'],
                            'INC_Estado' => 'PENDIENTE',
                            'INC_Motivo' => $line['motivo'],
                            'INC_Foto' => $path,
                            'INC_Fecha' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $damagedTotal += $line['danada'];
                    }
                }

                $stillPending = $this->purchaseStillPending($id);
                if ($acceptedTotal + $damagedTotal > 0) {
                    DB::table('TEC_COMPRA')->where('COM_Compra', $id)->update([
                        'ESC_Estado_Compra' => Catalogos::estadoCompra($stillPending ? 'PARCIAL' : 'RECIBIDA'),
                        'updated_at' => now(),
                    ]);
                }

                return [
                    'id' => $receipt,
                    'aceptadas' => $acceptedTotal,
                    'danadas' => $damagedTotal,
                    'pendiente' => $stillPending,
                ];
            });
        } catch (\Throwable $exception) {
            foreach ($storedPhotos as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }

        $this->audit($request, 'COMPRAS', 'RECIBIR', $id, [
            'aceptadas' => $result['aceptadas'],
            'danadas' => $result['danadas'],
            'pendiente' => $result['pendiente'],
        ]);

        $message = 'Compra recibida e inventario actualizado.';
        if ($result['aceptadas'] === 0 && $result['danadas'] > 0) {
            $message = 'No se ingresó inventario. Las unidades dañadas quedaron como incidencia pendiente.';
        } elseif ($result['aceptadas'] === 0) {
            $message = 'No se ingresó inventario. Las unidades faltantes siguen pendientes de recibir.';
        } elseif ($result['pendiente']) {
            $message = "Se ingresaron {$result['aceptadas']} unidades. La compra conserva cantidades pendientes.";
        } elseif ($result['danadas'] > 0) {
            $message = 'Se ingresaron las unidades aptas. Las dañadas quedaron como incidencia pendiente.';
        }

        return response()->json([
            'id' => $result['id'],
            'aceptadas' => $result['aceptadas'],
            'danadas' => $result['danadas'],
            'message' => $message,
        ]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'COMPRAS.REGISTRAR');
        $query = DB::table('TEC_INCIDENCIA as i')
            ->join('TEC_COMPRA as c', 'c.COM_Compra', '=', 'i.COM_Compra')
            ->join('TEC_PROVEEDOR as v', 'v.PRV_Proveedor', '=', 'c.PRV_Proveedor')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'i.PRO_Producto')
            ->select(
                'i.*',
                'p.PRO_SKU',
                'p.PRO_Nombre',
                'v.PRV_Razon_Social',
            );

        if ($request->filled('estado')) {
            $query->where('i.INC_Estado', $request->query('estado'));
        }

        $page = $query->orderByDesc('i.INC_Fecha')->paginate(20);
        $base = rtrim($request->getSchemeAndHttpHost(), '/').'/storage/';
        foreach ($page->items() as $item) {
            $item->INC_Foto_URL = $item->INC_Foto ? $base.ltrim($item->INC_Foto, '/') : null;
        }

        return response()->json($page);
    }

    public function resolveIncident(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'COMPRAS.REGISTRAR');
        $data = $request->validate([
            'gestion' => ['required', Rule::in(['DEVOLUCION', 'GARANTIA', 'REEMPLAZO', 'NOTA_CREDITO'])],
            'resolucion' => ['required', 'string', 'max:1000'],
            'monto_credito' => ['nullable', 'numeric', 'gt:0', 'required_if:gestion,NOTA_CREDITO'],
        ], [
            'monto_credito.required_if' => 'Indique el monto de la nota de crédito.',
        ]);

        DB::transaction(function () use ($request, $id, $data) {
            $incident = DB::table('TEC_INCIDENCIA')->where('INC_Incidencia', $id)->lockForUpdate()->first();
            abort_unless($incident, 404, 'Incidencia no encontrada.');
            abort_unless($incident->INC_Estado === 'PENDIENTE', 409, 'La incidencia ya fue gestionada.');

            if ($data['gestion'] === 'REEMPLAZO') {
                $this->addStock(
                    $request,
                    (int) $incident->PRO_Producto,
                    (int) $incident->INC_Cantidad,
                    (int) $incident->INC_Incidencia,
                    'Reemplazo de unidades dañadas',
                    'INCIDENCIA',
                );
            }

            DB::table('TEC_INCIDENCIA')->where('INC_Incidencia', $id)->update([
                'INC_Estado' => $data['gestion'],
                'INC_Resolucion' => $data['resolucion'],
                'INC_Monto_Credito' => $data['gestion'] === 'NOTA_CREDITO' ? $data['monto_credito'] : null,
                'INC_Fecha_Resolucion' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit($request, 'COMPRAS', 'RESOLVER_INCIDENCIA', $id, ['gestion' => $data['gestion']]);

        return response()->json(['message' => 'Incidencia actualizada.']);
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
            ->leftJoin('TEC_DETALLE_RECEPCION as r', function ($join) use ($id) {
                $join->on('r.PRO_Producto', '=', 'd.PRO_Producto')
                    ->where('r.REC_Recepcion', '=', $id);
            })
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
                'r.DRE_Aceptada',
                'r.DRE_Danada',
                'r.DRE_Faltante',
                'r.DRE_Motivo',
            ]);

        return response()->json($receipt);
    }

    private function attachReceptionProgress($purchases): void
    {
        $ids = collect($purchases->items())->pluck('COM_Compra');
        $progress = $ids->isEmpty()
            ? collect()
            : DB::table('TEC_DETALLE_RECEPCION as d')
                ->join('TEC_RECEPCION as r', 'r.REC_Recepcion', '=', 'd.REC_Recepcion')
                ->whereIn('r.COM_Compra', $ids)
                ->groupBy('r.COM_Compra', 'd.PRO_Producto')
                ->selectRaw('r.COM_Compra, d.PRO_Producto, SUM(d.DRE_Aceptada) as aceptada, SUM(d.DRE_Danada) as danada')
                ->get()
                ->groupBy(fn ($row) => $row->COM_Compra.'-'.$row->PRO_Producto);

        foreach ($purchases->items() as $purchase) {
            foreach ($purchase->detalles as $detail) {
                $hit = $progress->get($purchase->COM_Compra.'-'.$detail->PRO_Producto)?->first();
                $accepted = $hit ? (int) $hit->aceptada : ($purchase->COM_Estado === 'RECIBIDA' ? (int) $detail->DCO_Cantidad : 0);
                $damaged = $hit ? (int) $hit->danada : 0;
                $detail->DCO_Aceptada = $accepted;
                $detail->DCO_Danada = $damaged;
                $detail->DCO_Pendiente = max(0, (int) $detail->DCO_Cantidad - $accepted - $damaged);
            }
        }
    }

    private function receptionLines(int $purchaseId, ?array $submitted): array
    {
        $ordered = DB::table('TEC_DETALLE_COMPRA as d')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
            ->where('d.COM_Compra', $purchaseId)
            ->get(['d.PRO_Producto', 'd.DCO_Cantidad', 'p.PRO_Nombre']);
        abort_if($ordered->isEmpty(), 422, 'La compra no tiene productos para recibir.');

        $counted = DB::table('TEC_DETALLE_RECEPCION as d')
            ->join('TEC_RECEPCION as r', 'r.REC_Recepcion', '=', 'd.REC_Recepcion')
            ->where('r.COM_Compra', $purchaseId)
            ->groupBy('d.PRO_Producto')
            ->selectRaw('d.PRO_Producto, SUM(d.DRE_Aceptada) as aceptada, SUM(d.DRE_Danada) as danada')
            ->get()
            ->keyBy('PRO_Producto');

        $pending = [];
        foreach ($ordered as $detail) {
            $done = $counted->get($detail->PRO_Producto);
            $left = (int) $detail->DCO_Cantidad - (int) ($done->aceptada ?? 0) - (int) ($done->danada ?? 0);
            if ($left > 0) {
                $pending[(int) $detail->PRO_Producto] = ['pendiente' => $left, 'nombre' => $detail->PRO_Nombre];
            }
        }
        abort_if($pending === [], 409, 'La compra ya no tiene unidades pendientes de recibir.');

        if ($submitted === null) {
            return array_map(fn (int $productId, array $item) => [
                'producto_id' => $productId,
                'aceptada' => $item['pendiente'],
                'danada' => 0,
                'faltante' => 0,
                'motivo' => null,
            ], array_keys($pending), $pending);
        }

        $lines = [];
        foreach ($submitted as $line) {
            $productId = (int) $line['producto_id'];
            abort_unless(isset($pending[$productId]), 422, 'Uno de los productos no está pendiente en esta compra.');
            $accepted = (int) $line['aceptada'];
            $damaged = (int) $line['danada'];
            $missing = (int) $line['faltante'];
            $expected = $pending[$productId]['pendiente'];
            abort_unless(
                $accepted + $damaged + $missing === $expected,
                422,
                'La suma de unidades buenas, dañadas y faltantes debe ser igual a la cantidad pendiente de '.$pending[$productId]['nombre'].'.',
            );
            abort_if($damaged > 0 && trim((string) ($line['motivo'] ?? '')) === '', 422, 'Indique el motivo de las unidades dañadas.');
            $lines[] = [
                'producto_id' => $productId,
                'aceptada' => $accepted,
                'danada' => $damaged,
                'faltante' => $missing,
                'motivo' => trim((string) ($line['motivo'] ?? '')),
            ];
            unset($pending[$productId]);
        }
        abort_unless($pending === [], 422, 'Debe clasificar todos los productos que aún están pendientes.');

        return $lines;
    }

    private function purchaseStillPending(int $purchaseId): bool
    {
        $ordered = DB::table('TEC_DETALLE_COMPRA')
            ->where('COM_Compra', $purchaseId)
            ->get(['PRO_Producto', 'DCO_Cantidad']);
        $counted = DB::table('TEC_DETALLE_RECEPCION as d')
            ->join('TEC_RECEPCION as r', 'r.REC_Recepcion', '=', 'd.REC_Recepcion')
            ->where('r.COM_Compra', $purchaseId)
            ->groupBy('d.PRO_Producto')
            ->selectRaw('d.PRO_Producto, SUM(d.DRE_Aceptada) as aceptada, SUM(d.DRE_Danada) as danada')
            ->get()
            ->keyBy('PRO_Producto');

        foreach ($ordered as $detail) {
            $done = $counted->get($detail->PRO_Producto);
            $left = (int) $detail->DCO_Cantidad - (int) ($done->aceptada ?? 0) - (int) ($done->danada ?? 0);
            if ($left > 0) {
                return true;
            }
        }

        return false;
    }

    private function addStock(
        Request $request,
        int $productId,
        int $quantity,
        int $documentId,
        string $reason,
        string $documentType = 'RECEPCION',
    ): void {
        $inventory = DB::table('TEC_INVENTARIO')
            ->where('PRO_Producto', $productId)
            ->lockForUpdate()
            ->first();
        abort_unless($inventory, 409, 'No existe inventario para uno de los productos de la compra.');

        $before = (int) $inventory->INV_Existencia;
        $after = $before + $quantity;
        DB::table('TEC_INVENTARIO')->where('INV_Inventario', $inventory->INV_Inventario)->update([
            'INV_Existencia' => $after,
            'updated_at' => now(),
        ]);
        DB::table('TEC_MOVIMIENTO_INVENTARIO')->insert([
            'PRO_Producto' => $productId,
            'USU_Usuario' => $request->user()->USU_Usuario,
            'TIM_Tipo_Movimiento' => Catalogos::tipoMovimiento('ENTRADA'),
            'MOV_Cantidad' => $quantity,
            'MOV_Existencia_Anterior' => $before,
            'MOV_Existencia_Nueva' => $after,
            'MOV_Documento_Tipo' => $documentType,
            'MOV_Documento' => $documentId,
            'MOV_Motivo' => $reason,
            'MOV_Fecha' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->syncAlert($productId);
    }

    private function orderedPurchases($query, Request $request)
    {
        $sorts = [
            'id' => 'c.COM_Compra',
            'proveedor' => 'p.PRV_Razon_Social',
            'total' => 'c.COM_Total',
            'estado' => 'e.ESC_Codigo',
            'fecha' => 'c.COM_Fecha',
        ];
        $orden = (string) $request->query('orden', 'id');
        $column = $sorts[$orden] ?? $sorts['id'];
        $direction = $request->query('direccion') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($column, $direction);
        if ($orden !== 'id') {
            $query->orderBy('c.COM_Compra');
        }

        return $query;
    }
}
