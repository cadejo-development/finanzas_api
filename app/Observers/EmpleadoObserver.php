<?php

namespace App\Observers;

use App\Models\Empleado;
use Illuminate\Support\Facades\DB;

class EmpleadoObserver
{
    /**
     * Sincroniza el espejo de rrhh_db.empleados al guardar (create o update).
     * Usa upsert para ser idempotente ante re-runs o imports masivos.
     */
    public function saved(Empleado $empleado): void
    {
        DB::connection('rrhh')->table('empleados')->upsert(
            [[
                'id'              => $empleado->id,
                'codigo'          => $empleado->codigo,
                'nombres'         => $empleado->nombres,
                'apellidos'       => $empleado->apellidos,
                'email'           => $empleado->email,
                'cargo_id'        => $empleado->cargo_id,
                'sucursal_id'     => $empleado->sucursal_id,
                'activo'          => $empleado->activo,
                'aud_usuario'     => $empleado->aud_usuario,
                'created_at'      => $empleado->created_at,
                'updated_at'      => $empleado->updated_at,
                'user_id'         => $empleado->user_id,
                'fecha_ingreso'   => $empleado->fecha_ingreso,
                'departamento_id' => $empleado->departamento_id,
                'salario_base'    => $empleado->salario_base,
                'sync_excluido'   => $empleado->sync_excluido ?? false,
                'plaza_id'        => $empleado->plaza_id,
            ]],
            ['id'],
            ['codigo', 'nombres', 'apellidos', 'email', 'cargo_id', 'sucursal_id',
             'activo', 'aud_usuario', 'user_id', 'fecha_ingreso', 'departamento_id',
             'salario_base', 'sync_excluido', 'plaza_id', 'updated_at']
        );
    }
}
