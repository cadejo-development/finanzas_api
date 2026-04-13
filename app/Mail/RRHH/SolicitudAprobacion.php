<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email sent to a supervisor when a subordinate submits a
 * permiso or vacaciones request that requires approval.
 */
class SolicitudAprobacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $tipo,
        public readonly string $empleadoNombre,
        public readonly string $supervisorNombre,
        public readonly array  $detalles,
        public readonly string $linkUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud de {$this->tipo} pendiente de aprobación — {$this->empleadoNombre}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rrhh.solicitud-aprobacion',
        );
    }
}
