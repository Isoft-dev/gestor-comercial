<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $categoryIds = $this->ensureCatalog('TEC_CATEGORIA', 'CAT_Nombre', 'CAT_Categoria', [
                'Audio y video',
                'Componentes',
                'Cables y adaptadores',
            ], 'CAT_Estado', $now);
            $brandIds = $this->ensureCatalog('TEC_MARCA', 'MAR_Nombre', 'MAR_Marca', [
                'Dell',
                'HP',
                'Ugreen',
                'Samsung',
                'Anker',
            ], 'MAR_Estado', $now);

            $this->seedClients($now);
            $this->seedProducts($categoryIds, $brandIds, $now);
            $this->seedUsers($now);
        });
    }

    private function ensureCatalog(
        string $table,
        string $nameColumn,
        string $idColumn,
        array $names,
        string $statusColumn,
        \DateTimeInterface|string $now,
    ): array {
        $ids = [];

        foreach ($names as $name) {
            $id = DB::table($table)->where($nameColumn, $name)->value($idColumn);
            if ($id === null) {
                $id = DB::table($table)->insertGetId([
                    $nameColumn => $name,
                    $statusColumn => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $idColumn);
            }
            $ids[$name] = (int) $id;
        }

        return $ids;
    }

    private function seedClients(\DateTimeInterface|string $now): void
    {
        $clients = [
            ['DEMO-CLI-001', 'Mariana', null, 'Gómez', 'López', 'mariana.gomez@clientes.test', '55551001'],
            ['DEMO-CLI-002', 'Carlos', 'Andrés', 'Méndez', 'Ruiz', 'carlos.mendez@clientes.test', '55551002'],
            ['DEMO-CLI-003', 'Daniela', null, 'Hernández', 'Paz', 'daniela.hernandez@clientes.test', '55551003'],
            ['DEMO-CLI-004', 'José', 'Manuel', 'Castillo', 'Reyes', 'jose.castillo@clientes.test', '55551004'],
            ['DEMO-CLI-005', 'Andrea', null, 'Morales', 'Díaz', 'andrea.morales@clientes.test', '55551005'],
            ['DEMO-CLI-006', 'Luis', 'Fernando', 'Ramírez', 'Cruz', 'luis.ramirez@clientes.test', '55551006'],
            ['DEMO-CLI-007', 'Paola', null, 'Vásquez', 'León', 'paola.vasquez@clientes.test', '55551007'],
            ['DEMO-CLI-008', 'Miguel', 'Ángel', 'Rojas', 'Pineda', 'miguel.rojas@clientes.test', '55551008'],
            ['DEMO-CLI-009', 'Valeria', null, 'Fuentes', 'Navas', 'valeria.fuentes@clientes.test', '55551009'],
            ['DEMO-CLI-010', 'Pedro', 'Pablo', 'Aguilar', 'Soto', 'pedro.aguilar@clientes.test', '55551010'],
            ['DEMO-CLI-011', 'Fernanda', null, 'Ochoa', 'Lara', 'fernanda.ochoa@clientes.test', '55551011'],
            ['DEMO-CLI-012', 'Ricardo', null, 'Delgado', 'Mora', 'ricardo.delgado@clientes.test', '55551012'],
        ];

        foreach ($clients as [$identification, $firstName, $secondName, $firstSurname, $secondSurname, $email, $phone]) {
            if (DB::table('TEC_CLIENTE')->where('CLI_Identificacion', $identification)->exists()) {
                continue;
            }

            DB::table('TEC_CLIENTE')->insert([
                'CLI_Identificacion' => $identification,
                'CLI_Primer_Nombre' => $firstName,
                'CLI_Segundo_Nombre' => $secondName,
                'CLI_Primer_Apellido' => $firstSurname,
                'CLI_Segundo_Apellido' => $secondSurname,
                'CLI_Correo' => $email,
                'CLI_Telefono' => $phone,
                'CLI_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedProducts(array $categoryIds, array $brandIds, \DateTimeInterface|string $now): void
    {
        $products = [
            ['Audio y video', 'Anker', 'DEMO-AUD-001', 'Audífonos Bluetooth', 'Audífonos inalámbricos con estuche de carga.', 180, 275, 5],
            ['Audio y video', 'Samsung', 'DEMO-AUD-002', 'Audífonos USB-C', 'Audífonos con conexión USB-C.', 95, 155, 6],
            ['Audio y video', 'HP', 'DEMO-AUD-003', 'Bocina compacta USB', 'Bocina compacta para escritorio.', 120, 195, 4],
            ['Componentes', 'Samsung', 'DEMO-COM-001', 'SSD SATA 480 GB', 'Unidad de estado sólido para computadora.', 310, 445, 4],
            ['Componentes', 'Dell', 'DEMO-COM-002', 'Memoria RAM DDR4 8 GB', 'Módulo de memoria DDR4 de 8 GB.', 185, 265, 6],
            ['Componentes', 'HP', 'DEMO-COM-003', 'Mouse inalámbrico', 'Mouse óptico inalámbrico con receptor USB.', 75, 125, 8],
            ['Componentes', 'Dell', 'DEMO-COM-004', 'Teclado USB español', 'Teclado con distribución en español.', 105, 165, 5],
            ['Cables y adaptadores', 'Ugreen', 'DEMO-CAB-001', 'Adaptador USB-C a HDMI', 'Adaptador de video USB-C a HDMI.', 145, 225, 4],
            ['Cables y adaptadores', 'Ugreen', 'DEMO-CAB-002', 'Cable HDMI 2 m', 'Cable HDMI de dos metros.', 48, 85, 10],
            ['Cables y adaptadores', 'Anker', 'DEMO-CAB-003', 'Cable USB-C 1 m', 'Cable USB-C para carga y transferencia.', 35, 65, 12],
            ['Cables y adaptadores', 'Anker', 'DEMO-CAB-004', 'Cargador USB-C 30 W', 'Cargador compacto USB-C de 30 W.', 160, 245, 5],
            ['Cables y adaptadores', 'Ugreen', 'DEMO-CAB-005', 'Hub USB 4 puertos', 'Concentrador USB de cuatro puertos.', 110, 175, 5],
            ['Componentes', 'Samsung', 'DEMO-COM-005', 'Memoria USB 128 GB', 'Unidad USB de 128 GB.', 70, 115, 8],
            ['Audio y video', 'HP', 'DEMO-AUD-004', 'Webcam HD', 'Cámara web HD para videollamadas.', 210, 315, 3],
            ['Componentes', 'Dell', 'DEMO-COM-006', 'Base para laptop', 'Base ajustable para computadora portátil.', 130, 205, 4],
        ];

        foreach ($products as [$category, $brand, $sku, $name, $description, $cost, $price, $minimum]) {
            if (DB::table('TEC_PRODUCTO')->where('PRO_SKU', $sku)->exists()) {
                continue;
            }

            $productId = DB::table('TEC_PRODUCTO')->insertGetId([
                'CAT_Categoria' => $categoryIds[$category],
                'MAR_Marca' => $brandIds[$brand],
                'PRO_SKU' => $sku,
                'PRO_Nombre' => $name,
                'PRO_Descripcion' => $description,
                'PRO_Costo' => $cost,
                'PRO_Precio' => $price,
                'PRO_Existencia_Minima' => $minimum,
                'PRO_Estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'PRO_Producto');

            DB::table('TEC_INVENTARIO')->insert([
                'PRO_Producto' => $productId,
                'INV_Existencia' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($minimum > 0) {
                DB::table('TEC_ALERTA')->insert([
                    'PRO_Producto' => $productId,
                    'ALE_Tipo' => 'INVENTARIO_MINIMO',
                    'ALE_Estado' => 'ACTIVA',
                    'ALE_Fecha_Generacion' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedUsers(\DateTimeInterface|string $now): void
    {
        $roleIds = DB::table('TEC_ROL')->pluck('ROL_Rol', 'ROL_Nombre');
        $users = [
            ['ADMINISTRADOR', 'Camila', 'Elena', 'Navarro', 'López', 'camila.navarro@techstore.test'],
            ['ADMINISTRADOR', 'Tomás', null, 'Ibarra', 'Ruiz', 'tomas.ibarra@techstore.test'],
            ['SUPERVISOR', 'Gabriela', null, 'Salazar', 'Méndez', 'gabriela.salazar@techstore.test'],
            ['SUPERVISOR', 'Esteban', 'David', 'Cifuentes', 'Reyes', 'esteban.cifuentes@techstore.test'],
            ['OPERADOR', 'Lucía', null, 'Paredes', 'Gómez', 'lucia.paredes@techstore.test'],
            ['OPERADOR', 'Mateo', 'Andrés', 'Calderón', 'Vásquez', 'mateo.calderon@techstore.test'],
        ];

        foreach ($users as [$role, $firstName, $secondName, $firstSurname, $secondSurname, $email]) {
            if (! isset($roleIds[$role])) {
                throw new RuntimeException("No existe el rol requerido '{$role}'. No se crearán roles automáticamente.");
            }
            if (DB::table('TEC_USUARIO')->where('USU_Correo', $email)->exists()) {
                continue;
            }

            DB::table('TEC_USUARIO')->insert([
                'ROL_Rol' => $roleIds[$role],
                'USU_Primer_Nombre' => $firstName,
                'USU_Segundo_Nombre' => $secondName,
                'USU_Primer_Apellido' => $firstSurname,
                'USU_Segundo_Apellido' => $secondSurname,
                'USU_Correo' => $email,
                'USU_Contrasena' => Hash::make('TechStore2026!'),
                'USU_Estado' => true,
                'USU_Intentos_Fallidos' => 0,
                'USU_Bloqueado_Hasta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
