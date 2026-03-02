<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Ejecutar en la conexión pgsql (base central / core).
     */
    public function getConnection(): string
    {
        return 'pgsql';
    }

    public function up(): void
    {
        // 1) Añadir columna activo si no existe
        if (!Schema::connection('pgsql')->hasColumn('centros_costo', 'activo')) {
            Schema::connection('pgsql')->table('centros_costo', function (Blueprint $table) {
                $table->boolean('activo')->default(true)->after('codigo');
            });
        }

        // 2) Insertar centros de costo solo si la tabla está vacía
        if (DB::connection('pgsql')->table('centros_costo')->count() === 0) {
            $now = now();
            DB::connection('pgsql')->table('centros_costo')->insert([
                ['codigo' => 'CC-ADM',       'nombre' => 'Administración Central',    'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-OPE',       'nombre' => 'Operaciones',               'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-LOG',       'nombre' => 'Logística',                 'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-MKT',       'nombre' => 'Marketing y Ventas',        'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-PROD',      'nombre' => 'Producción / Planta',       'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-MAN',       'nombre' => 'Mantenimiento',             'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-FIN',       'nombre' => 'Finanzas y Contabilidad',   'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-RH',        'nombre' => 'Recursos Humanos',          'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-TI',        'nombre' => 'Tecnología e Informática',  'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
                ['codigo' => 'CC-CAL',       'nombre' => 'Control de Calidad',        'activo' => true, 'aud_usuario' => 'migration', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('pgsql')->table('centros_costo')
            ->whereIn('codigo', [
                'CC-ADM','CC-OPE','CC-LOG','CC-MKT','CC-PROD',
                'CC-MAN','CC-FIN','CC-RH','CC-TI','CC-CAL',
            ])->delete();
    }
};
