<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'AUDITORIA.CONSULTAR');
        $query = DB::table('TEC_AUDITORIA as a')
            ->leftJoin('TEC_USUARIO as u', 'u.USU_Usuario', '=', 'a.USU_Usuario')
            ->select(
                'a.*',
                'u.USU_Primer_Nombre',
                'u.USU_Primer_Apellido',
                'u.USU_Correo',
            );

        if ($request->filled('usuario_id')) {
            $query->where('a.USU_Usuario', $request->integer('usuario_id'));
        }
        if ($request->filled('modulo')) {
            $query->where('a.AUD_Modulo', 'like', '%'.$request->query('modulo').'%');
        }
        if ($request->filled('accion')) {
            $query->where('a.AUD_Accion', 'like', '%'.$request->query('accion').'%');
        }
        if ($request->filled('desde')) {
            $query->whereDate('a.AUD_Fecha', '>=', $request->query('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('a.AUD_Fecha', '<=', $request->query('hasta'));
        }

        $page = $query->orderByDesc('a.AUD_Fecha')->orderByDesc('a.AUD_Auditoria')->paginate(30);
        $payload = $page->toArray();
        $payload['catalogos'] = [
            'modulos' => DB::table('TEC_AUDITORIA')
                ->select('AUD_Modulo')
                ->distinct()
                ->orderBy('AUD_Modulo')
                ->pluck('AUD_Modulo'),
            'acciones' => DB::table('TEC_AUDITORIA')
                ->select('AUD_Accion')
                ->distinct()
                ->orderBy('AUD_Accion')
                ->pluck('AUD_Accion'),
        ];

        return response()->json($payload);
    }
}
