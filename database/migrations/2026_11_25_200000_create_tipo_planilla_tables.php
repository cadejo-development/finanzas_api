<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tablas en la conexión principal (pgsql), junto a empleados
    public function up(): void
    {
        Schema::create('tipo_planilla', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        DB::table('tipo_planilla')->insert([
            ['slug' => 'salarios',   'nombre' => 'Planilla de Salarios',   'descripcion' => 'Pago quincenal de salario',  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'honorarios', 'nombre' => 'Planilla de Honorarios', 'descripcion' => 'Pago por honorarios',        'activo' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('empleado_tipo_planilla', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedBigInteger('tipo_planilla_id');
            $table->timestamps();
            $table->unique(['empleado_id', 'tipo_planilla_id']);
            $table->foreign('tipo_planilla_id')->references('id')->on('tipo_planilla')->onDelete('cascade');
            $table->foreign('empleado_id')->references('id')->on('empleados')->onDelete('cascade');
        });

        // Todos los empleados actuales van a "salarios" por defecto
        $salId = DB::table('tipo_planilla')->where('slug', 'salarios')->value('id');
        DB::table('empleados')
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($salId) {
                DB::table('empleado_tipo_planilla')->insertOrIgnore(
                    $rows->map(fn($e) => [
                        'empleado_id'      => $e->id,
                        'tipo_planilla_id' => $salId,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleado_tipo_planilla');
        Schema::dropIfExists('tipo_planilla');
    }
};
