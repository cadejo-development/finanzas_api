<?php

namespace App\Console\Commands;

use App\Mail\RRHH\SolicitudAprobacion;
use App\Mail\RRHH\VeredictoSolicitud;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class RrhhTestEmail extends Command
{
    protected $signature   = 'rrhh:test-email {to? : Correo destino (default: javiermejia@cervezacadejo.com)} {--veredicto= : Enviar solo correo veredicto: aprobado|rechazado}';
    protected $description = 'Envía correos de prueba RRHH (solicitud con botones aprobar/rechazar, o veredicto al empleado)';

    public function handle(): int
    {
        $to       = $this->argument('to') ?? 'javiermejia@cervezacadejo.com';
        $veredicto = $this->option('veredicto');

        if ($veredicto) {
            return $this->enviarVeredicto($to, $veredicto);
        }

        return $this->enviarSolicitud($to);
    }

    private function enviarSolicitud(string $to): int
    {
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

    private function enviarVeredicto(string $to, string $estadoVeredicto): int
    {
        if (! in_array($estadoVeredicto, ['aprobado', 'rechazado'])) {
            $this->error('--veredicto debe ser aprobado o rechazado');
            return self::FAILURE;
        }

        $mailable = new VeredictoSolicitud(
            tipo:             'Permiso',
            empleadoNombre:   'Carlos Alberto Mejía López',
            supervisorNombre: 'Javier Mejía',
            estado:           $estadoVeredicto,
            detalles:         [
                'Tipo de permiso' => 'Personal',
                'Fecha inicio'    => '2026-04-14',
                'Fecha fin'       => '2026-04-15',
                'Días'            => '2 días',
                'Motivo'          => 'Cita médica familiar',
            ],
            linkUrl: 'https://talentohumano.cervezacadejo.com/permisos',
        );

        $this->info("Enviando correo veredicto ({$estadoVeredicto}) a: {$to}");

        try {
            Mail::to($to)->send($mailable);
            $this->info('✓ Correo veredicto enviado correctamente.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('✗ Error: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
