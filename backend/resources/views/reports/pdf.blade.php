<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        body { font-family: Helvetica, sans-serif; color: #1a1612; font-size: 12px; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        p { margin: 0 0 8px; color: #4a433b; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #d8cfc2; padding: 6px 8px; text-align: left; }
        th { background: #f7f1e8; font-size: 11px; text-transform: uppercase; }
        .empty { margin-top: 24px; }
    </style>
</head>
<body>
    <h1>TechStore — {{ $titulo }}</h1>
    <p>Período: {{ $desde }} al {{ $hasta }}</p>
    <p>{{ $criterios }}</p>
    <p>Generado: {{ $generado_en }}</p>
    @if (! empty($grafica))
        <h2 style="font-size: 14px; margin: 18px 0 8px;">Gráfica</h2>
        <table>
            @foreach ($grafica as $barra)
                <tr>
                    <td style="width: 34%; border: 0;">{{ $barra['etiqueta'] }}</td>
                    <td style="border: 0;">
                        <div style="background: #1f6b4a; height: 10px; width: {{ $barra['ancho'] }}%;"></div>
                    </td>
                    <td style="width: 18%; border: 0; text-align: right;">{{ $barra['texto'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    @if ($filas->isEmpty())
        <p class="empty">No hay registros para el período seleccionado.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columnas as $columna)
                        <th>{{ $columna }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($filas as $fila)
                    <tr>
                        @foreach ($fila as $valor)
                            <td>{{ $valor }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
