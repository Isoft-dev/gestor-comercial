<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $now = now();

        $this->seedCatalogs();
        $this->seedSecurity($now);

        foreach (['Periféricos', 'Conectividad', 'Almacenamiento'] as $name) {
            DB::table('TEC_CATEGORIA')->insert([
                'CAT_Nombre' => $name,
                'CAT_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach (['Logitech', 'Kingston', 'TechLine'] as $name) {
            DB::table('TEC_MARCA')->insert([
                'MAR_Nombre' => $name,
                'MAR_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('TEC_CLIENTE')->insert([
            'CLI_Identificacion' => 'CF-001',
            'CLI_Primer_Nombre' => 'Cliente',
            'CLI_Primer_Apellido' => 'Demostración',
            'CLI_Correo' => 'cliente@example.test',
            'CLI_Telefono' => '55550101',
            'CLI_Estado' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('TEC_PROVEEDOR')->insert([
            'PRV_Identificacion_Fiscal' => 'NIT-TECH-001',
            'PRV_Razon_Social' => 'Distribuidora Digital, S. A.',
            'PRV_Contacto' => 'María Proveedora',
            'PRV_Telefono' => '55550202',
            'PRV_Correo' => 'ventas@distribuidora.test',
            'PRV_Estado' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categories = DB::table('TEC_CATEGORIA')->pluck('CAT_Categoria', 'CAT_Nombre');
        $brands = DB::table('TEC_MARCA')->pluck('MAR_Marca', 'MAR_Nombre');
        foreach ([
            [$categories['Periféricos'], $brands['Logitech'], 'TEC-TEC-001', 'Teclado inalámbrico', 20, 35, 5],
            [$categories['Periféricos'], $brands['Logitech'], 'TEC-MOU-001', 'Ratón óptico', 8, 15, 8],
            [$categories['Almacenamiento'], $brands['Kingston'], 'TEC-USB-001', 'Memoria USB 64 GB', 6, 12, 10],
            [$categories['Conectividad'], $brands['TechLine'], 'TEC-CAB-001', 'Cable USB-C', 3, 8, 12],
        ] as [$category, $brand, $sku, $name, $cost, $price, $minimum]) {
            $product = DB::table('TEC_PRODUCTO')->insertGetId([
                'CAT_Categoria' => $category,
                'MAR_Marca' => $brand,
                'PRO_SKU' => $sku,
                'PRO_Nombre' => $name,
                'PRO_Costo' => $cost,
                'PRO_Precio' => $price,
                'PRO_Existencia_Minima' => $minimum,
                'PRO_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'PRO_Producto');
            DB::table('TEC_INVENTARIO')->insert([
                'PRO_Producto' => $product,
                'INV_Existencia' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('TEC_ALERTA')->insert([
                'PRO_Producto' => $product,
                'ALE_Tipo' => 'INVENTARIO_MINIMO',
                'ALE_Estado' => 'ACTIVA',
                'ALE_Fecha_Generacion' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedCatalogs(): void
    {
        foreach ([
            ['PENDIENTE', 'Pendiente', 1],
            ['PARCIAL', 'Recepción parcial', 2],
            ['RECIBIDA', 'Recibida', 3],
            ['CANCELADA', 'Cancelada', 4],
        ] as [$codigo, $nombre, $orden]) {
            DB::table('TEC_ESTADO_COMPRA')->insert([
                'ESC_Codigo' => $codigo,
                'ESC_Nombre' => $nombre,
                'ESC_Orden' => $orden,
                'ESC_Estado' => true,
            ]);
        }

        foreach ([
            ['CREADO', 'Creado', 1],
            ['PENDIENTE_APROBACION', 'Pendiente de aprobación', 2],
            ['APROBADO', 'Aprobado', 3],
            ['EN_PROCESO', 'En proceso', 4],
            ['COMPLETADO', 'Completado', 5],
            ['CANCELADO', 'Cancelado', 6],
        ] as [$codigo, $nombre, $orden]) {
            DB::table('TEC_ESTADO_PEDIDO')->insert([
                'ESP_Codigo' => $codigo,
                'ESP_Nombre' => $nombre,
                'ESP_Orden' => $orden,
                'ESP_Estado' => true,
            ]);
        }

        foreach ([
            ['FINALIZADA', 'Finalizada'],
            ['ANULADA', 'Anulada'],
        ] as [$codigo, $nombre]) {
            DB::table('TEC_ESTADO_VENTA')->insert([
                'ESV_Codigo' => $codigo,
                'ESV_Nombre' => $nombre,
                'ESV_Estado' => true,
            ]);
        }

        foreach ([
            ['PENDIENTE', 'Pendiente'],
            ['PARCIAL', 'Parcial'],
            ['PAGADA', 'Pagada'],
        ] as [$codigo, $nombre]) {
            DB::table('TEC_ESTADO_PAGO')->insert([
                'EPA_Codigo' => $codigo,
                'EPA_Nombre' => $nombre,
                'EPA_Estado' => true,
            ]);
        }

        foreach ([
            ['EFECTIVO', 'Efectivo'],
            ['TARJETA', 'Tarjeta'],
            ['TRANSFERENCIA', 'Transferencia'],
        ] as [$codigo, $nombre]) {
            DB::table('TEC_METODO_PAGO')->insert([
                'MPA_Codigo' => $codigo,
                'MPA_Nombre' => $nombre,
                'MPA_Estado' => true,
            ]);
        }

        foreach ([
            ['ENTRADA', 'Entrada', 'Recepción de compra'],
            ['SALIDA', 'Salida', 'Venta finalizada'],
            ['AJUSTE', 'Ajuste', 'Corrección supervisada'],
            ['DEVOLUCION', 'Devolución', 'Reingreso de mercancía'],
        ] as [$codigo, $nombre, $descripcion]) {
            DB::table('TEC_TIPO_MOVIMIENTO')->insert([
                'TIM_Codigo' => $codigo,
                'TIM_Nombre' => $nombre,
                'TIM_Descripcion' => $descripcion,
                'TIM_Estado' => true,
            ]);
        }
    }

    private function seedSecurity(\DateTimeInterface|string $now): void
    {
        foreach (['ADMINISTRADOR', 'SUPERVISOR', 'OPERADOR'] as $role) {
            DB::table('TEC_ROL')->insert([
                'ROL_Nombre' => $role,
                'ROL_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissions = [
            ['USUARIOS.CONSULTAR', 'Consultar usuarios', 'USUARIOS', 'Ver usuarios y roles sin crear ni modificar'],
            ['USUARIOS.GESTIONAR', 'Gestionar usuarios', 'USUARIOS', 'Crear, modificar y activar usuarios y roles'],
            ['CLIENTES.CONSULTAR', 'Consultar clientes', 'CLIENTES', 'Ver el módulo de clientes sin crear ni modificar'],
            ['CLIENTES.GESTIONAR', 'Gestionar clientes', 'CLIENTES', 'Alta, edición e inactivación de clientes'],
            ['CATALOGOS.CONSULTAR', 'Consultar catálogos', 'CATALOGOS', 'Ver productos, categorías y marcas sin crear ni modificar'],
            ['CATALOGOS.GESTIONAR', 'Gestionar catálogos', 'CATALOGOS', 'Crear y editar proveedores, categorías, marcas y productos'],
            ['CATALOGOS.INACTIVAR', 'Inactivar catálogos', 'CATALOGOS', 'Baja lógica de maestros'],
            ['COMPRAS.REGISTRAR', 'Registrar compras', 'COMPRAS', 'Orden de compra y recepción'],
            ['INVENTARIO.AJUSTAR', 'Ajustar inventario', 'INVENTARIO', 'Ajustes y devoluciones supervisadas'],
            ['PEDIDOS.REGISTRAR', 'Registrar pedidos', 'PEDIDOS', 'Crear, avanzar y cancelar pedidos'],
            ['PEDIDOS.APROBAR', 'Aprobar descuentos', 'PEDIDOS', 'Autorizar o rechazar descuentos altos'],
            ['VENTAS.REGISTRAR', 'Registrar ventas', 'VENTAS', 'Facturar pedido y descontar stock'],
            ['PAGOS.REGISTRAR', 'Registrar pagos', 'PAGOS', 'Abonos sobre el saldo de la venta'],
            ['REPORTES.CONSULTAR', 'Consultar reportes', 'REPORTES', 'Dashboard, reportes y exportación'],
            ['AUDITORIA.CONSULTAR', 'Consultar auditoría', 'AUDITORIA', 'Bitácora de operaciones'],
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

        $roles = DB::table('TEC_ROL')->pluck('ROL_Rol', 'ROL_Nombre');
        $permissionIds = DB::table('TEC_PERMISO')->pluck('PER_Permiso', 'PER_Codigo');
        $matrix = [
            'ADMINISTRADOR' => array_column($permissions, 0),
            'SUPERVISOR' => ['PEDIDOS.APROBAR', 'INVENTARIO.AJUSTAR', 'REPORTES.CONSULTAR'],
            'OPERADOR' => [
                'CLIENTES.GESTIONAR',
                'CATALOGOS.GESTIONAR',
                'COMPRAS.REGISTRAR',
                'PEDIDOS.REGISTRAR',
                'VENTAS.REGISTRAR',
                'PAGOS.REGISTRAR',
            ],
        ];
        foreach ($matrix as $role => $codes) {
            foreach ($codes as $code) {
                DB::table('TEC_ROL_PERMISO')->insert([
                    'ROL_Rol' => $roles[$role],
                    'PER_Permiso' => $permissionIds[$code],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach ([
            ['ADMINISTRADOR', 'Ana', 'Admin', 'admin@techstore.test'],
            ['SUPERVISOR', 'Sofía', 'Supervisora', 'supervisor@techstore.test'],
            ['OPERADOR', 'Óscar', 'Operador', 'operador@techstore.test'],
        ] as [$role, $name, $lastName, $email]) {
            DB::table('TEC_USUARIO')->insert([
                'ROL_Rol' => $roles[$role],
                'USU_Primer_Nombre' => $name,
                'USU_Primer_Apellido' => $lastName,
                'USU_Correo' => $email,
                'USU_Contrasena' => Hash::make('TechStore2026!'),
                'USU_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
