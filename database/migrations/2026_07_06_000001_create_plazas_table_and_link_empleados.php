<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        // 1. Crear tabla plazas
        Schema::connection('pgsql')->create('plazas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cargo_id');
            $table->unsignedBigInteger('departamento_id')->nullable();
            $table->string('codigo', 30)->nullable();
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->foreign('cargo_id', 'plazas_cargo_fk')
                  ->references('id')->on('cargos')->restrictOnDelete();
            $table->foreign('departamento_id', 'plazas_depto_fk')
                  ->references('id')->on('departamentos')->nullOnDelete();
        });

        // 2. Agregar plaza_id a empleados (nullable — no FK todavía para poder sembrar)
        Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
            $table->unsignedBigInteger('plaza_id')->nullable()->after('cargo_id');
        });

        // 3. Sembrar: una plaza por cada empleado activo que tenga cargo asignado
        $empleados = DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true)
            ->whereNotNull('cargo_id')
            ->get(['id', 'cargo_id', 'departamento_id']);

        foreach ($empleados as $emp) {
            $plazaId = DB::connection('pgsql')->table('plazas')->insertGetId([
                'cargo_id'        => $emp->cargo_id,
                'departamento_id' => $emp->departamento_id,
                'activo'          => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $emp->id)
                ->update(['plaza_id' => $plazaId]);
        }

        // 4. Agregar FK constraint ahora que los datos están enlazados
        Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
            $table->foreign('plaza_id', 'emp_plaza_fk')
                  ->references('id')->on('plazas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
            $table->dropForeign('emp_plaza_fk');
            $table->dropColumn('plaza_id');
        });
        Schema::connection('pgsql')->dropIfExists('plazas');
    }
};
