<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->table('ordenes_descuento', function (Blueprint $table) {
            $table->string('documento_path', 500)->nullable()->after('notas');
            $table->string('documento_nombre', 255)->nullable()->after('documento_path');
            $table->date('fecha_finiquito')->nullable()->after('documento_nombre');
            $table->string('documento_finiquito_path', 500)->nullable()->after('fecha_finiquito');
            $table->string('documento_finiquito_nombre', 255)->nullable()->after('documento_finiquito_path');
            $table->string('finiquito_usuario', 100)->nullable()->after('documento_finiquito_nombre');
        });

        // Agregar estado "Finiquitada" si no existe
        $existe = DB::connection('pgsql')
            ->table('estados_orden_descuento')
            ->where('nombre', 'Finiquitada')
            ->exists();

        if (!$existe) {
            DB::connection('pgsql')->table('estados_orden_descuento')->insert([
                'nombre'      => 'Finiquitada',
                'color'       => 'blue',
                'activo'      => true,
                'aud_usuario' => 'sistema',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('ordenes_descuento', function (Blueprint $table) {
            $table->dropColumn([
                'documento_path', 'documento_nombre',
                'fecha_finiquito', 'documento_finiquito_path',
                'documento_finiquito_nombre', 'finiquito_usuario',
            ]);
        });
    }
};
