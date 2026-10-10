<?php

namespace App\Support;

class Entrega
{
    public const DESDE = [
        'USUARIOS.CONSULTAR' => 1,
        'USUARIOS.GESTIONAR' => 1,
        'CLIENTES.CONSULTAR' => 1,
        'CLIENTES.GESTIONAR' => 1,
        'CATALOGOS.CONSULTAR' => 1,
        'CATALOGOS.GESTIONAR' => 1,
        'CATALOGOS.INACTIVAR' => 1,
        'COMPRAS.REGISTRAR' => 2,
        'INVENTARIO.AJUSTAR' => 2,
        'PEDIDOS.REGISTRAR' => 3,
        'PEDIDOS.APROBAR' => 3,
        'VENTAS.REGISTRAR' => 3,
        'PAGOS.REGISTRAR' => 3,
        'AUDITORIA.CONSULTAR' => 3,
        'REPORTES.CONSULTAR' => 4,
    ];

    public static function actual(): int
    {
        return (int) config('entrega.actual', 1);
    }

    public static function visible(string $codigo): bool
    {
        return (self::DESDE[$codigo] ?? 1) <= self::actual();
    }

    public static function codigosVisibles(): array
    {
        return array_keys(array_filter(
            self::DESDE,
            fn (int $desde) => $desde <= self::actual(),
        ));
    }
}
