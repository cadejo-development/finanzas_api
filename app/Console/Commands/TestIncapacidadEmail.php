<?php

namespace App\Console\Commands;

use App\Mail\RRHH\AccionPersonalNotificacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestIncapacidadEmail extends Command
{
    protected $signature = 'rrhh:test-incapacidad-email {email? : Destinatario (default: javiermejia@cervezacadejo.com)}';
    protected $description = 'Envía un correo de prueba de incapacidad';

    public function handle(): int
    {
        $to = $this->argument('email') ?? 'javiermejia@cervezacadejo.com';

        $mail = new AccionPersonalNotificacion(
            tipo:             'Incapacidad',
            empleadoNombre:   'Juan Empleado (correo de prueba)',
            supervisorNombre: 'Javier Mejia',
            detalles: [
                'Tipo'          => 'Enfermedad común',
                'Institución'   => 'ISSS',
                'Desde'         => '2026-04-14',
                'Hasta'         => '2026-04-18',
                'Días'          => '5 día(s)',
                'Observaciones' => 'Este es un correo de prueba del sistema de notificaciones de incapacidades.',
            ],
            linkUrl: rtrim(config('app.frontend_rrhh_url', 'https://rrhh.cervezacadejo.com'), '/') . '/incapacidades',
        );

        Mail::to($to)->send($mail);

        $this->info("Correo de prueba enviado a: {$to}");

        return 0;
    }
}
