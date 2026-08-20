<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Config en pgsql (org chart); solicitudes en rrhh (payroll)
    public function up(): void
    {
        // ── 1. Config por departamento (pgsql) ────────────────────────────────
        Schema::connection('pgsql')->create('horas_extras_config', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('departamento_id')->unique();
            $table->boolean('requiere_n2')->default(false);
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 150)->nullable();
            $table->timestamps();

            $table->foreign('departamento_id')->references('id')->on('departamentos')->onDelete('cascade');
        });

        // Seed: todos los depts hijos de OPS requieren N2 (Rosa aprueba)
        $opsDeptId = DB::connection('pgsql')
            ->table('departamentos')
            ->where('codigo', 'OPS')
            ->value('id');

        if ($opsDeptId) {
            $childDepts = DB::connection('pgsql')
                ->table('departamentos')
                ->where('parent_id', $opsDeptId)
                ->where('activo', true)
                ->pluck('id');

            // OPERACIONES misma también requiere N2 (Rosa → su jefe padre)
            $childDepts->push($opsDeptId);

            foreach ($childDepts as $deptId) {
                DB::connection('pgsql')->table('horas_extras_config')->insertOrIgnore([
                    'departamento_id' => $deptId,
                    'requiere_n2'     => true,
                    'activo'          => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        // ── 2. Solicitudes (rrhh) ─────────────────────────────────────────────
        Schema::connection('rrhh')->create('horas_extras_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedBigInteger('solicitado_por_empleado_id');

            // Snapshot del departamento al momento de la solicitud
            $table->unsignedBigInteger('departamento_id')->nullable();

            // Período trabajado
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('horas', 5, 2);
            $table->text('descripcion')->nullable();

            // Estado: pendiente_n1 | pendiente_n2 | aprobada | rechazada
            $table->string('estado', 20)->default('pendiente_n1');

            // N1
            $table->unsignedBigInteger('n1_empleado_id')->nullable();
            $table->unsignedBigInteger('n1_aprobado_por_id')->nullable();
            $table->timestamp('n1_fecha')->nullable();
            $table->text('n1_observaciones')->nullable();

            // N2
            $table->boolean('n2_requerido')->default(false);
            $table->unsignedBigInteger('n2_empleado_id')->nullable();
            $table->unsignedBigInteger('n2_aprobado_por_id')->nullable();
            $table->timestamp('n2_fecha')->nullable();
            $table->text('n2_observaciones')->nullable();

            // Quincena trabajada
            $table->smallInteger('quincena_trabajo_anio')->nullable();
            $table->tinyInteger('quincena_trabajo_mes')->nullable();
            $table->tinyInteger('quincena_trabajo_num')->nullable(); // 1 o 2

            // Quincena de pago (calculada al aprobar)
            $table->smallInteger('quincena_pago_anio')->nullable();
            $table->tinyInteger('quincena_pago_mes')->nullable();
            $table->tinyInteger('quincena_pago_num')->nullable();

            // Rechazo
            $table->text('rechazo_observaciones')->nullable();

            $table->string('aud_usuario', 150)->nullable();
            $table->timestamps();

            $table->index(['empleado_id', 'estado']);
            $table->index(['n1_empleado_id', 'estado']);
            $table->index(['n2_empleado_id', 'estado']);
            $table->index(['quincena_pago_anio', 'quincena_pago_mes', 'quincena_pago_num']);
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('horas_extras_solicitudes');
        Schema::connection('pgsql')->dropIfExists('horas_extras_config');
    }
};
