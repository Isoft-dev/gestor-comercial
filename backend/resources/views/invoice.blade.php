<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ str_pad($invoice->FAC_Factura, 8, '0', STR_PAD_LEFT) }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10.5px; color: #253246; line-height: 1.4; }
        .band { height: 8px; background: #b77916; }
        .page { padding: 34px 44px 90px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .brand { font-size: 26px; font-weight: bold; color: #1d2a3d; letter-spacing: -0.5px; }
        .brand span { color: #b77916; }
        .tagline { color: #7b8698; font-size: 10px; margin-top: 2px; }
        .doc-box { border: 1.5px solid #b77916; border-radius: 6px; padding: 10px 14px; text-align: right; }
        .doc-title { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #b77916; font-weight: bold; }
        .doc-number { font-size: 19px; font-weight: bold; color: #1d2a3d; margin: 3px 0 4px; }
        .doc-meta { color: #5f6b7d; font-size: 9.5px; }
        .badge { display: inline-block; margin-top: 6px; padding: 2px 9px; border-radius: 9px; background: #e5f4ea; color: #1f7a45; font-size: 9px; font-weight: bold; letter-spacing: .5px; }
        .section { margin-top: 26px; }
        .card { background: #f6f7f9; border-radius: 6px; padding: 12px 14px; height: 74px; }
        .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; color: #8a94a5; font-weight: bold; margin-bottom: 4px; }
        .client-name { font-size: 13px; font-weight: bold; color: #1d2a3d; margin-bottom: 4px; }
        .muted { color: #5f6b7d; }
        .kv td { padding: 2px 0; }
        .kv td.k { color: #8a94a5; width: 70px; }
        .items th { background: #1d2a3d; color: #fff; font-size: 9px; text-transform: uppercase; letter-spacing: .8px; padding: 8px 10px; text-align: left; }
        .items td { padding: 9px 10px; border-bottom: 1px solid #e4e7ec; }
        .items tr.alt td { background: #fafbfc; }
        .num, .items th.num, .payments th.num { text-align: right; white-space: nowrap; }
        .center, .items th.center { text-align: center; }
        .sku { font-size: 9.5px; color: #5f6b7d; letter-spacing: .3px; }
        .totals td { padding: 5px 10px; }
        .totals .grand td { background: #b77916; color: #fff; font-size: 13px; font-weight: bold; padding: 9px 10px; }
        .payments th { font-size: 8.5px; text-transform: uppercase; letter-spacing: .8px; color: #8a94a5; text-align: left; padding: 4px 6px; border-bottom: 1px solid #e4e7ec; }
        .payments td { padding: 5px 6px; border-bottom: 1px solid #f0f2f5; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 14px 44px 18px; border-top: 1px solid #e4e7ec; color: #8a94a5; font-size: 8.5px; }
        .thanks { color: #1d2a3d; font-size: 11px; font-weight: bold; margin-bottom: 3px; }
    </style>
</head>
<body>
@php
    $client = $data['cliente'];
    $number = str_pad($invoice->FAC_Factura, 8, '0', STR_PAD_LEFT);
    $date = \Illuminate\Support\Carbon::parse($invoice->FAC_Fecha);
    $money = fn ($value) => 'Q '.number_format((float) $value, 2);
@endphp
<div class="band"></div>
<div class="page">
    <table>
        <tr>
            <td style="width: 58%;">
                <div class="brand">Tech<span>Store</span></div>
                <div class="tagline">Tecnolog&iacute;a y accesorios</div>
                <div class="muted" style="margin-top: 10px;">Ciudad de Guatemala, Guatemala</div>
            </td>
            <td>
                <div class="doc-box">
                    <div class="doc-title">Factura</div>
                    <div class="doc-number">No. {{ $number }}</div>
                    <div class="doc-meta">Emitida: {{ $date->format('d/m/Y H:i') }}</div>
                    <div class="doc-meta">Venta #{{ $invoice->VEN_Venta }} &middot; Pedido #{{ $data['pedido'] }}</div>
                    <span class="badge">PAGADA</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="section">
        <tr>
            <td style="width: 58%; padding-right: 12px;">
                <div class="card">
                    <div class="label">Facturar a</div>
                    <div class="client-name">{{ $client['razon_social'] }}</div>
                    <table class="kv">
                        <tr><td class="k">NIT</td><td>{{ $client['nit'] }}</td></tr>
                        <tr><td class="k">Direcci&oacute;n</td><td>{{ $client['direccion_fiscal'] }}</td></tr>
                    </table>
                </div>
            </td>
            <td>
                <div class="card">
                    <div class="label">Contacto</div>
                    <table class="kv">
                        @if (! empty($client['nombre']) && $client['nombre'] !== $client['razon_social'])
                            <tr><td class="k">Nombre</td><td>{{ $client['nombre'] }}</td></tr>
                        @endif
                        <tr><td class="k">Correo</td><td>{{ $client['correo'] ?: '—' }}</td></tr>
                        <tr><td class="k">Tel&eacute;fono</td><td>{{ $client['telefono'] ?: '—' }}</td></tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>

    <table class="items section">
        <thead>
            <tr>
                <th style="width: 6%;" class="center">#</th>
                <th style="width: 18%;">SKU</th>
                <th>Descripci&oacute;n</th>
                <th style="width: 10%;" class="center">Cant.</th>
                <th style="width: 15%;" class="num">P. unitario</th>
                <th style="width: 15%;" class="num">Importe</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($data['detalles'] as $index => $line)
            <tr class="{{ $index % 2 ? 'alt' : '' }}">
                <td class="center muted">{{ $index + 1 }}</td>
                <td class="sku">{{ $line['PRO_SKU'] ?? '—' }}</td>
                <td>{{ $line['PRO_Nombre'] }}
                    @if (($line['DPE_Descuento_Porcentaje'] ?? 0) > 0)
                        <br><span class="muted">Descuento: {{ $line['DPE_Descuento_Porcentaje'] }}% (- {{ $money($line['DPE_Descuento_Monto']) }})</span>
                    @endif
                </td>
                <td class="center">{{ $line['DPE_Cantidad'] }}</td>
                <td class="num">{{ $money($line['DPE_Precio_Unitario']) }}</td>
                <td class="num">{{ $money($line['DPE_Subtotal']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="section" style="margin-top: 16px;">
        <tr>
            <td style="width: 55%; padding-right: 24px;">
                @if ($payments->isNotEmpty())
                    <div class="label">Pagos recibidos</div>
                    <table class="payments">
                        <thead><tr><th>Fecha</th><th>M&eacute;todo</th><th>Referencia</th><th class="num">Monto</th></tr></thead>
                        <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($payment->PAG_Fecha)->format('d/m/Y') }}</td>
                                <td>{{ $payment->MPA_Nombre }}</td>
                                <td class="muted">{{ $payment->PAG_Referencia ?: '—' }}</td>
                                <td class="num">{{ $money($payment->PAG_Monto) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </td>
            <td>
                <table class="totals">
                    <tr><td class="muted">Subtotal</td><td class="num">{{ $money($data['subtotal']) }}</td></tr>
                    <tr>
                        <td class="muted">Descuento {{ (float) $data['descuento_porcentaje'] > 0 ? 'general ('.rtrim(rtrim(number_format((float) $data['descuento_porcentaje'], 2), '0'), '.').'%)' : 'por producto' }}</td>
                        <td class="num">- {{ $money($data['descuento']) }}</td>
                    </tr>
                    <tr class="grand"><td>Total</td><td class="num">{{ $money($data['total']) }}</td></tr>
                </table>
                <div class="muted" style="text-align: right; margin-top: 6px; font-size: 9px;">Montos expresados en quetzales (GTQ)</div>
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    <div class="thanks">&iexcl;Gracias por su compra!</div>
    TechStore &middot; Factura No. {{ $number }} &middot; Documento generado el {{ $date->format('d/m/Y') }}
</div>
</body>
</html>
