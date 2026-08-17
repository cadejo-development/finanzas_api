<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Permisos directos por usuario (sin depender del rol)
        Schema::create('permission_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            $table->unique(['user_id', 'permission_id']);
            $table->string('aud_usuario')->nullable();
            $table->timestamps();
        });

        // Permiso: puede_hacer_conteo (sistema compras)
        $sistemaCodigo = 'compras';
        $sistemaId = DB::table('systems')->where('codigo', $sistemaCodigo)->value('id');
        if ($sistemaId) {
            DB::table('permissions')->insertOrIgnore([
                'nombre'      => 'Puede hacer conteo de inventario',
                'codigo'      => 'puede_hacer_conteo',
                'system_id'   => $sistemaId,
                'aud_usuario' => 'migration',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('codigo', 'puede_hacer_conteo')->delete();
        Schema::dropIfExists('permission_user');
    }
};
