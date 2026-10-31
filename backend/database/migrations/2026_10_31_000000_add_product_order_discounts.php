<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['TEC_DETALLE_PEDIDO' => 'DPE', 'TEC_DETALLE_VENTA' => 'DVE'] as $name => $prefix) {
            Schema::table($name, function (Blueprint $table) use ($prefix) {
                $table->decimal("{$prefix}_Descuento_Porcentaje", 5, 2)->default(0);
                $table->decimal("{$prefix}_Descuento_Monto", 12, 2)->default(0);
            });
        }

        Schema::create('TEC_APROBACION_PRODUCTO', function (Blueprint $table) {
            $table->id('APR_Aprobacion');
            $table->foreignId('PED_Pedido')->constrained('TEC_PEDIDO', 'PED_Pedido');
            $table->foreignId('DPE_Detalle_Pedido')->unique()->constrained('TEC_DETALLE_PEDIDO', 'DPE_Detalle_Pedido');
            $table->foreignId('USU_Usuario')->nullable()->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->string('APR_Estado', 20)->default('PENDIENTE');
            $table->text('APR_Motivo')->nullable();
            $table->dateTime('APR_Fecha')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TEC_APROBACION_PRODUCTO');
        foreach (['TEC_DETALLE_PEDIDO' => 'DPE', 'TEC_DETALLE_VENTA' => 'DVE'] as $name => $prefix) {
            Schema::table($name, function (Blueprint $table) use ($prefix) {
                $table->dropColumn(["{$prefix}_Descuento_Porcentaje", "{$prefix}_Descuento_Monto"]);
            });
        }
    }
};
