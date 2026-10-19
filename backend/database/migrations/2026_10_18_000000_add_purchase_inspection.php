<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TEC_RECEPCION', function (Blueprint $table) {
            $table->dropForeign(['COM_Compra']);
            $table->dropUnique(['COM_Compra']);
            $table->foreign('COM_Compra')->references('COM_Compra')->on('TEC_COMPRA');
        });

        Schema::create('TEC_DETALLE_RECEPCION', function (Blueprint $table) {
            $table->id('DRE_Detalle_Recepcion');
            $table->foreignId('REC_Recepcion')->constrained('TEC_RECEPCION', 'REC_Recepcion');
            $table->foreignId('PRO_Producto')->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->unsignedInteger('DRE_Aceptada')->default(0);
            $table->unsignedInteger('DRE_Danada')->default(0);
            $table->unsignedInteger('DRE_Faltante')->default(0);
            $table->text('DRE_Motivo')->nullable();
            $table->unique(['REC_Recepcion', 'PRO_Producto']);
        });

        Schema::create('TEC_INCIDENCIA', function (Blueprint $table) {
            $table->id('INC_Incidencia');
            $table->foreignId('COM_Compra')->constrained('TEC_COMPRA', 'COM_Compra');
            $table->foreignId('REC_Recepcion')->constrained('TEC_RECEPCION', 'REC_Recepcion');
            $table->foreignId('PRO_Producto')->constrained('TEC_PRODUCTO', 'PRO_Producto');
            $table->foreignId('USU_Usuario')->constrained('TEC_USUARIO', 'USU_Usuario');
            $table->unsignedInteger('INC_Cantidad');
            $table->string('INC_Estado', 20)->default('PENDIENTE');
            $table->text('INC_Motivo');
            $table->text('INC_Resolucion')->nullable();
            $table->decimal('INC_Monto_Credito', 12, 2)->nullable();
            $table->string('INC_Foto', 255)->nullable();
            $table->dateTime('INC_Fecha');
            $table->dateTime('INC_Fecha_Resolucion')->nullable();
            $table->timestamps();
            $table->index(['INC_Estado', 'INC_Fecha']);
        });

        $catalogAlreadyLoaded = DB::table('TEC_ESTADO_COMPRA')->exists();
        $partialExists = DB::table('TEC_ESTADO_COMPRA')->where('ESC_Codigo', 'PARCIAL')->exists();
        if ($catalogAlreadyLoaded && ! $partialExists) {
            DB::table('TEC_ESTADO_COMPRA')->insert([
                'ESC_Codigo' => 'PARCIAL',
                'ESC_Nombre' => 'Recepción parcial',
                'ESC_Orden' => 2,
                'ESC_Estado' => true,
            ]);
        }

        $receipts = DB::table('TEC_RECEPCION')->get(['REC_Recepcion', 'COM_Compra']);
        foreach ($receipts as $receipt) {
            $details = DB::table('TEC_DETALLE_COMPRA')
                ->where('COM_Compra', $receipt->COM_Compra)
                ->get(['PRO_Producto', 'DCO_Cantidad']);
            foreach ($details as $detail) {
                DB::table('TEC_DETALLE_RECEPCION')->insert([
                    'REC_Recepcion' => $receipt->REC_Recepcion,
                    'PRO_Producto' => $detail->PRO_Producto,
                    'DRE_Aceptada' => $detail->DCO_Cantidad,
                    'DRE_Danada' => 0,
                    'DRE_Faltante' => 0,
                    'DRE_Motivo' => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('TEC_INCIDENCIA');
        Schema::dropIfExists('TEC_DETALLE_RECEPCION');
        DB::table('TEC_ESTADO_COMPRA')->where('ESC_Codigo', 'PARCIAL')->delete();
        Schema::table('TEC_RECEPCION', function (Blueprint $table) {
            $table->unique('COM_Compra');
        });
    }
};
