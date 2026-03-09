<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Actualiza sucursal_id en users usando los datos de user_centros_costo existentes.
 *    (El único user con asignación es el gerente de Guirola → SUC-GU)
 * 2) Elimina la tabla user_centros_costo (reemplazada por users.sucursal_id → centros_costo.sucursal_id).
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        // Migrar datos de user_centros_costo a users.sucursal_id
        // Derivar la sucursal de los CECOs asignados al usuario a través de centros_costo.sucursal_id
        if (Schema::connection('pgsql')->hasTable('user_centros_costo')) {
            $asignaciones = DB::connection('pgsql')
                ->table('user_centros_costo as ucc')
                ->join('centros_costo as cc', 'cc.codigo', '=', 'ucc.centro_costo_codigo')
                ->whereNotNull('cc.sucursal_id')
                ->select('ucc.user_id', 'cc.sucursal_id')
                ->groupBy('ucc.user_id', 'cc.sucursal_id')
                ->get();

            foreach ($asignaciones as $a) {
                DB::connection('pgsql')->table('users')
                    ->where('id', $a->user_id)
                    ->whereNull('sucursal_id')
                    ->update(['sucursal_id' => $a->sucursal_id]);
            }

            // Eliminar tabla obsoleta
            Schema::connection('pgsql')->dropIfExists('user_centros_costo');
        }
    }

    public function down(): void
    {
        // Recrear user_centros_costo vacía (datos originales no recuperables)
        Schema::connection('pgsql')->create('user_centros_costo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('centro_costo_codigo', 20);
            $table->string('aud_usuario')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'centro_costo_codigo']);
        });
    }
};
