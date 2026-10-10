<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TEC_USUARIO', function (Blueprint $table) {
            $table->index(['USU_Estado', 'USU_Primer_Nombre'], 'TEC_USUARIO_estado_nombre_index');
        });

        Schema::table('TEC_CLIENTE', function (Blueprint $table) {
            $table->index(['CLI_Estado', 'CLI_Primer_Nombre'], 'TEC_CLIENTE_estado_nombre_index');
            $table->index('CLI_Primer_Apellido', 'TEC_CLIENTE_apellido_index');
        });

        Schema::table('TEC_PRODUCTO', function (Blueprint $table) {
            $table->index(['PRO_Estado', 'PRO_Nombre'], 'TEC_PRODUCTO_estado_nombre_index');
        });

        Schema::table('TEC_COMPRA', function (Blueprint $table) {
            $table->index('COM_Fecha', 'TEC_COMPRA_fecha_index');
        });

        Schema::table('TEC_PEDIDO', function (Blueprint $table) {
            $table->index(['ESP_Estado_Pedido', 'PED_Fecha'], 'TEC_PEDIDO_estado_fecha_index');
        });

        Schema::table('TEC_VENTA', function (Blueprint $table) {
            $table->index('VEN_Fecha', 'TEC_VENTA_fecha_index');
        });

        Schema::table('TEC_ALERTA', function (Blueprint $table) {
            $table->index('ALE_Estado', 'TEC_ALERTA_estado_index');
        });

        Schema::table('TEC_PERMISO', function (Blueprint $table) {
            $table->index('PER_Estado', 'TEC_PERMISO_estado_index');
        });
    }

    public function down(): void
    {
        Schema::table('TEC_USUARIO', function (Blueprint $table) {
            $table->dropIndex('TEC_USUARIO_estado_nombre_index');
        });
        Schema::table('TEC_CLIENTE', function (Blueprint $table) {
            $table->dropIndex('TEC_CLIENTE_estado_nombre_index');
            $table->dropIndex('TEC_CLIENTE_apellido_index');
        });
        Schema::table('TEC_PRODUCTO', function (Blueprint $table) {
            $table->dropIndex('TEC_PRODUCTO_estado_nombre_index');
        });
        Schema::table('TEC_COMPRA', function (Blueprint $table) {
            $table->dropIndex('TEC_COMPRA_fecha_index');
        });
        Schema::table('TEC_PEDIDO', function (Blueprint $table) {
            $table->dropIndex('TEC_PEDIDO_estado_fecha_index');
        });
        Schema::table('TEC_VENTA', function (Blueprint $table) {
            $table->dropIndex('TEC_VENTA_fecha_index');
        });
        Schema::table('TEC_ALERTA', function (Blueprint $table) {
            $table->dropIndex('TEC_ALERTA_estado_index');
        });
        Schema::table('TEC_PERMISO', function (Blueprint $table) {
            $table->dropIndex('TEC_PERMISO_estado_index');
        });
    }
};
