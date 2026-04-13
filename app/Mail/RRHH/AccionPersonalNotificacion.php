<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Informational email sent to a supervisor when an action is
 * registered for a subordinate (e.g. incapacidad).
 * No approval required — notification only.
 */
class AccionPersonalNotificacion extends Mailable
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
            subject: "Notificación: {$this->tipo} registrada — {$this->empleadoNombre}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rrhh.accion-notificacion',
        );
    }
}
