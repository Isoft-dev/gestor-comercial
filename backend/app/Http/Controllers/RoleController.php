<?php

namespace App\Http\Controllers;

use App\Support\Entrega;
use App\Support\FieldRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends ApiController
{
    private const ADMIN_ROLE = 'ADMINISTRADOR';

    public function index(Request $request): JsonResponse
    {
        $this->requireView($request, 'USUARIOS');

        $query = DB::table('TEC_ROL')->orderByDesc('ROL_Rol');
        if ($request->boolean('solo_activos')) {
            $query->where('ROL_Estado', true);
        }

        $roles = $query->get();
        if (! $request->boolean('compact')) {
            $this->attachPermissions($roles);
        }

        if (! $request->boolean('con_permisos')) {
            return response()->json($roles);
        }

        return response()->json([
            'roles' => $roles,
            'permisos' => $this->visiblePermissions(),
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->requireView($request, 'USUARIOS');

        return response()->json($this->visiblePermissions());
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $data = $this->validatedRole($request);

        $id = DB::transaction(function () use ($data) {
            $id = DB::table('TEC_ROL')->insertGetId([
                'ROL_Nombre' => $data['nombre'],
                'ROL_Estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'ROL_Rol');
            $this->syncPermissions($id, $data['permisos']);

            return $id;
        });

        $this->audit($request, 'ROLES', 'CREAR', $id, $data);

        return response()->json(['id' => $id, 'message' => 'Rol creado.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $role = $this->findRole($id);
        $data = $this->validatedRole($request, $id);
        $permissions = $this->guardAdminPermissions(
            $role->ROL_Nombre,
            $this->mergeHiddenPermissions($id, $data['permisos']),
        );

        DB::transaction(function () use ($role, $data, $permissions, $id) {
            DB::table('TEC_ROL')->where('ROL_Rol', $id)->update([
                'ROL_Nombre' => $role->ROL_Nombre === self::ADMIN_ROLE ? self::ADMIN_ROLE : $data['nombre'],
                'updated_at' => now(),
            ]);
            $this->syncPermissions($id, $permissions);
        });

        $this->audit($request, 'ROLES', 'MODIFICAR', $id, [
            'nombre' => $data['nombre'],
            'permisos' => $permissions,
        ]);

        return response()->json([
            'message' => 'Rol actualizado.',
            'permisos' => $this->permissionCodesForRole($id),
        ]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $role = $this->findRole($id);
        abort_if($role->ROL_Nombre === self::ADMIN_ROLE, 422, 'El rol Administrador no puede inactivarse.');

        $hasUsers = DB::table('TEC_USUARIO')->where('ROL_Rol', $id)->where('USU_Estado', true)->exists();
        abort_if($role->ROL_Estado && $hasUsers, 422, 'No puede inactivar un rol asignado a usuarios activos.');

        $nextState = ! $role->ROL_Estado;
        DB::table('TEC_ROL')->where('ROL_Rol', $id)->update([
            'ROL_Estado' => $nextState,
            'updated_at' => now(),
        ]);
        $this->audit($request, 'ROLES', $nextState ? 'ACTIVAR' : 'INACTIVAR', $id);

        return response()->json(['message' => 'Estado del rol actualizado.']);
    }

    private function validatedRole(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'nombre' => [...FieldRules::roleName(), Rule::unique('TEC_ROL', 'ROL_Nombre')->ignore($id, 'ROL_Rol')],
            'permisos' => ['required', 'array', 'min:1'],
            'permisos.*' => [
                'integer',
                Rule::exists('TEC_PERMISO', 'PER_Permiso')->where(function ($query) {
                    $query->whereIn('PER_Codigo', Entrega::codigosVisibles());
                }),
            ],
        ], FieldRules::messages());
    }

    private function visiblePermissions()
    {
        return DB::table('TEC_PERMISO')
            ->where('PER_Estado', true)
            ->whereIn('PER_Codigo', Entrega::codigosVisibles())
            ->orderBy('PER_Modulo')
            ->orderBy('PER_Codigo')
            ->get();
    }

    private function mergeHiddenPermissions(int $roleId, array $submitted): array
    {
        $visibleIds = DB::table('TEC_PERMISO')
            ->whereIn('PER_Codigo', Entrega::codigosVisibles())
            ->pluck('PER_Permiso');
        $kept = DB::table('TEC_ROL_PERMISO')
            ->where('ROL_Rol', $roleId)
            ->whereNotIn('PER_Permiso', $visibleIds)
            ->pluck('PER_Permiso');

        return $kept->merge($submitted)->unique()->values()->all();
    }

    private function findRole(int $id): object
    {
        $role = DB::table('TEC_ROL')->where('ROL_Rol', $id)->first();
        abort_unless($role, 404, 'Rol no encontrado.');

        return $role;
    }

    private function attachPermissions($roles): void
    {
        if ($roles->isEmpty()) {
            return;
        }

        $grouped = DB::table('TEC_ROL_PERMISO as rp')
            ->join('TEC_PERMISO as p', 'p.PER_Permiso', '=', 'rp.PER_Permiso')
            ->whereIn('rp.ROL_Rol', $roles->pluck('ROL_Rol'))
            ->where('p.PER_Estado', true)
            ->orderBy('p.PER_Codigo')
            ->get(['rp.ROL_Rol', 'p.PER_Codigo'])
            ->groupBy('ROL_Rol');

        foreach ($roles as $role) {
            $role->permisos = $grouped->get($role->ROL_Rol, collect())->pluck('PER_Codigo')->values()->all();
        }
    }

    private function syncPermissions(int $roleId, array $permissionIds): void
    {
        DB::table('TEC_ROL_PERMISO')->where('ROL_Rol', $roleId)->delete();
        foreach (array_unique($permissionIds) as $permissionId) {
            DB::table('TEC_ROL_PERMISO')->insert([
                'ROL_Rol' => $roleId,
                'PER_Permiso' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->forgetRolePermissions($roleId);
    }

    private function guardAdminPermissions(string $roleName, array $permissionIds): array
    {
        if ($roleName !== self::ADMIN_ROLE) {
            return $permissionIds;
        }

        $required = DB::table('TEC_PERMISO')
            ->whereIn('PER_Codigo', ['USUARIOS.CONSULTAR', 'USUARIOS.GESTIONAR'])
            ->pluck('PER_Permiso');

        foreach ($required as $permissionId) {
            if (! in_array((int) $permissionId, array_map('intval', $permissionIds), true)) {
                $permissionIds[] = (int) $permissionId;
            }
        }

        return array_values(array_unique(array_map('intval', $permissionIds)));
    }
}
