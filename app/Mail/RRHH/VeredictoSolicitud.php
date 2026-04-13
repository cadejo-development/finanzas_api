<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email sent to the employee after their solicitud is approved or rejected.
 */
class VeredictoSolicitud extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string  $tipo,
        public readonly string  $empleadoNombre,
        public readonly string  $supervisorNombre,
        public readonly string  $estado,          // 'aprobado' | 'rechazado'
        public readonly array   $detalles,
        public readonly string  $linkUrl,
    ) {}

    public function envelope(): Envelope
    {
        $verbo = $this->estado === 'aprobado' ? 'aprobada' : 'rechazada';

        return new Envelope(
            subject: "Tu solicitud de {$this->tipo} fue {$verbo}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rrhh.veredicto-solicitud',
        );
    }
}
