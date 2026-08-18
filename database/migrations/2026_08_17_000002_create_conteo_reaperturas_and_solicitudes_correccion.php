<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Registro de reaperturas de conteo (auditor abre un conteo ya cerrado)
        Schema::connection('compras')->create('conteo_reaperturas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sucursal_id');
            $table->date('fecha_conteo');
            $table->text('motivo');
            $table->string('aud_usuario')->nullable();
            $table->timestamps();
        });

        // Solicitudes de corrección de conteo (gerente pide corrección; auditor aprueba)
        Schema::connection('compras')->create('solicitudes_correccion_conteo', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sucursal_id');
            $table->date('fecha_conteo');
            $table->unsignedInteger('producto_id');
            $table->decimal('cantidad_nueva', 14, 4);
            $table->string('unidad', 30);
            $table->text('motivo');
            $table->string('estado', 20)->default('pendiente'); // pendiente, aprobado, rechazado
            $table->text('motivo_rechazo')->nullable();
            $table->string('solicitado_por')->nullable();
            $table->string('revisado_por')->nullable();
            $table->timestamps();
        });

        // Permiso para auditar conteo
        $sistema = DB::connection('pgsql')->table('systems')->where('codigo', 'compras')->first();
        if ($sistema) {
            $exists = DB::connection('pgsql')
                ->table('permissions')
                ->where('codigo', 'puede_auditar_conteo')
                ->where('system_id', $sistema->id)
                ->exists();

            if (!$exists) {
                DB::connection('pgsql')->table('permissions')->insert([
                    'system_id'  => $sistema->id,
                    'nombre'     => 'Auditar conteo físico',
                    'codigo'     => 'puede_auditar_conteo',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('solicitudes_correccion_conteo');
        Schema::connection('compras')->dropIfExists('conteo_reaperturas');
        DB::connection('pgsql')->table('permissions')->where('codigo', 'puede_auditar_conteo')->delete();
    }
};
