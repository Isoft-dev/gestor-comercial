<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends ApiController
{
    private const ADMIN_ROLE = 'ADMINISTRADOR';

    public function index(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');

        $query = DB::table('TEC_ROL')->orderBy('ROL_Nombre');
        if ($request->boolean('solo_activos')) {
            $query->where('ROL_Estado', true);
        }

        $roles = $query->get();
        foreach ($roles as $role) {
            $role->permisos = $this->permissionCodes((int) $role->ROL_Rol);
        }

        return response()->json($roles);
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');

        return response()->json(
            DB::table('TEC_PERMISO')
                ->where('PER_Estado', true)
                ->orderBy('PER_Modulo')
                ->orderBy('PER_Codigo')
                ->get()
        );
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
        $permissions = $this->guardAdminPermissions($role->ROL_Nombre, $data['permisos']);

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

        return response()->json(['message' => 'Rol actualizado.']);
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
            'nombre' => ['required', 'string', 'max:30', Rule::unique('TEC_ROL', 'ROL_Nombre')->ignore($id, 'ROL_Rol')],
            'permisos' => ['required', 'array', 'min:1'],
            'permisos.*' => ['integer', 'exists:TEC_PERMISO,PER_Permiso'],
        ]);
    }

    private function findRole(int $id): object
    {
        $role = DB::table('TEC_ROL')->where('ROL_Rol', $id)->first();
        abort_unless($role, 404, 'Rol no encontrado.');

        return $role;
    }

    private function permissionCodes(int $roleId): array
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
    }

    private function guardAdminPermissions(string $roleName, array $permissionIds): array
    {
        if ($roleName !== self::ADMIN_ROLE) {
            return $permissionIds;
        }

        $required = DB::table('TEC_PERMISO')->where('PER_Codigo', 'USUARIOS.GESTIONAR')->value('PER_Permiso');
        if ($required && ! in_array((int) $required, array_map('intval', $permissionIds), true)) {
            $permissionIds[] = (int) $required;
        }

        return array_values(array_unique(array_map('intval', $permissionIds)));
    }
}
