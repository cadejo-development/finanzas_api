<?php

namespace App\Console\Commands;

use App\Mail\RRHH\SolicitudAprobacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class RrhhTestEmail extends Command
{
    protected $signature   = 'rrhh:test-email {to? : Correo destino (default: javiermejia@cervezacadejo.com)}';
    protected $description = 'Envía un correo de prueba de solicitud de permiso RRHH y registra en email_logs';

    public function handle(): int
    {
        $to = $this->argument('to') ?? 'javiermejia@cervezacadejo.com';

        $aprobarUrl  = URL::temporarySignedRoute('rrhh.email.aprobar',  now()->addDays(5), ['tipo' => 'permiso', 'id' => 999]);
        $rechazarUrl = URL::temporarySignedRoute('rrhh.email.rechazar', now()->addDays(5), ['tipo' => 'permiso', 'id' => 999]);

        $mailable = new SolicitudAprobacion(
            tipo:             'Permiso',
            empleadoNombre:   'Carlos Alberto Mejía López',
            supervisorNombre: 'Javier Mejía',
            detalles:         [
                'Tipo de permiso'  => 'Personal',
                'Fecha inicio'     => '2026-04-14',
                'Fecha fin'        => '2026-04-15',
                'Días solicitados' => '2 días',
                'Motivo'           => 'Cita médica familiar',
                'Estado'           => 'Pendiente de aprobación',
            ],
            linkUrl:     'https://talentohumano.cervezacadejo.com/permisos',
            aprobarUrl:  $aprobarUrl,
            rechazarUrl: $rechazarUrl,
        );

        $this->info("Mailer activo: " . config('mail.default'));
        $this->info("Enviando correo de prueba a: {$to}");

        $estado       = 'enviado';
        $errorMsg     = null;
        $respuestaApi = null;

        try {
            Mail::to($to)->send($mailable);
            $this->info('✓ Correo enviado correctamente.');
        } catch (\Throwable $e) {
            $estado       = 'fallido';
            $errorMsg     = $e->getMessage();
            $respuestaApi = json_encode([
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            $this->error('✗ Error al enviar: ' . $e->getMessage());
        }

        try {
            DB::connection('pgsql')->table('email_logs')->insert([
                'sistema'         => 'rrhh',
                'tipo'            => 'prueba_envio',
                'destinatario'    => $to,
                'asunto'          => $mailable->envelope()->subject,
                'estado'          => $estado,
                'error_mensaje'   => $errorMsg,
                'respuesta_api'   => $respuestaApi,
                'enviado_por'     => 'artisan:rrhh:test-email',
                'referencia_id'   => null,
                'referencia_tipo' => 'test',
                'created_at'      => now(),
            ]);
            $this->info("✓ Registro guardado en email_logs (estado: {$estado})");
        } catch (\Throwable $e) {
            $this->error('✗ Error al escribir en email_logs: ' . $e->getMessage());
        }

        return $estado === 'enviado' ? self::SUCCESS : self::FAILURE;
    }
}
