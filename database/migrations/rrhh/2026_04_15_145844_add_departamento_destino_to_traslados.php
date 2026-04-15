<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('traslados', function (Blueprint $table) {
            $table->unsignedBigInteger('departamento_origen_id')->nullable()->after('cargo_origen_nombre');
            $table->string('departamento_origen_nombre', 120)->nullable()->after('departamento_origen_id');
            $table->unsignedBigInteger('departamento_destino_id')->nullable()->after('cargo_destino_nombre');
            $table->string('departamento_destino_nombre', 120)->nullable()->after('departamento_destino_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('traslados', function (Blueprint $table) {
            $table->dropColumn([
                'departamento_origen_id', 'departamento_origen_nombre',
                'departamento_destino_id', 'departamento_destino_nombre',
            ]);
        });
    }
};
