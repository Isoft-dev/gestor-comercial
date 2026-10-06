<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogController extends ApiController
{
    private const CATALOGS = [
        'clientes' => ['table' => 'TEC_CLIENTE', 'key' => 'CLI_Cliente', 'name' => 'CLI_Primer_Nombre'],
        'proveedores' => ['table' => 'TEC_PROVEEDOR', 'key' => 'PRV_Proveedor', 'name' => 'PRV_Razon_Social'],
        'categorias' => ['table' => 'TEC_CATEGORIA', 'key' => 'CAT_Categoria', 'name' => 'CAT_Nombre'],
        'marcas' => ['table' => 'TEC_MARCA', 'key' => 'MAR_Marca', 'name' => 'MAR_Nombre'],
        'productos' => ['table' => 'TEC_PRODUCTO', 'key' => 'PRO_Producto', 'name' => 'PRO_Nombre'],
    ];

    public function index(Request $request, string $type): JsonResponse
    {
        $this->requirePermission($request, $type === 'clientes' ? 'CLIENTES.GESTIONAR' : 'CATALOGOS.GESTIONAR');
        $config = $this->config($type);
        $query = DB::table($config['table']);
        $search = trim((string) $request->query('buscar', ''));

        if ($search !== '') {
            if ($type === 'clientes') {
                $query->where(function ($builder) use ($search) {
                    $builder->where('CLI_Identificacion', 'like', "%{$search}%")
                        ->orWhere('CLI_Primer_Nombre', 'like', "%{$search}%")
                        ->orWhere('CLI_Segundo_Nombre', 'like', "%{$search}%")
                        ->orWhere('CLI_Primer_Apellido', 'like', "%{$search}%")
                        ->orWhere('CLI_Segundo_Apellido', 'like', "%{$search}%");
                });
            } elseif ($type === 'productos') {
                $query->where(function ($builder) use ($search) {
                    $builder->where('TEC_PRODUCTO.PRO_Nombre', 'like', "%{$search}%")
                        ->orWhere('TEC_PRODUCTO.PRO_SKU', 'like', "%{$search}%");
                });
            } else {
                $query->where($config['name'], 'like', "%{$search}%");
            }
        }

        if ($type === 'productos') {
            $query
                ->leftJoin('TEC_CATEGORIA', 'TEC_CATEGORIA.CAT_Categoria', '=', 'TEC_PRODUCTO.CAT_Categoria')
                ->leftJoin('TEC_MARCA', 'TEC_MARCA.MAR_Marca', '=', 'TEC_PRODUCTO.MAR_Marca')
                ->leftJoin('TEC_INVENTARIO', 'TEC_INVENTARIO.PRO_Producto', '=', 'TEC_PRODUCTO.PRO_Producto')
                ->select(
                    'TEC_PRODUCTO.*',
                    'TEC_CATEGORIA.CAT_Nombre',
                    'TEC_MARCA.MAR_Nombre',
                    'TEC_INVENTARIO.INV_Existencia',
                );
        }

        return response()->json(
            $query->orderBy($config['name'])->paginate(min((int) $request->query('por_pagina', 20), 100))
        );
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $this->requirePermission($request, $type === 'clientes' ? 'CLIENTES.GESTIONAR' : 'CATALOGOS.GESTIONAR');
        $config = $this->config($type);
        $data = $this->validatedData($request, $type);
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::transaction(function () use ($config, $data, $type) {
            $id = DB::table($config['table'])->insertGetId($data, $config['key']);
            if ($type === 'productos') {
                DB::table('TEC_INVENTARIO')->insert([
                    'PRO_Producto' => $id,
                    'INV_Existencia' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $id;
        });

        $this->audit($request, strtoupper($type), 'CREAR', $id, $data);

        return response()->json(['id' => $id, 'message' => 'Registro creado.'], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->requirePermission($request, $type === 'clientes' ? 'CLIENTES.GESTIONAR' : 'CATALOGOS.GESTIONAR');
        $config = $this->config($type);
        abort_unless(DB::table($config['table'])->where($config['key'], $id)->exists(), 404, 'Registro no encontrado.');
        $data = $this->validatedData($request, $type, $id);
        $data['updated_at'] = now();
        DB::table($config['table'])->where($config['key'], $id)->update($data);
        $this->audit($request, strtoupper($type), 'MODIFICAR', $id, $data);

        return response()->json(['message' => 'Registro actualizado.']);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $this->requirePermission(
            $request,
            $type === 'clientes' ? 'CLIENTES.GESTIONAR' : 'CATALOGOS.INACTIVAR'
        );
        $config = $this->config($type);
        $exists = DB::table($config['table'])->where($config['key'], $id)->exists();
        abort_unless($exists, 404, 'Registro no encontrado.');

        $stateColumn = match ($type) {
            'clientes' => 'CLI_Estado',
            'proveedores' => 'PRV_Estado',
            'categorias' => 'CAT_Estado',
            'marcas' => 'MAR_Estado',
            'productos' => 'PRO_Estado',
        };
        DB::table($config['table'])->where($config['key'], $id)->update([
            $stateColumn => false,
            'updated_at' => now(),
        ]);
        $this->audit($request, strtoupper($type), 'INACTIVAR', $id);

        return response()->json(['message' => 'Registro inactivado.']);
    }

    public function users(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');

        return response()->json(
            DB::table('TEC_USUARIO as u')
                ->join('TEC_ROL as r', 'r.ROL_Rol', '=', 'u.ROL_Rol')
                ->select(
                    'u.USU_Usuario as id',
                    'u.USU_Primer_Nombre as primer_nombre',
                    'u.USU_Primer_Apellido as primer_apellido',
                    'u.USU_Correo as correo',
                    'u.USU_Estado as estado',
                    'u.USU_Intentos_Fallidos as intentos',
                    'u.USU_Bloqueado_Hasta as bloqueado_hasta',
                    'r.ROL_Nombre as rol',
                )
                ->orderBy('u.USU_Primer_Nombre')
                ->get()
        );
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $data = $request->validate([
            'rol_id' => ['required', 'integer', Rule::exists('TEC_ROL', 'ROL_Rol')->where('ROL_Estado', true)],
            'primer_nombre' => ['required', 'string', 'max:60'],
            'segundo_nombre' => ['nullable', 'string', 'max:60'],
            'primer_apellido' => ['required', 'string', 'max:60'],
            'segundo_apellido' => ['nullable', 'string', 'max:60'],
            'correo' => ['required', 'email', 'max:120', 'unique:TEC_USUARIO,USU_Correo'],
            'contrasena' => PasswordRules::required(),
        ], [
            'contrasena' => PasswordRules::message(),
        ]);

        $user = User::create([
            'ROL_Rol' => $data['rol_id'],
            'USU_Primer_Nombre' => $data['primer_nombre'],
            'USU_Segundo_Nombre' => $data['segundo_nombre'] ?? null,
            'USU_Primer_Apellido' => $data['primer_apellido'],
            'USU_Segundo_Apellido' => $data['segundo_apellido'] ?? null,
            'USU_Correo' => $data['correo'],
            'USU_Contrasena' => $data['contrasena'],
            'USU_Estado' => true,
        ]);
        $this->audit($request, 'USUARIOS', 'CREAR', $user->USU_Usuario);

        return response()->json(['id' => $user->USU_Usuario, 'message' => 'Usuario creado.'], 201);
    }

    public function toggleUser(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $user = User::findOrFail($id);
        abort_if($user->USU_Usuario === $request->user()->USU_Usuario, 422, 'No puede desactivar su propio usuario.');
        $user->USU_Estado = ! $user->USU_Estado;
        if ($user->USU_Estado) {
            $user->USU_Intentos_Fallidos = 0;
            $user->USU_Bloqueado_Hasta = null;
        }
        $user->save();
        $user->tokens()->delete();
        $this->audit($request, 'USUARIOS', $user->USU_Estado ? 'ACTIVAR' : 'INACTIVAR', $id);

        return response()->json(['message' => 'Estado actualizado. Los usuarios no se eliminan, solo se desactivan.']);
    }

    public function unlockUser(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $user = User::findOrFail($id);
        $user->USU_Intentos_Fallidos = 0;
        $user->USU_Bloqueado_Hasta = null;
        $user->save();
        $this->audit($request, 'USUARIOS', 'DESBLOQUEAR', $id);

        return response()->json(['message' => 'Usuario desbloqueado.']);
    }

    public function references(): JsonResponse
    {
        return response()->json([
            'estados_pedido' => DB::table('TEC_ESTADO_PEDIDO')->where('ESP_Estado', true)->orderBy('ESP_Orden')->get(),
            'estados_compra' => DB::table('TEC_ESTADO_COMPRA')->where('ESC_Estado', true)->orderBy('ESC_Orden')->get(),
            'estados_venta' => DB::table('TEC_ESTADO_VENTA')->where('ESV_Estado', true)->orderBy('ESV_Codigo')->get(),
            'estados_pago' => DB::table('TEC_ESTADO_PAGO')->where('EPA_Estado', true)->orderBy('EPA_Codigo')->get(),
            'metodos_pago' => DB::table('TEC_METODO_PAGO')->where('MPA_Estado', true)->orderBy('MPA_Nombre')->get(),
            'tipos_movimiento' => DB::table('TEC_TIPO_MOVIMIENTO')->where('TIM_Estado', true)->orderBy('TIM_Codigo')->get(),
        ]);
    }

    private function config(string $type): array
    {
        abort_unless(isset(self::CATALOGS[$type]), 404, 'Catálogo no encontrado.');

        return self::CATALOGS[$type];
    }

    private function validatedData(Request $request, string $type, ?int $id = null): array
    {
        return match ($type) {
            'clientes' => $this->clientData($request, $id),
            'proveedores' => $this->providerData($request, $id),
            'categorias' => $this->simpleData($request, 'CAT', 'TEC_CATEGORIA', 'CAT_Categoria', $id),
            'marcas' => $this->simpleData($request, 'MAR', 'TEC_MARCA', 'MAR_Marca', $id),
            'productos' => $this->productData($request, $id),
        };
    }

    private function clientData(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'identificacion' => ['required', 'string', 'max:30', Rule::unique('TEC_CLIENTE', 'CLI_Identificacion')->ignore($id, 'CLI_Cliente')],
            'primer_nombre' => ['required', 'string', 'max:60'],
            'segundo_nombre' => ['nullable', 'string', 'max:60'],
            'primer_apellido' => ['required', 'string', 'max:60'],
            'segundo_apellido' => ['nullable', 'string', 'max:60'],
            'correo' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ]);

        return [
            'CLI_Identificacion' => $data['identificacion'],
            'CLI_Primer_Nombre' => $data['primer_nombre'],
            'CLI_Segundo_Nombre' => $data['segundo_nombre'] ?? null,
            'CLI_Primer_Apellido' => $data['primer_apellido'],
            'CLI_Segundo_Apellido' => $data['segundo_apellido'] ?? null,
            'CLI_Correo' => $data['correo'] ?? null,
            'CLI_Telefono' => $data['telefono'] ?? null,
            'CLI_Estado' => true,
        ];
    }

    private function providerData(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'identificacion_fiscal' => ['required', 'string', 'max:40', Rule::unique('TEC_PROVEEDOR', 'PRV_Identificacion_Fiscal')->ignore($id, 'PRV_Proveedor')],
            'razon_social' => ['required', 'string', 'max:120'],
            'contacto' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:120'],
        ]);

        return [
            'PRV_Identificacion_Fiscal' => $data['identificacion_fiscal'],
            'PRV_Razon_Social' => $data['razon_social'],
            'PRV_Contacto' => $data['contacto'] ?? null,
            'PRV_Telefono' => $data['telefono'] ?? null,
            'PRV_Correo' => $data['correo'] ?? null,
            'PRV_Estado' => true,
        ];
    }

    private function simpleData(Request $request, string $prefix, string $table, string $key, ?int $id): array
    {
        $name = "{$prefix}_Nombre";
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique($table, $name)->ignore($id, $key)],
        ]);

        return [$name => $data['nombre'], "{$prefix}_Estado" => true];
    }

    private function productData(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'categoria_id' => ['required', 'integer', 'exists:TEC_CATEGORIA,CAT_Categoria'],
            'marca_id' => ['required', 'integer', 'exists:TEC_MARCA,MAR_Marca'],
            'sku' => ['required', 'string', 'max:40', Rule::unique('TEC_PRODUCTO', 'PRO_SKU')->ignore($id, 'PRO_Producto')],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string'],
            'costo' => ['required', 'numeric', 'min:0'],
            'precio' => ['required', 'numeric', 'gt:costo'],
            'existencia_minima' => ['required', 'integer', 'min:0'],
        ]);

        return [
            'CAT_Categoria' => $data['categoria_id'],
            'MAR_Marca' => $data['marca_id'],
            'PRO_SKU' => $data['sku'],
            'PRO_Nombre' => $data['nombre'],
            'PRO_Descripcion' => $data['descripcion'] ?? null,
            'PRO_Costo' => $data['costo'],
            'PRO_Precio' => $data['precio'],
            'PRO_Existencia_Minima' => $data['existencia_minima'],
            'PRO_Estado' => true,
        ];
    }
}
