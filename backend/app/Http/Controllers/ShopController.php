<?php

namespace App\Http\Controllers;

use App\Support\Catalogos;
use App\Support\FieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopController extends ApiController
{
    public function products(): JsonResponse
    {
        $products = DB::table('TEC_PRODUCTO as p')
            ->join('TEC_CATEGORIA as c', 'c.CAT_Categoria', '=', 'p.CAT_Categoria')
            ->join('TEC_MARCA as m', 'm.MAR_Marca', '=', 'p.MAR_Marca')
            ->join('TEC_INVENTARIO as i', 'i.PRO_Producto', '=', 'p.PRO_Producto')
            ->where('p.PRO_Estado', true)
            ->where('c.CAT_Estado', true)
            ->where('m.MAR_Estado', true)
            ->select(
                'p.PRO_Producto as id',
                'p.PRO_SKU as sku',
                'p.PRO_Nombre as nombre',
                'p.PRO_Descripcion as descripcion',
                'p.PRO_Precio as precio',
                'c.CAT_Nombre as categoria',
                'm.MAR_Nombre as marca',
                'i.INV_Existencia as existencia',
            )
            ->orderBy('p.PRO_Nombre')
            ->get();

        return response()->json($products);
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identificacion' => FieldRules::identification(),
            'primer_nombre' => FieldRules::personName(),
            'segundo_nombre' => FieldRules::personName(false),
            'primer_apellido' => FieldRules::personName(),
            'segundo_apellido' => FieldRules::personName(false),
            'correo' => ['nullable', 'email', 'max:120'],
            'telefono' => FieldRules::phone(),
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => ['required', 'integer', 'distinct', 'exists:TEC_PRODUCTO,PRO_Producto'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1'],
        ], FieldRules::messages());

        $operatorId = DB::table('TEC_USUARIO as u')
            ->join('TEC_ROL as r', 'r.ROL_Rol', '=', 'u.ROL_Rol')
            ->where('r.ROL_Nombre', 'OPERADOR')
            ->where('u.USU_Estado', true)
            ->value('u.USU_Usuario');
        abort_unless($operatorId, 422, 'No hay un operador activo para registrar el pedido.');

        $lines = collect($data['detalles'])->map(function (array $detail) {
            $product = DB::table('TEC_PRODUCTO as p')
                ->join('TEC_INVENTARIO as i', 'i.PRO_Producto', '=', 'p.PRO_Producto')
                ->where('p.PRO_Producto', $detail['producto_id'])
                ->where('p.PRO_Estado', true)
                ->select('p.PRO_Producto', 'p.PRO_Nombre', 'p.PRO_Precio', 'i.INV_Existencia')
                ->first();
            abort_unless($product, 422, 'Uno de los productos no está disponible.');
            abort_if(
                $detail['cantidad'] > (int) $product->INV_Existencia,
                422,
                "No hay existencia suficiente de {$product->PRO_Nombre}."
            );

            return [
                'producto_id' => $detail['producto_id'],
                'cantidad' => $detail['cantidad'],
                'precio' => (float) $product->PRO_Precio,
                'subtotal' => $detail['cantidad'] * (float) $product->PRO_Precio,
            ];
        });
        $total = $lines->sum('subtotal');

        $id = DB::transaction(function () use ($request, $data, $lines, $total, $operatorId) {
            $client = DB::table('TEC_CLIENTE')->where('CLI_Identificacion', $data['identificacion'])->first();
            if ($client) {
                abort_unless($client->CLI_Estado, 422, 'El cliente está inactivo. Debe atenderlo un operador.');
                $clientId = $client->CLI_Cliente;
            } else {
                $clientId = DB::table('TEC_CLIENTE')->insertGetId([
                    'CLI_Identificacion' => $data['identificacion'],
                    'CLI_Primer_Nombre' => $data['primer_nombre'],
                    'CLI_Segundo_Nombre' => $data['segundo_nombre'] ?? null,
                    'CLI_Primer_Apellido' => $data['primer_apellido'],
                    'CLI_Segundo_Apellido' => $data['segundo_apellido'] ?? null,
                    'CLI_Correo' => $data['correo'] ?? null,
                    'CLI_Telefono' => $data['telefono'] ?? null,
                    'CLI_Estado' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], 'CLI_Cliente');
            }

            $id = DB::table('TEC_PEDIDO')->insertGetId([
                'CLI_Cliente' => $clientId,
                'USU_Usuario' => $operatorId,
                'PED_Fecha' => now(),
                'ESP_Estado_Pedido' => Catalogos::estadoPedido('CREADO'),
                'PED_Subtotal' => $total,
                'PED_Descuento_Porcentaje' => 0,
                'PED_Descuento_Monto' => 0,
                'PED_Total' => $total,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'PED_Pedido');

            foreach ($lines as $line) {
                DB::table('TEC_DETALLE_PEDIDO')->insert([
                    'PED_Pedido' => $id,
                    'PRO_Producto' => $line['producto_id'],
                    'DPE_Cantidad' => $line['cantidad'],
                    'DPE_Precio_Unitario' => $line['precio'],
                    'DPE_Subtotal' => $line['subtotal'],
                ]);
            }

            DB::table('TEC_HISTORIAL_ESTADO')->insert([
                'PED_Pedido' => $id,
                'USU_Usuario' => $operatorId,
                'HES_Estado_Anterior' => null,
                'HES_Estado_Nuevo' => 'CREADO',
                'HES_Motivo' => 'Pedido público del cliente',
                'HES_Fecha' => now(),
            ]);

            $this->audit($request, 'PEDIDOS', 'CREAR_PUBLICO', $id, ['total' => $total], (int) $operatorId);

            return $id;
        });

        return response()->json([
            'id' => $id,
            'total' => $total,
            'message' => 'Pedido registrado. Un operador validará existencia y concretará la venta.',
        ], 201);
    }
}
