<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class Catalogos
{
    public static function estadoPedido(string $codigo): int
    {
        return self::id('TEC_ESTADO_PEDIDO', 'ESP_Estado_Pedido', 'ESP_Codigo', $codigo);
    }

    public static function estadoVenta(string $codigo): int
    {
        return self::id('TEC_ESTADO_VENTA', 'ESV_Estado_Venta', 'ESV_Codigo', $codigo);
    }

    public static function estadoPago(string $codigo): int
    {
        return self::id('TEC_ESTADO_PAGO', 'EPA_Estado_Pago', 'EPA_Codigo', $codigo);
    }

    public static function estadoCompra(string $codigo): int
    {
        return self::id('TEC_ESTADO_COMPRA', 'ESC_Estado_Compra', 'ESC_Codigo', $codigo);
    }

    public static function metodoPago(string $codigo): int
    {
        return self::id('TEC_METODO_PAGO', 'MPA_Metodo_Pago', 'MPA_Codigo', $codigo);
    }

    public static function tipoMovimiento(string $codigo): int
    {
        return self::id('TEC_TIPO_MOVIMIENTO', 'TIM_Tipo_Movimiento', 'TIM_Codigo', $codigo);
    }

    public static function codigoPedido(?int $id): ?string
    {
        return $id === null ? null : self::codigo('TEC_ESTADO_PEDIDO', 'ESP_Estado_Pedido', 'ESP_Codigo', $id);
    }

    private static function id(string $table, string $pk, string $codeColumn, string $code): int
    {
        $id = DB::table($table)->where($codeColumn, $code)->value($pk);
        if (! $id) {
            throw new RuntimeException("El catálogo {$table} no contiene el código {$code}.");
        }

        return (int) $id;
    }

    private static function codigo(string $table, string $pk, string $codeColumn, int $id): ?string
    {
        $code = DB::table($table)->where($pk, $id)->value($codeColumn);

        return $code === null ? null : (string) $code;
    }
}
