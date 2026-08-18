<?php

namespace App\Mail\Compras;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RespuestaAuditoriaConteoNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string  $sucursalNombre,
        public readonly string  $fecha,
        public readonly string  $respondente,
        public readonly string  $decision,
        public readonly ?string $comentarios,
    ) {}

    public function envelope(): Envelope
    {
        $accion = $this->decision === 'aprobada' ? 'aprobada' : 'rechazada';
        return new Envelope(
            subject: "Auditoría de conteo {$accion} — {$this->sucursalNombre} ({$this->fecha})",
            cc: [new Address('marcelaorellana@cervezacadejo.com', 'Ana Marcela Orellana')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compras.respuesta-auditoria-conteo',
        );
    }
}
