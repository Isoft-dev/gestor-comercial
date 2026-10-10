<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            ['USUARIOS.CONSULTAR', 'Consultar usuarios', 'USUARIOS', 'Ver usuarios y roles sin crear ni modificar'],
            ['CLIENTES.CONSULTAR', 'Consultar clientes', 'CLIENTES', 'Ver el módulo de clientes sin crear ni modificar'],
            ['CATALOGOS.CONSULTAR', 'Consultar catálogos', 'CATALOGOS', 'Ver productos, categorías y marcas sin crear ni modificar'],
        ];

        foreach ($permissions as [$codigo, $nombre, $modulo, $descripcion]) {
            if (DB::table('TEC_PERMISO')->where('PER_Codigo', $codigo)->exists()) {
                continue;
            }

            DB::table('TEC_PERMISO')->insert([
                'PER_Codigo' => $codigo,
                'PER_Nombre' => $nombre,
                'PER_Modulo' => $modulo,
                'PER_Descripcion' => $descripcion,
                'PER_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $adminId = DB::table('TEC_ROL')->where('ROL_Nombre', 'ADMINISTRADOR')->value('ROL_Rol');
        if (! $adminId) {
            return;
        }

        $ids = DB::table('TEC_PERMISO')
            ->whereIn('PER_Codigo', array_column($permissions, 0))
            ->pluck('PER_Permiso');

        foreach ($ids as $id) {
            $already = DB::table('TEC_ROL_PERMISO')
                ->where('ROL_Rol', $adminId)
                ->where('PER_Permiso', $id)
                ->exists();
            if ($already) {
                continue;
            }

            DB::table('TEC_ROL_PERMISO')->insert([
                'ROL_Rol' => $adminId,
                'PER_Permiso' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('TEC_PERMISO')
            ->whereIn('PER_Codigo', ['USUARIOS.CONSULTAR', 'CLIENTES.CONSULTAR', 'CATALOGOS.CONSULTAR'])
            ->pluck('PER_Permiso');

        DB::table('TEC_ROL_PERMISO')->whereIn('PER_Permiso', $ids)->delete();
        DB::table('TEC_PERMISO')->whereIn('PER_Permiso', $ids)->delete();
    }
};
