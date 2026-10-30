<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TEC_CLIENTE', function (Blueprint $table) {
            $table->string('CLI_NIT', 20)->nullable();
            $table->string('CLI_Razon_Social', 160)->nullable();
            $table->string('CLI_Direccion_Fiscal', 255)->nullable();
        });

        Schema::create('TEC_FACTURA', function (Blueprint $table) {
            $table->id('FAC_Factura');
            $table->foreignId('VEN_Venta')->unique()->constrained('TEC_VENTA', 'VEN_Venta');
            $table->json('FAC_Datos');
            $table->timestamp('FAC_Fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TEC_FACTURA');
        Schema::table('TEC_CLIENTE', function (Blueprint $table) {
            $table->dropColumn(['CLI_NIT', 'CLI_Razon_Social', 'CLI_Direccion_Fiscal']);
        });
    }
};
