<?php

namespace App\Http\Controllers;

use App\Support\Catalogos;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends ApiController
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'REPORTES.CONSULTAR');
        $from = $request->query('desde', now()->startOfMonth()->toDateString());
        $to = $request->query('hasta', now()->toDateString());
        [$start, $end] = $this->periodBounds($from, $to);

        $sales = DB::table('TEC_VENTA')->whereBetween('VEN_Fecha', [$start, $end]);
        $purchases = DB::table('TEC_COMPRA')->whereBetween('COM_Fecha', [$start, $end]);

        return response()->json([
            'periodo' => ['desde' => $from, 'hasta' => $to],
            'indicadores' => [
                'ventas' => (float) $sales->sum('VEN_Total'),
                'saldo_pendiente' => (float) DB::table('TEC_VENTA')->sum('VEN_Saldo'),
                'pedidos_por_aprobar' => DB::table('TEC_PEDIDO')
                    ->where('ESP_Estado_Pedido', Catalogos::estadoPedido('PENDIENTE_APROBACION'))
                    ->count(),
                'productos_bajo_minimo' => DB::table('TEC_ALERTA')->where('ALE_Estado', 'ACTIVA')->count(),
                'compras' => (float) $purchases->sum('COM_Total'),
            ],
            'ventas_por_fecha' => DB::table('TEC_VENTA')
                ->whereBetween('VEN_Fecha', [$start, $end])
                ->selectRaw('DATE(VEN_Fecha) as fecha, SUM(VEN_Total) as total')
                ->groupByRaw('DATE(VEN_Fecha)')
                ->orderBy('fecha')
                ->get(),
            'productos_mas_vendidos' => $this->bestSellers($from, $to, 5),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'REPORTES.CONSULTAR');
        $criteria = $this->criteria($request);
        $payload = $this->reportPayload($criteria);

        return response()->json($payload);
    }

    public function exportPdf(Request $request, string $tipo): Response
    {
        $this->requirePermission($request, 'REPORTES.CONSULTAR');
        abort_unless(in_array($tipo, ['ventas', 'productos', 'inventario', 'compras'], true), 404, 'Reporte no encontrado.');

        $criteria = $this->criteria($request);
        $payload = $this->reportPayload($criteria);
        $report = $this->pdfDefinition($tipo, $payload);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('reports.pdf', [
            'titulo' => $report['titulo'],
            'desde' => $criteria['desde'],
            'hasta' => $criteria['hasta'],
            'criterios' => $payload['criterios'],
            'generado_en' => $payload['generado_en'],
            'columnas' => $report['columnas'],
            'filas' => $report['filas'],
        ])->render());
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $filename = "{$tipo}_{$criteria['desde']}_{$criteria['hasta']}.pdf";

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    public function exportSales(Request $request): StreamedResponse
    {
        $this->requirePermission($request, 'REPORTES.CONSULTAR');
        $criteria = $this->criteria($request);
        $from = $criteria['desde'];
        $to = $criteria['hasta'];
        [$start, $end] = $this->periodBounds($from, $to);
        $rows = DB::table('TEC_VENTA as v')
            ->join('TEC_PEDIDO as p', 'p.PED_Pedido', '=', 'v.PED_Pedido')
            ->join('TEC_CLIENTE as c', 'c.CLI_Cliente', '=', 'p.CLI_Cliente')
            ->join('TEC_ESTADO_PAGO as ep', 'ep.EPA_Estado_Pago', '=', 'v.EPA_Estado_Pago')
            ->whereBetween('v.VEN_Fecha', [$start, $end])
            ->when($criteria['estado_pago'], fn ($query) => $query->where('ep.EPA_Codigo', $criteria['estado_pago']))
            ->select(
                'v.VEN_Venta',
                'v.VEN_Fecha',
                'c.CLI_Identificacion',
                'c.CLI_Primer_Nombre',
                'c.CLI_Primer_Apellido',
                'v.VEN_Total',
                'v.VEN_Pagado',
                'v.VEN_Saldo',
                'ep.EPA_Codigo as VEN_Estado_Pago',
            )
            ->orderBy('v.VEN_Fecha')
            ->get();

        return response()->streamDownload(function () use ($rows, $from, $to) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ["Reporte de ventas {$from} a {$to}"]);
            fputcsv($output, ['Venta', 'Fecha', 'Identificación', 'Cliente', 'Total (Q)', 'Pagado (Q)', 'Saldo (Q)', 'Estado']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row->VEN_Venta,
                    $row->VEN_Fecha,
                    $row->CLI_Identificacion,
                    "{$row->CLI_Primer_Nombre} {$row->CLI_Primer_Apellido}",
                    $row->VEN_Total,
                    $row->VEN_Pagado,
                    $row->VEN_Saldo,
                    $row->VEN_Estado_Pago,
                ]);
            }
            fclose($output);
        }, "ventas_{$from}_{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function criteria(Request $request): array
    {
        return [
            'desde' => $request->query('desde', now()->startOfMonth()->toDateString()),
            'hasta' => $request->query('hasta', now()->toDateString()),
            'estado_pago' => $request->filled('estado_pago') ? (string) $request->query('estado_pago') : null,
            'categoria_id' => $request->filled('categoria_id') ? (int) $request->query('categoria_id') : null,
            'proveedor_id' => $request->filled('proveedor_id') ? (int) $request->query('proveedor_id') : null,
        ];
    }

    private function reportPayload(array $criteria): array
    {
        [$start, $end] = $this->periodBounds($criteria['desde'], $criteria['hasta']);
        $sales = DB::table('TEC_VENTA as v')->whereBetween('v.VEN_Fecha', [$start, $end]);
        if ($criteria['estado_pago']) {
            $sales->join('TEC_ESTADO_PAGO as ep', 'ep.EPA_Estado_Pago', '=', 'v.EPA_Estado_Pago')
                ->where('ep.EPA_Codigo', $criteria['estado_pago']);
        }

        return [
            'generado_en' => now()->toDateTimeString(),
            'filtros' => $criteria,
            'criterios' => $this->criteriaLabel($criteria),
            'opciones' => [
                'estados_pago' => DB::table('TEC_ESTADO_PAGO')->where('EPA_Estado', true)->orderBy('EPA_Nombre')->get(['EPA_Codigo', 'EPA_Nombre']),
                'categorias' => DB::table('TEC_CATEGORIA')->where('CAT_Estado', true)->orderBy('CAT_Nombre')->get(['CAT_Categoria', 'CAT_Nombre']),
                'proveedores' => DB::table('TEC_PROVEEDOR')->where('PRV_Estado', true)->orderBy('PRV_Razon_Social')->get(['PRV_Proveedor', 'PRV_Razon_Social']),
            ],
            'ventas_por_fecha' => $sales
                ->selectRaw('DATE(v.VEN_Fecha) as fecha, COUNT(v.VEN_Venta) as cantidad, SUM(v.VEN_Total) as total')
                ->groupByRaw('DATE(v.VEN_Fecha)')
                ->orderBy('fecha')
                ->get(),
            'productos_mas_vendidos' => $this->bestSellers($criteria['desde'], $criteria['hasta'], 20, $criteria['categoria_id']),
            'inventario_bajo' => DB::table('TEC_INVENTARIO as i')
                ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'i.PRO_Producto')
                ->whereColumn('i.INV_Existencia', '<=', 'p.PRO_Existencia_Minima')
                ->when($criteria['categoria_id'], fn ($query) => $query->where('p.CAT_Categoria', $criteria['categoria_id']))
                ->select('p.PRO_SKU', 'p.PRO_Nombre', 'i.INV_Existencia', 'p.PRO_Existencia_Minima')
                ->orderBy('i.INV_Existencia')
                ->get(),
            'compras_por_proveedor' => DB::table('TEC_COMPRA as c')
                ->join('TEC_PROVEEDOR as p', 'p.PRV_Proveedor', '=', 'c.PRV_Proveedor')
                ->whereBetween('c.COM_Fecha', [$start, $end])
                ->when($criteria['proveedor_id'], fn ($query) => $query->where('c.PRV_Proveedor', $criteria['proveedor_id']))
                ->selectRaw('p.PRV_Razon_Social as proveedor, COUNT(c.COM_Compra) as cantidad, SUM(c.COM_Total) as total')
                ->groupBy('p.PRV_Proveedor', 'p.PRV_Razon_Social')
                ->orderByDesc('total')
                ->get(),
        ];
    }

    private function criteriaLabel(array $criteria): string
    {
        $parts = [];
        if ($criteria['estado_pago']) {
            $name = DB::table('TEC_ESTADO_PAGO')->where('EPA_Codigo', $criteria['estado_pago'])->value('EPA_Nombre');
            $parts[] = 'Estado de pago: '.($name ?: $criteria['estado_pago']);
        }
        if ($criteria['categoria_id']) {
            $name = DB::table('TEC_CATEGORIA')->where('CAT_Categoria', $criteria['categoria_id'])->value('CAT_Nombre');
            $parts[] = 'Categoría: '.($name ?: $criteria['categoria_id']);
        }
        if ($criteria['proveedor_id']) {
            $name = DB::table('TEC_PROVEEDOR')->where('PRV_Proveedor', $criteria['proveedor_id'])->value('PRV_Razon_Social');
            $parts[] = 'Proveedor: '.($name ?: $criteria['proveedor_id']);
        }

        return $parts ? implode(' · ', $parts) : 'Sin filtros adicionales';
    }

    private function pdfDefinition(string $tipo, array $payload): array
    {
        return match ($tipo) {
            'ventas' => [
                'titulo' => 'Ventas por fecha',
                'columnas' => ['Fecha', 'Cantidad', 'Total (Q)'],
                'filas' => collect($payload['ventas_por_fecha'])->map(fn ($row) => [
                    $row->fecha,
                    $row->cantidad,
                    'Q '.number_format((float) $row->total, 2),
                ]),
            ],
            'productos' => [
                'titulo' => 'Productos más vendidos',
                'columnas' => ['SKU', 'Producto', 'Unidades', 'Total (Q)'],
                'filas' => collect($payload['productos_mas_vendidos'])->map(fn ($row) => [
                    $row->PRO_SKU,
                    $row->PRO_Nombre,
                    $row->cantidad,
                    'Q '.number_format((float) $row->total, 2),
                ]),
            ],
            'inventario' => [
                'titulo' => 'Inventario bajo',
                'columnas' => ['SKU', 'Producto', 'Stock', 'Mínimo'],
                'filas' => collect($payload['inventario_bajo'])->map(fn ($row) => [
                    $row->PRO_SKU,
                    $row->PRO_Nombre,
                    $row->INV_Existencia,
                    $row->PRO_Existencia_Minima,
                ]),
            ],
            'compras' => [
                'titulo' => 'Compras por proveedor',
                'columnas' => ['Proveedor', 'Compras', 'Total (Q)'],
                'filas' => collect($payload['compras_por_proveedor'])->map(fn ($row) => [
                    $row->proveedor,
                    $row->cantidad,
                    'Q '.number_format((float) $row->total, 2),
                ]),
            ],
        };
    }

    private function periodBounds(string $from, string $to): array
    {
        return [$from.' 00:00:00', $to.' 23:59:59'];
    }

    private function bestSellers(string $from, string $to, int $limit, ?int $categoryId = null)
    {
        [$start, $end] = $this->periodBounds($from, $to);

        return DB::table('TEC_DETALLE_VENTA as d')
            ->join('TEC_VENTA as v', 'v.VEN_Venta', '=', 'd.VEN_Venta')
            ->join('TEC_PRODUCTO as p', 'p.PRO_Producto', '=', 'd.PRO_Producto')
            ->whereBetween('v.VEN_Fecha', [$start, $end])
            ->when($categoryId, fn ($query) => $query->where('p.CAT_Categoria', $categoryId))
            ->selectRaw('p.PRO_SKU, p.PRO_Nombre, SUM(d.DVE_Cantidad) as cantidad, SUM(d.DVE_Subtotal) as total')
            ->groupBy('p.PRO_Producto', 'p.PRO_SKU', 'p.PRO_Nombre')
            ->orderByDesc('cantidad')
            ->limit($limit)
            ->get();
    }
}
