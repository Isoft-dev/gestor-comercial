<?php

namespace App\Http\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InvoiceController extends ApiController
{
    public function show(Request $request, int $saleId): Response
    {
        $this->requireAnyPermission($request, ['VENTAS.REGISTRAR', 'PAGOS.REGISTRAR', 'REPORTES.CONSULTAR']);
        $invoice = DB::table('TEC_FACTURA')->where('VEN_Venta', $saleId)->first();
        abort_unless($invoice, 404, 'Esta venta no tiene factura emitida.');
        $data = json_decode($invoice->FAC_Datos, true, 512, JSON_THROW_ON_ERROR);
        $payments = DB::table('TEC_PAGO as pg')
            ->join('TEC_METODO_PAGO as mp', 'mp.MPA_Metodo_Pago', '=', 'pg.MPA_Metodo_Pago')
            ->where('pg.VEN_Venta', $saleId)
            ->orderBy('pg.PAG_Fecha')
            ->get(['pg.PAG_Fecha', 'pg.PAG_Monto', 'pg.PAG_Referencia', 'mp.MPA_Nombre']);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('invoice', compact('invoice', 'data', 'payments'))->render(), 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="factura-'.$invoice->FAC_Factura.'.pdf"',
        ]);
    }
}
