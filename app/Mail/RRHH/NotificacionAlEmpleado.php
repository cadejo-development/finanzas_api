<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
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
        public readonly ?string $pdfContent = null,
        public readonly ?string $pdfNombre  = null,
    ) {}

    public function attachments(): array
    {
        if ($this->pdfContent && $this->pdfNombre) {
            return [
                Attachment::fromData(fn () => $this->pdfContent, $this->pdfNombre)
                    ->withMime('application/pdf'),
            ];
        }
        return [];
    }

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
