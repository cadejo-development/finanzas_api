<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EstadoSolicitudPago;

class EstadosSolicitudPagoSeeder extends Seeder
{
    public function run(): void
    {
        // Truncar usando el modelo
        EstadoSolicitudPago::truncate();

        // Insertar estados
        EstadoSolicitudPago::insert([
            [
                'codigo' => 'BORRADOR',
                'nombre' => 'Borrador',
                'descripcion' => 'Solicitud en edición, aún no enviada.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'ENVIADO',
                'nombre' => 'Enviado',
                'descripcion' => 'Solicitud enviada para aprobación.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'APROBADO',
                'nombre' => 'Aprobado',
                'descripcion' => 'Solicitud aprobada por todos los responsables.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'RECHAZADO',
                'nombre' => 'Rechazado',
                'descripcion' => 'Solicitud rechazada en el proceso de aprobación.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}