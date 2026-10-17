<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Catalogos;
use App\Support\FieldRules;
use App\Support\PasswordRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

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
        $this->requireView($request, $type === 'clientes' ? 'CLIENTES' : 'CATALOGOS');
        $config = $this->config($type);
        $query = DB::table($config['table']);
        $search = trim((string) $request->query('buscar', ''));

        if ($search !== '') {
            if ($type === 'proveedores') {
                $query->where(function ($builder) use ($search) {
                    $builder->where('PRV_Identificacion_Fiscal', 'like', "%{$search}%")
                        ->orWhere('PRV_Razon_Social', 'like', "%{$search}%")
                        ->orWhere('PRV_Contacto', 'like', "%{$search}%")
                        ->orWhere('PRV_Correo', 'like', "%{$search}%")
                        ->orWhere('PRV_Telefono', 'like', "%{$search}%");
                });
            } elseif ($type === 'clientes') {
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
                    if (ctype_digit($search)) {
                        $builder->orWhere('TEC_PRODUCTO.PRO_Producto', (int) $search);
                    }
                });
            } else {
                $query->where($config['name'], 'like', "%{$search}%");
            }
        }

        if ($type === 'proveedores') {
            $request->validate(['estado' => ['sometimes', 'in:todos,activos,inactivos']]);
            if ($request->query('estado') === 'activos') {
                $query->where('PRV_Estado', true);
            } elseif ($request->query('estado') === 'inactivos') {
                $query->where('PRV_Estado', false);
            }
        }

        if ($type === 'productos') {
            $query
                ->leftJoin('TEC_CATEGORIA', 'TEC_CATEGORIA.CAT_Categoria', '=', 'TEC_PRODUCTO.CAT_Categoria')
                ->leftJoin('TEC_MARCA', 'TEC_MARCA.MAR_Marca', '=', 'TEC_PRODUCTO.MAR_Marca')
                ->leftJoin('TEC_INVENTARIO', 'TEC_INVENTARIO.PRO_Producto', '=', 'TEC_PRODUCTO.PRO_Producto')
                ->select(
                    'TEC_PRODUCTO.PRO_Producto',
                    'TEC_PRODUCTO.CAT_Categoria',
                    'TEC_PRODUCTO.MAR_Marca',
                    'TEC_PRODUCTO.PRO_SKU',
                    'TEC_PRODUCTO.PRO_Nombre',
                    'TEC_PRODUCTO.PRO_Descripcion',
                    'TEC_PRODUCTO.PRO_Foto',
                    'TEC_PRODUCTO.PRO_Costo',
                    'TEC_PRODUCTO.PRO_Precio',
                    'TEC_PRODUCTO.PRO_Existencia_Minima',
                    'TEC_PRODUCTO.PRO_Estado',
                    'TEC_CATEGORIA.CAT_Nombre',
                    'TEC_MARCA.MAR_Nombre',
                    'TEC_INVENTARIO.INV_Existencia',
                );
        }

        $page = $query->orderBy($config['name'])->paginate(min((int) $request->query('por_pagina', 20), 100));
        if ($type === 'productos') {
            $storageBaseUrl = rtrim($request->getSchemeAndHttpHost(), '/').'/storage/';
            $page->getCollection()->transform(function ($product) use ($storageBaseUrl) {
                $product->PRO_Foto_URL = $product->PRO_Foto
                    ? $storageBaseUrl.ltrim($product->PRO_Foto, '/')
                    : null;

                return $product;
            });
        }
        if (! $request->boolean('con_opciones') || ! in_array($type, ['productos', 'proveedores'], true)) {
            return response()->json($page);
        }

        $payload = $page->toArray();
        if ($type === 'productos') {
            $payload['categorias'] = DB::table('TEC_CATEGORIA')
                ->where('CAT_Estado', true)
                ->orderBy('CAT_Nombre')
                ->get(['CAT_Categoria', 'CAT_Nombre']);
            $payload['marcas'] = DB::table('TEC_MARCA')
                ->where('MAR_Estado', true)
                ->orderBy('MAR_Nombre')
                ->get(['MAR_Marca', 'MAR_Nombre']);
        } else {
            $payload['opciones'] = DB::table('TEC_PROVEEDOR')
                ->orderBy('PRV_Razon_Social')
                ->get(['PRV_Razon_Social', 'PRV_Identificacion_Fiscal']);
        }

        return response()->json($payload);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $this->requirePermission($request, $type === 'clientes' ? 'CLIENTES.GESTIONAR' : 'CATALOGOS.GESTIONAR');
        $config = $this->config($type);
        $data = $this->validatedData($request, $type);
        if ($type === 'productos' && $request->hasFile('foto')) {
            $data['PRO_Foto'] = $this->storeProductPhoto($request);
        }
        $data['created_at'] = now();
        $data['updated_at'] = now();

        try {
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
        } catch (Throwable $exception) {
            if (isset($data['PRO_Foto'])) {
                $this->deleteProductPhoto($data['PRO_Foto']);
            }
            throw $exception;
        }

        $this->audit($request, strtoupper($type), 'CREAR', $id, $data);

        return response()->json(['id' => $id, 'message' => 'Registro creado.'], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $this->requirePermission($request, $type === 'clientes' ? 'CLIENTES.GESTIONAR' : 'CATALOGOS.GESTIONAR');
        $config = $this->config($type);
        $record = DB::table($config['table'])->where($config['key'], $id)->first();
        abort_unless($record, 404, 'Registro no encontrado.');
        $data = $this->validatedData($request, $type, $id);
        $existencia = $type === 'productos' ? $data['existencia'] ?? null : null;
        unset($data['existencia']);
        unset($data['PRO_Estado']);
        $canAdjustStock = in_array('INVENTARIO.AJUSTAR', $this->effectivePermissionCodes($request->user()), true);
        $oldPhoto = $type === 'productos' ? $record->PRO_Foto : null;
        if ($type === 'productos' && $request->hasFile('foto')) {
            $data['PRO_Foto'] = $this->storeProductPhoto($request);
        } elseif ($type === 'productos' && $request->boolean('quitar_foto')) {
            $data['PRO_Foto'] = null;
        }
        $data['updated_at'] = now();

        try {
            DB::transaction(function () use ($request, $config, $id, $data, $type, $existencia, $canAdjustStock) {
                DB::table($config['table'])->where($config['key'], $id)->update($data);
                if ($type === 'productos' && $canAdjustStock && $existencia !== null) {
                    $this->setProductStock($request, $id, (int) $existencia);
                }
            });
        } catch (Throwable $exception) {
            if (isset($data['PRO_Foto'])) {
                $this->deleteProductPhoto($data['PRO_Foto']);
            }
            throw $exception;
        }

        if ($type === 'productos') {
            if (array_key_exists('PRO_Foto', $data) && $oldPhoto && $oldPhoto !== $data['PRO_Foto']) {
                $this->deleteProductPhoto($oldPhoto);
            }
            $this->syncAlert($id);
        }
        $this->audit($request, strtoupper($type), 'MODIFICAR', $id, $data);

        return response()->json(['message' => 'Registro actualizado.']);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        return $this->setRecordState($request, $type, $id, false);
    }

    public function activate(Request $request, string $type, int $id): JsonResponse
    {
        return $this->setRecordState($request, $type, $id, true);
    }

    private function setRecordState(Request $request, string $type, int $id, bool $active): JsonResponse
    {
        $this->requireAnyPermission(
            $request,
            $type === 'clientes' ? ['CLIENTES.GESTIONAR'] : ['CATALOGOS.INACTIVAR', 'CATALOGOS.GESTIONAR']
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
            $stateColumn => $active,
            'updated_at' => now(),
        ]);
        $this->audit($request, strtoupper($type), $active ? 'ACTIVAR' : 'INACTIVAR', $id);

        return response()->json(['message' => $active ? 'Registro activado.' : 'Registro inactivado.']);
    }

    public function users(Request $request): JsonResponse
    {
        $this->requireView($request, 'USUARIOS');

        $users = DB::table('TEC_USUARIO as u')
            ->join('TEC_ROL as r', 'r.ROL_Rol', '=', 'u.ROL_Rol')
            ->select(
                'u.USU_Usuario as id',
                'u.ROL_Rol as rol_id',
                'u.USU_Primer_Nombre as primer_nombre',
                'u.USU_Segundo_Nombre as segundo_nombre',
                'u.USU_Primer_Apellido as primer_apellido',
                'u.USU_Segundo_Apellido as segundo_apellido',
                'u.USU_Correo as correo',
                'u.USU_Estado as estado',
                'u.USU_Intentos_Fallidos as intentos',
                'u.USU_Bloqueado_Hasta as bloqueado_hasta',
                'r.ROL_Nombre as rol',
            )
            ->orderBy('u.USU_Primer_Nombre')
            ->get();

        if (! $request->boolean('con_roles')) {
            return response()->json($users);
        }

        return response()->json([
            'usuarios' => $users,
            'roles' => DB::table('TEC_ROL')->orderBy('ROL_Nombre')->get(['ROL_Rol', 'ROL_Nombre', 'ROL_Estado']),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $data = $request->validate([
            'rol_id' => ['required', 'integer', Rule::exists('TEC_ROL', 'ROL_Rol')->where('ROL_Estado', true)],
            'primer_nombre' => FieldRules::personName(),
            'segundo_nombre' => FieldRules::personName(false),
            'primer_apellido' => FieldRules::personName(),
            'segundo_apellido' => FieldRules::personName(false),
            'correo' => [...FieldRules::email(true), 'unique:TEC_USUARIO,USU_Correo'],
            'contrasena' => PasswordRules::required(),
        ], [
            ...FieldRules::messages(),
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

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $this->requirePermission($request, 'USUARIOS.GESTIONAR');
        $user = User::findOrFail($id);
        $data = $request->validate([
            'rol_id' => [
                'required',
                'integer',
                Rule::exists('TEC_ROL', 'ROL_Rol')->where(function ($query) use ($user) {
                    $query->where('ROL_Estado', true)->orWhere('ROL_Rol', $user->ROL_Rol);
                }),
            ],
            'primer_nombre' => FieldRules::personName(),
            'segundo_nombre' => FieldRules::personName(false),
            'primer_apellido' => FieldRules::personName(),
            'segundo_apellido' => FieldRules::personName(false),
            'correo' => [...FieldRules::email(true), Rule::unique('TEC_USUARIO', 'USU_Correo')->ignore($id, 'USU_Usuario')],
            'contrasena' => PasswordRules::optional(),
        ], [
            ...FieldRules::messages(),
            'contrasena' => PasswordRules::message(),
        ]);

        $user->ROL_Rol = $data['rol_id'];
        $user->USU_Primer_Nombre = $data['primer_nombre'];
        $user->USU_Segundo_Nombre = $data['segundo_nombre'] ?? null;
        $user->USU_Primer_Apellido = $data['primer_apellido'];
        $user->USU_Segundo_Apellido = $data['segundo_apellido'] ?? null;
        $user->USU_Correo = $data['correo'];
        if (! empty($data['contrasena'])) {
            $user->USU_Contrasena = $data['contrasena'];
        }
        $user->save();
        $this->audit($request, 'USUARIOS', 'MODIFICAR', $id);

        return response()->json(['message' => 'Usuario actualizado.']);
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
        return response()->json(cache()->remember('tec_referencias', 300, fn () => [
            'estados_pedido' => DB::table('TEC_ESTADO_PEDIDO')->where('ESP_Estado', true)->orderBy('ESP_Orden')->get(),
            'estados_compra' => DB::table('TEC_ESTADO_COMPRA')->where('ESC_Estado', true)->orderBy('ESC_Orden')->get(),
            'estados_venta' => DB::table('TEC_ESTADO_VENTA')->where('ESV_Estado', true)->orderBy('ESV_Codigo')->get(),
            'estados_pago' => DB::table('TEC_ESTADO_PAGO')->where('EPA_Estado', true)->orderBy('EPA_Codigo')->get(),
            'metodos_pago' => DB::table('TEC_METODO_PAGO')->where('MPA_Estado', true)->orderBy('MPA_Nombre')->get(),
            'tipos_movimiento' => DB::table('TEC_TIPO_MOVIMIENTO')->where('TIM_Estado', true)->orderBy('TIM_Codigo')->get(),
        ]));
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
            'identificacion' => [...FieldRules::identification(), Rule::unique('TEC_CLIENTE', 'CLI_Identificacion')->ignore($id, 'CLI_Cliente')],
            'primer_nombre' => FieldRules::personName(),
            'segundo_nombre' => FieldRules::personName(false),
            'primer_apellido' => FieldRules::personName(),
            'segundo_apellido' => FieldRules::personName(false),
            'correo' => FieldRules::email(),
            'telefono' => FieldRules::phone(),
        ], FieldRules::messages());

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
            'identificacion_fiscal' => [...FieldRules::identification(true, 40), Rule::unique('TEC_PROVEEDOR', 'PRV_Identificacion_Fiscal')->ignore($id, 'PRV_Proveedor')],
            'razon_social' => FieldRules::companyName(),
            'contacto' => FieldRules::personName(false, 120),
            'telefono' => FieldRules::phone(),
            'correo' => FieldRules::email(),
        ], FieldRules::messages());

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
            'nombre' => [...FieldRules::catalogName(), Rule::unique($table, $name)->ignore($id, $key)],
        ], FieldRules::messages());

        return [$name => $data['nombre'], "{$prefix}_Estado" => true];
    }

    private function productData(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'categoria_id' => ['required', 'integer', 'exists:TEC_CATEGORIA,CAT_Categoria'],
            'marca_id' => ['required', 'integer', 'exists:TEC_MARCA,MAR_Marca'],
            'sku' => [...FieldRules::sku(), Rule::unique('TEC_PRODUCTO', 'PRO_SKU')->ignore($id, 'PRO_Producto')],
            'nombre' => FieldRules::catalogName(true, 120),
            'descripcion' => FieldRules::text(false, 500),
            'costo' => ['required', 'numeric', 'min:0'],
            'precio' => ['required', 'numeric', 'gt:costo'],
            'existencia_minima' => ['required', 'integer', 'min:0'],
            'existencia' => ['nullable', 'integer', 'min:0'],
            'foto' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ], [
            ...FieldRules::messages(),
            'precio.gt' => 'El precio de venta debe ser mayor que el costo del producto.',
            'foto.upload' => 'La foto no se pudo cargar. Verifique que pese menos de 2 MB e inténtelo de nuevo.',
            'foto.image' => 'El archivo seleccionado debe ser una imagen.',
            'foto.mimes' => 'La foto debe estar en formato JPG, PNG o WebP.',
            'foto.max' => 'La foto no puede superar los 2 MB.',
        ]);

        $payload = [
            'CAT_Categoria' => $data['categoria_id'],
            'MAR_Marca' => $data['marca_id'],
            'PRO_SKU' => $data['sku'],
            'PRO_Nombre' => $data['nombre'],
            'PRO_Descripcion' => $data['descripcion'] ?? null,
            'PRO_Costo' => $data['costo'],
            'PRO_Precio' => $data['precio'],
            'PRO_Existencia_Minima' => $data['existencia_minima'],
        ];

        if ($id === null) {
            $payload['PRO_Estado'] = true;
        }

        if ($id !== null && array_key_exists('existencia', $data) && $data['existencia'] !== null) {
            $payload['existencia'] = (int) $data['existencia'];
        }

        return $payload;
    }

    private function storeProductPhoto(Request $request): string
    {
        $path = $request->file('foto')->store('products', 'public');
        if (! is_string($path)) {
            throw new RuntimeException('No fue posible guardar la foto del producto.');
        }

        return $path;
    }

    private function deleteProductPhoto(string $path): void
    {
        if (! Storage::disk('public')->delete($path)) {
            Log::warning('No fue posible eliminar una foto de producto.', ['path' => $path]);
        }
    }

    private function setProductStock(Request $request, int $productId, int $after): void
    {
        $inventory = DB::table('TEC_INVENTARIO')
            ->where('PRO_Producto', $productId)
            ->lockForUpdate()
            ->first();

        if (! $inventory) {
            DB::table('TEC_INVENTARIO')->insert([
                'PRO_Producto' => $productId,
                'INV_Existencia' => $after,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $before = (int) $inventory->INV_Existencia;
        if ($before === $after) {
            return;
        }

        DB::table('TEC_INVENTARIO')->where('INV_Inventario', $inventory->INV_Inventario)->update([
            'INV_Existencia' => $after,
            'updated_at' => now(),
        ]);

        DB::table('TEC_MOVIMIENTO_INVENTARIO')->insert([
            'PRO_Producto' => $productId,
            'USU_Usuario' => $request->user()->USU_Usuario,
            'TIM_Tipo_Movimiento' => Catalogos::tipoMovimiento('AJUSTE'),
            'MOV_Cantidad' => $after - $before,
            'MOV_Existencia_Anterior' => $before,
            'MOV_Existencia_Nueva' => $after,
            'MOV_Documento_Tipo' => 'PRODUCTO',
            'MOV_Documento' => $productId,
            'MOV_Motivo' => 'Ajuste desde edición de producto',
            'MOV_Fecha' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
