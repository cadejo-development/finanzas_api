<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email directed at the employee themselves.
 * Used for: amonestaciones, ausencias, traslados (al equipo IT), etc.
 */
class NotificacionAlEmpleado extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string  $tipo,
        public readonly string  $empleadoNombre,
        public readonly string  $mensaje,
        public readonly array   $detalles,
        public readonly string  $linkUrl,
        public readonly string  $destinatarioNombre,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Notificación de RRHH: {$this->tipo}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rrhh.notificacion-empleado',
        );
    }
}
