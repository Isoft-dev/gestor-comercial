<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('TEC_ROL', function (Blueprint $table) {
            $table->id('ROL_Rol');
            $table->string('ROL_Nombre', 30)->unique();
            $table->boolean('ROL_Estado')->default(true);
            $table->timestamps();
        });

        Schema::create('TEC_PERMISO', function (Blueprint $table) {
            $table->id('PER_Permiso');
            $table->string('PER_Codigo', 50)->unique();
            $table->string('PER_Nombre', 80);
            $table->string('PER_Modulo', 40);
            $table->string('PER_Descripcion', 200)->nullable();
            $table->boolean('PER_Estado')->default(true);
            $table->timestamps();
        });

        Schema::create('TEC_ROL_PERMISO', function (Blueprint $table) {
            $table->id('RPE_Rol_Permiso');
            $table->foreignId('ROL_Rol')->constrained('TEC_ROL', 'ROL_Rol');
            $table->foreignId('PER_Permiso')->constrained('TEC_PERMISO', 'PER_Permiso');
            $table->timestamps();
            $table->unique(['ROL_Rol', 'PER_Permiso']);
        });

        Schema::create('TEC_USUARIO', function (Blueprint $table) {
            $table->id('USU_Usuario');
            $table->foreignId('ROL_Rol')->constrained('TEC_ROL', 'ROL_Rol');
            $table->string('USU_Primer_Nombre', 60);
            $table->string('USU_Segundo_Nombre', 60)->nullable();
            $table->string('USU_Primer_Apellido', 60);
            $table->string('USU_Segundo_Apellido', 60)->nullable();
            $table->string('USU_Correo', 120)->unique();
            $table->string('USU_Contrasena');
            $table->boolean('USU_Estado')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('TEC_CLIENTE', function (Blueprint $table) {
            $table->id('CLI_Cliente');
            $table->string('CLI_Identificacion', 30)->unique();
            $table->string('CLI_Primer_Nombre', 60);
            $table->string('CLI_Segundo_Nombre', 60)->nullable();
            $table->string('CLI_Primer_Apellido', 60);
            $table->string('CLI_Segundo_Apellido', 60)->nullable();
            $table->string('CLI_Correo', 120)->nullable();
            $table->string('CLI_Telefono', 30)->nullable();
            $table->boolean('CLI_Estado')->default(true);
            $table->timestamps();
        });

        Schema::create('TEC_PROVEEDOR', function (Blueprint $table) {
            $table->id('PRV_Proveedor');
            $table->string('PRV_Identificacion_Fiscal', 40)->unique();
            $table->string('PRV_Razon_Social', 120);
            $table->string('PRV_Contacto', 120)->nullable();
            $table->string('PRV_Telefono', 30)->nullable();
            $table->string('PRV_Correo', 120)->nullable();
            $table->boolean('PRV_Estado')->default(true);
            $table->timestamps();
        });

        Schema::create('TEC_CATEGORIA', function (Blueprint $table) {
            $table->id('CAT_Categoria');
            $table->string('CAT_Nombre', 80)->unique();
            $table->boolean('CAT_Estado')->default(true);
            $table->timestamps();
        });

        Schema::create('TEC_MARCA', function (Blueprint $table) {
            $table->id('MAR_Marca');
            $table->string('MAR_Nombre', 80)->unique();
            $table->boolean('MAR_Estado')->default(true);
            $table->timestamps();
        });

        Schema::create('TEC_PRODUCTO', function (Blueprint $table) {
            $table->id('PRO_Producto');
            $table->foreignId('CAT_Categoria')->constrained('TEC_CATEGORIA', 'CAT_Categoria');
            $table->foreignId('MAR_Marca')->constrained('TEC_MARCA', 'MAR_Marca');
            $table->string('PRO_SKU', 40)->unique();
            $table->string('PRO_Nombre', 120);
            $table->text('PRO_Descripcion')->nullable();
            $table->decimal('PRO_Costo', 12, 2)->default(0);
            $table->decimal('PRO_Precio', 12, 2);
            $table->unsignedInteger('PRO_Existencia_Minima')->default(0);
            $table->boolean('PRO_Estado')->default(true);
            $table->timestamps();
        });

        Schema::create('TEC_INVENTARIO', function (Blueprint $table) {
            $table->id('INV_Inventario');
            $table->foreignId('PRO_Producto')->unique()->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->unsignedInteger('INV_Existencia')->default(0);
            $table->timestamps();
        });

        Schema::create('TEC_ESTADO_COMPRA', function (Blueprint $table) {
            $table->id('ESC_Estado_Compra');
            $table->string('ESC_Codigo', 30)->unique();
            $table->string('ESC_Nombre', 60);
            $table->unsignedTinyInteger('ESC_Orden')->default(1);
            $table->boolean('ESC_Estado')->default(true);
        });

        Schema::create('TEC_COMPRA', function (Blueprint $table) {
            $table->id('COM_Compra');
            $table->foreignId('PRV_Proveedor')->constrained('TEC_PROVEEDOR', 'PRV_Proveedor');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->foreignId('ESC_Estado_Compra')->constrained('TEC_ESTADO_COMPRA', 'ESC_Estado_Compra');
            $table->dateTime('COM_Fecha');
            $table->decimal('COM_Total', 12, 2);
            $table->text('COM_Observacion')->nullable();
            $table->timestamps();
        });

        Schema::create('TEC_DETALLE_COMPRA', function (Blueprint $table) {
            $table->id('DCO_Detalle_Compra');
            $table->foreignId('COM_Compra')->constrained('TEC_COMPRA', 'COM_Compra');
            $table->foreignId('PRO_Producto')->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->unsignedInteger('DCO_Cantidad');
            $table->decimal('DCO_Costo_Unitario', 12, 2);
            $table->decimal('DCO_Subtotal', 12, 2);
            $table->unique(['COM_Compra', 'PRO_Producto']);
        });

        Schema::create('TEC_RECEPCION', function (Blueprint $table) {
            $table->id('REC_Recepcion');
            $table->foreignId('COM_Compra')->unique()->constrained('TEC_COMPRA', 'COM_Compra');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->dateTime('REC_Fecha');
            $table->text('REC_Observacion')->nullable();
            $table->timestamps();
        });

        Schema::create('TEC_TIPO_MOVIMIENTO', function (Blueprint $table) {
            $table->id('TIM_Tipo_Movimiento');
            $table->string('TIM_Codigo', 20)->unique();
            $table->string('TIM_Nombre', 60);
            $table->string('TIM_Descripcion', 120)->nullable();
            $table->boolean('TIM_Estado')->default(true);
        });

        Schema::create('TEC_MOVIMIENTO_INVENTARIO', function (Blueprint $table) {
            $table->id('MOV_Movimiento_Inventario');
            $table->foreignId('PRO_Producto')->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->foreignId('TIM_Tipo_Movimiento')->constrained('TEC_TIPO_MOVIMIENTO', 'TIM_Tipo_Movimiento');
            $table->integer('MOV_Cantidad');
            $table->unsignedInteger('MOV_Existencia_Anterior');
            $table->unsignedInteger('MOV_Existencia_Nueva');
            $table->string('MOV_Documento_Tipo', 30);
            $table->unsignedBigInteger('MOV_Documento');
            $table->text('MOV_Motivo');
            $table->dateTime('MOV_Fecha');
            $table->timestamps();
            $table->index(['PRO_Producto', 'MOV_Fecha']);
        });

        Schema::create('TEC_ALERTA', function (Blueprint $table) {
            $table->id('ALE_Alerta');
            $table->foreignId('PRO_Producto')->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->string('ALE_Tipo', 30)->default('INVENTARIO_MINIMO');
            $table->string('ALE_Estado', 20)->default('ACTIVA');
            $table->dateTime('ALE_Fecha_Generacion');
            $table->dateTime('ALE_Fecha_Resolucion')->nullable();
            $table->timestamps();
            $table->index(['PRO_Producto', 'ALE_Estado']);
        });

        Schema::create('TEC_ESTADO_PEDIDO', function (Blueprint $table) {
            $table->id('ESP_Estado_Pedido');
            $table->string('ESP_Codigo', 30)->unique();
            $table->string('ESP_Nombre', 60);
            $table->unsignedTinyInteger('ESP_Orden')->default(1);
            $table->boolean('ESP_Estado')->default(true);
        });

        Schema::create('TEC_PEDIDO', function (Blueprint $table) {
            $table->id('PED_Pedido');
            $table->foreignId('CLI_Cliente')->constrained('TEC_CLIENTE', 'CLI_Cliente');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->foreignId('ESP_Estado_Pedido')->constrained('TEC_ESTADO_PEDIDO', 'ESP_Estado_Pedido');
            $table->dateTime('PED_Fecha');
            $table->decimal('PED_Subtotal', 12, 2);
            $table->decimal('PED_Descuento_Porcentaje', 5, 2)->default(0);
            $table->decimal('PED_Descuento_Monto', 12, 2)->default(0);
            $table->decimal('PED_Total', 12, 2);
            $table->text('PED_Motivo_Cancelacion')->nullable();
            $table->timestamps();
        });

        Schema::create('TEC_DETALLE_PEDIDO', function (Blueprint $table) {
            $table->id('DPE_Detalle_Pedido');
            $table->foreignId('PED_Pedido')->constrained('TEC_PEDIDO', 'PED_Pedido');
            $table->foreignId('PRO_Producto')->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->unsignedInteger('DPE_Cantidad');
            $table->decimal('DPE_Precio_Unitario', 12, 2);
            $table->decimal('DPE_Subtotal', 12, 2);
            $table->unique(['PED_Pedido', 'PRO_Producto']);
        });

        Schema::create('TEC_HISTORIAL_ESTADO', function (Blueprint $table) {
            $table->id('HES_Historial_Estado');
            $table->foreignId('PED_Pedido')->constrained('TEC_PEDIDO', 'PED_Pedido');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->string('HES_Estado_Anterior', 30)->nullable();
            $table->string('HES_Estado_Nuevo', 30);
            $table->text('HES_Motivo')->nullable();
            $table->dateTime('HES_Fecha');
        });

        Schema::create('TEC_APROBACION', function (Blueprint $table) {
            $table->id('APR_Aprobacion');
            $table->foreignId('PED_Pedido')->unique()->constrained('TEC_PEDIDO', 'PED_Pedido');
            $table->foreignId('USU_Usuario')->nullable()->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->string('APR_Estado', 20)->default('PENDIENTE');
            $table->text('APR_Motivo')->nullable();
            $table->dateTime('APR_Fecha')->nullable();
            $table->timestamps();
        });

        Schema::create('TEC_ESTADO_VENTA', function (Blueprint $table) {
            $table->id('ESV_Estado_Venta');
            $table->string('ESV_Codigo', 30)->unique();
            $table->string('ESV_Nombre', 60);
            $table->boolean('ESV_Estado')->default(true);
        });

        Schema::create('TEC_ESTADO_PAGO', function (Blueprint $table) {
            $table->id('EPA_Estado_Pago');
            $table->string('EPA_Codigo', 30)->unique();
            $table->string('EPA_Nombre', 60);
            $table->boolean('EPA_Estado')->default(true);
        });

        Schema::create('TEC_METODO_PAGO', function (Blueprint $table) {
            $table->id('MPA_Metodo_Pago');
            $table->string('MPA_Codigo', 30)->unique();
            $table->string('MPA_Nombre', 60);
            $table->boolean('MPA_Estado')->default(true);
        });

        Schema::create('TEC_VENTA', function (Blueprint $table) {
            $table->id('VEN_Venta');
            $table->foreignId('PED_Pedido')->unique()->constrained('TEC_PEDIDO', 'PED_Pedido');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->foreignId('ESV_Estado_Venta')->constrained('TEC_ESTADO_VENTA', 'ESV_Estado_Venta');
            $table->foreignId('EPA_Estado_Pago')->constrained('TEC_ESTADO_PAGO', 'EPA_Estado_Pago');
            $table->dateTime('VEN_Fecha');
            $table->decimal('VEN_Total', 12, 2);
            $table->decimal('VEN_Pagado', 12, 2)->default(0);
            $table->decimal('VEN_Saldo', 12, 2);
            $table->timestamps();
        });

        Schema::create('TEC_DETALLE_VENTA', function (Blueprint $table) {
            $table->id('DVE_Detalle_Venta');
            $table->foreignId('VEN_Venta')->constrained('TEC_VENTA', 'VEN_Venta');
            $table->foreignId('PRO_Producto')->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->unsignedInteger('DVE_Cantidad');
            $table->decimal('DVE_Precio_Unitario', 12, 2);
            $table->decimal('DVE_Subtotal', 12, 2);
        });

        Schema::create('TEC_PAGO', function (Blueprint $table) {
            $table->id('PAG_Pago');
            $table->foreignId('VEN_Venta')->constrained('TEC_VENTA', 'VEN_Venta');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->foreignId('MPA_Metodo_Pago')->constrained('TEC_METODO_PAGO', 'MPA_Metodo_Pago');
            $table->decimal('PAG_Monto', 12, 2);
            $table->dateTime('PAG_Fecha');
            $table->string('PAG_Referencia', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('TEC_AUDITORIA', function (Blueprint $table) {
            $table->id('AUD_Auditoria');
            $table->foreignId('USU_Usuario')->nullable()->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->string('AUD_Modulo', 50);
            $table->string('AUD_Accion', 50);
            $table->string('AUD_Registro', 80);
            $table->json('AUD_Datos')->nullable();
            $table->string('AUD_IP', 45)->nullable();
            $table->dateTime('AUD_Fecha');
            $table->index(['AUD_Modulo', 'AUD_Fecha']);
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'TEC_AUDITORIA', 'TEC_PAGO', 'TEC_DETALLE_VENTA', 'TEC_VENTA',
            'TEC_METODO_PAGO', 'TEC_ESTADO_PAGO', 'TEC_ESTADO_VENTA',
            'TEC_APROBACION', 'TEC_HISTORIAL_ESTADO', 'TEC_DETALLE_PEDIDO',
            'TEC_PEDIDO', 'TEC_ESTADO_PEDIDO', 'TEC_ALERTA', 'TEC_MOVIMIENTO_INVENTARIO',
            'TEC_TIPO_MOVIMIENTO', 'TEC_RECEPCION', 'TEC_DETALLE_COMPRA', 'TEC_COMPRA',
            'TEC_ESTADO_COMPRA', 'TEC_INVENTARIO', 'TEC_PRODUCTO', 'TEC_MARCA', 'TEC_CATEGORIA',
            'TEC_PROVEEDOR', 'TEC_CLIENTE', 'personal_access_tokens',
            'TEC_USUARIO', 'TEC_ROL_PERMISO', 'TEC_PERMISO', 'TEC_ROL',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
