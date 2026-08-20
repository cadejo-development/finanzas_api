<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla de asignaciones de sucursal por rol de inventario
        Schema::create('inventario_sucursal_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('sucursal_id');
            $table->string('rol', 20); // 'contador' | 'auditor'
            $table->boolean('activo')->default(true);
            $table->string('asignado_por', 150)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sucursal_id', 'rol']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('sucursal_id')->references('id')->on('sucursales')->onDelete('cascade');
        });

        // Buscar el sistema "Gestión de Operaciones" (compras)
        $sistema = DB::table('systems')->where('codigo', 'compras')->first();
        if (!$sistema) {
            return; // no insertar si no existe el sistema
        }

        $now = now();

        // Insertar los dos nuevos roles solo si no existen
        foreach ([
            ['contador_inv', 'Contador de Inventario Mensual'],
            ['auditor_inv',  'Auditor de Inventario Mensual'],
        ] as [$codigo, $nombre]) {
            $exists = DB::table('roles')->where('codigo', $codigo)->exists();
            if (!$exists) {
                DB::table('roles')->insert([
                    'nombre'     => $nombre,
                    'codigo'     => $codigo,
                    'system_id'  => $sistema->id,
                    'is_active'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_sucursal_roles');

        DB::table('roles')->whereIn('codigo', ['contador_inv', 'auditor_inv'])->delete();
    }
};
