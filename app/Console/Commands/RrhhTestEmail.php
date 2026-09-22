<?php

namespace App\Console\Commands;

use App\Mail\RRHH\NotificacionAlEmpleado;
use App\Mail\RRHH\SolicitudAprobacion;
use App\Mail\RRHH\VeredictoSolicitud;
use App\Models\RRHH\CambioSalarial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class RrhhTestEmail extends Command
{
    protected $signature   = 'rrhh:test-email {to? : Correo destino (default: javiermejia@cervezacadejo.com)} {--veredicto= : Enviar solo correo veredicto: aprobado|rechazado} {--nivelacion : Probar notificación de nivelación salarial con botones Aprobar/Rechazar}';
    protected $description = 'Envía correos de prueba RRHH (solicitud con botones aprobar/rechazar, o veredicto al empleado)';

    public function handle(): int
    {
        $to        = $this->argument('to') ?? 'javiermejia@cervezacadejo.com';
        $veredicto = $this->option('veredicto');

        if ($this->option('nivelacion')) {
            return $this->enviarNivelacion($to);
        }

        if ($veredicto) {
            return $this->enviarVeredicto($to, $veredicto);
        }

        return $this->enviarSolicitud($to);
    }

    private function enviarNivelacion(string $to): int
    {
        $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');

        // Buscar nivelación pendiente existente para usar su token real
        $cambio = CambioSalarial::whereNotNull('aprobacion_token')
            ->where('estado', 'pendiente')
            ->latest()
            ->first();

        if (!$cambio) {
            // Crear registro de prueba mínimo si no hay ninguno pendiente
            $tipoId = DB::connection('rrhh')->table('tipos_aumento_salarial')->where('codigo', 'NIVELACION')->value('id');
            // Obtener cualquier empleado activo para la prueba
            $empId = DB::connection('pgsql')->table('empleados')->where('activo', true)->value('id');

            if (!$tipoId || !$empId) {
                $this->error('No se encontró tipo NIVELACION o empleado activo para la prueba.');
                return self::FAILURE;
            }

            $token = (string) Str::uuid();
            $cambio = CambioSalarial::create([
                'empleado_id'       => $empId,
                'solicitado_por_id' => $empId,
                'tipo_aumento_id'   => $tipoId,
                'salario_anterior'  => 800.00,
                'salario_nuevo'     => 950.00,
                'porcentaje'        => 18.75,
                'fecha_efectiva'    => now()->addMonth()->format('Y-m-d'),
                'justificacion'     => '[Prueba] Ajuste al mercado salarial sector alimentos',
                'estado'            => 'pendiente',
                'aprobacion_token'  => $token,
                'aud_usuario'       => 'artisan:test',
            ]);
            $this->info("✓ Creado registro de prueba ID={$cambio->id}");
        } else {
            $token = $cambio->aprobacion_token;
            $this->info("✓ Usando nivelación pendiente ID={$cambio->id}");
        }

        $mailable = new NotificacionAlEmpleado(
            tipo:               'Nivelación Salarial — Pendiente de aprobación',
            empleadoNombre:     'Juan Carlos López Torres (TEST)',
            mensaje:            'Se ha registrado una nueva Nivelación Salarial que requiere su aprobación. Por favor, revise los detalles y tome una decisión.',
            detalles:           [
                'Tipo de aumento'  => 'Nivelación de mercado',
                'Salario actual'   => '$800.00',
                'Salario nivelado' => '$950.00',
                'Porcentaje'       => '18.75%',
                'Fecha efectiva'   => '01/10/2026',
                'Justificación'    => 'Ajuste al mercado salarial',
                'Cargo'            => 'Chef de Cocina',
                'Sucursal'         => 'Santa Elena',
                'Solicitado por'   => 'María Fernández (TEST)',
            ],
            linkUrl:            "{$baseUrl}/modificaciones/nivelacion-salarial",
            destinatarioNombre: 'Destinatario de prueba',
            aprobarUrl:         "{$baseUrl}/nivelacion/revision/{$token}?accion=aprobar",
            rechazarUrl:        "{$baseUrl}/nivelacion/revision/{$token}?accion=rechazar",
        );

        $this->info("Enviando correo de prueba de nivelación a: {$to}");
        $this->info("Token: {$token}");
        $this->info("Aprobar: {$baseUrl}/nivelacion/revision/{$token}?accion=aprobar");
        $this->info("Rechazar: {$baseUrl}/nivelacion/revision/{$token}?accion=rechazar");

        try {
            Mail::to($to)->send($mailable);
            $this->info('✓ Correo enviado. Revisa tu bandeja y prueba los botones.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('✗ Error al enviar: ' . $e->getMessage());
            return self::FAILURE;
        }
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
