<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TEC_PRODUCTO', function (Blueprint $table) {
            $table->string('PRO_Foto', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('TEC_PRODUCTO', function (Blueprint $table) {
            $table->dropColumn('PRO_Foto');
        });
    }
};
