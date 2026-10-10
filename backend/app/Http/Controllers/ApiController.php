<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class ApiController extends Controller
{
    protected function requirePermission(Request $request, string $codigo): void
    {
        $this->requireAnyPermission($request, [$codigo]);
    }

    protected function requireAnyPermission(Request $request, array $codigos): void
    {
        $user = $request->user();
        abort_unless($user, 403, 'No posee permisos para consultar este módulo.');

        $token = $user->currentAccessToken();
        $abilities = is_object($token) ? ($token->abilities ?? null) : null;
        if (is_array($abilities) && $abilities !== []) {
            $allowed = collect($codigos)->contains(fn (string $codigo) => $token->can($codigo));
            abort_unless($allowed, 403, 'No posee permisos para consultar este módulo.');

            return;
        }

        $allowed = collect($codigos)->intersect($this->permissionCodesForRole((int) $user->ROL_Rol))->isNotEmpty();
        abort_unless($allowed, 403, 'No posee permisos para consultar este módulo.');
    }

    protected function permissionCodesForRole(int $roleId): array
    {
        return DB::table('TEC_ROL_PERMISO as rp')
            ->join('TEC_PERMISO as p', 'p.PER_Permiso', '=', 'rp.PER_Permiso')
            ->where('rp.ROL_Rol', $roleId)
            ->where('p.PER_Estado', true)
            ->orderBy('p.PER_Codigo')
            ->pluck('p.PER_Codigo')
            ->values()
            ->all();
    }

    protected function attachGrouped(iterable $parents, string $key, string $relation, $grouped): void
    {
        foreach ($parents as $parent) {
            $parent->{$relation} = $grouped->get($parent->{$key}, collect())->values();
        }
    }

    protected function productOptions()
    {
        return DB::table('TEC_PRODUCTO as p')
            ->leftJoin('TEC_INVENTARIO as i', 'i.PRO_Producto', '=', 'p.PRO_Producto')
            ->where('p.PRO_Estado', true)
            ->orderBy('p.PRO_Nombre')
            ->get([
                'p.PRO_Producto',
                'p.PRO_SKU',
                'p.PRO_Nombre',
                'p.PRO_Costo',
                'p.PRO_Precio',
                'p.PRO_Estado',
                'i.INV_Existencia',
            ]);
    }

    protected function audit(
        ?Request $request,
        string $module,
        string $action,
        string|int $record,
        array $data = [],
        ?int $userId = null,
    ): void {
        DB::table('TEC_AUDITORIA')->insert([
            'USU_Usuario' => $userId ?? $request?->user()?->USU_Usuario,
            'AUD_Modulo' => $module,
            'AUD_Accion' => $action,
            'AUD_Registro' => (string) $record,
            'AUD_Datos' => $data === [] ? null : json_encode($data),
            'AUD_IP' => $request?->ip(),
            'AUD_Fecha' => now(),
        ]);
    }

    protected function syncAlert(int $productId): void
    {
        $product = DB::table('TEC_PRODUCTO as p')
            ->join('TEC_INVENTARIO as i', 'i.PRO_Producto', '=', 'p.PRO_Producto')
            ->where('p.PRO_Producto', $productId)
            ->select('p.PRO_Existencia_Minima', 'i.INV_Existencia')
            ->first();

        if (! $product) {
            return;
        }

        $active = DB::table('TEC_ALERTA')
            ->where('PRO_Producto', $productId)
            ->where('ALE_Estado', 'ACTIVA');

        if ($product->INV_Existencia <= $product->PRO_Existencia_Minima) {
            if (! $active->exists()) {
                DB::table('TEC_ALERTA')->insert([
                    'PRO_Producto' => $productId,
                    'ALE_Tipo' => 'INVENTARIO_MINIMO',
                    'ALE_Estado' => 'ACTIVA',
                    'ALE_Fecha_Generacion' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } else {
            $active->update([
                'ALE_Estado' => 'RESUELTA',
                'ALE_Fecha_Resolucion' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
