<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega FK constraints de empleado_id → rrhh_db.empleados.id
 * en todas las tablas de rrhh_db que referencian a un empleado.
 *
 * Precondición: que la migración create_empleados_mirror_in_rrhh haya corrido
 * (la tabla rrhh.empleados debe existir y estar poblada).
 *
 * Los datos existentes NO se modifican. Solo se añaden constraints.
 * Usa RESTRICT: no se puede eliminar un empleado del espejo si tiene registros.
 */
return new class extends Migration
{
    // Tablas en rrhh_db que tienen empleado_id → empleados.id
    private array $tablas = [
        // Expediente
        'expediente_datos_personales' => 'exp_dp_emp_fk',
        'expediente_contactos'        => 'exp_cont_emp_fk',
        'expediente_cuentas_banco'    => 'exp_banco_emp_fk',
        'expediente_documentos'       => 'exp_doc_emp_fk',
        'expediente_estudios'         => 'exp_est_emp_fk',
        'expediente_experiencia_laboral' => 'exp_exp_emp_fk',
        'expediente_idiomas'          => 'exp_idio_emp_fk',
        'expediente_archivos'         => 'exp_arch_emp_fk',
        // Movimientos
        'ingresos_personal'           => 'ing_per_emp_fk',
        'periodos_prueba'             => 'per_pru_emp_fk',
        'desvinculaciones'            => 'desv_emp_fk',
        'traslados'                   => 'tras_emp_fk',
        'contratos_empleado'          => 'cont_emp_fk',
        'cambios_salariales'          => 'cam_sal_emp_fk',
        // Asistencia y tiempo
        'permisos'                    => 'perm_emp_fk',
        'incapacidades'               => 'incap_emp_fk',
        'ausencias_injustificadas'    => 'aus_inj_emp_fk',
        'horas_extras_solicitudes'    => 'he_sol_emp_fk',
        'vacaciones'                  => 'vac_emp_fk',
        'saldos_vacaciones'           => 'sal_vac_emp_fk',
        'saldos_cadejo'               => 'sal_cad_emp_fk',
        // Disciplinario
        'amonestaciones'              => 'amon_emp_fk',
        // Planilla
        'planilla_lineas'             => 'pl_lin_emp_fk',
        'planilla_ordenes_descuento'  => 'pl_ord_emp_fk',
        // Propinas
        'propina_detalles'            => 'prop_det_emp_fk',
        'propina_puntos_empleado'     => 'prop_ptos_emp_fk',
    ];

    public function up(): void
    {
        foreach ($this->tablas as $tabla => $constraintName) {
            Schema::connection('rrhh')->table($tabla, function (Blueprint $table) use ($constraintName) {
                $table->foreign('empleado_id', $constraintName)
                      ->references('id')
                      ->on('empleados')
                      ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla => $constraintName) {
            Schema::connection('rrhh')->table($tabla, function (Blueprint $table) use ($constraintName) {
                $table->dropForeign($constraintName);
            });
        }
    }
};
