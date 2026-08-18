<?php

namespace App\Mail\Compras;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditoriaConteoNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $sucursalNombre,
        public readonly string $fecha,
        public readonly string $auditorNombre,
        public readonly int    $totalComprobados,
        public readonly string $firma,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Auditoría de conteo completada — {$this->sucursalNombre} ({$this->fecha})",
            cc: [new Address('marcelaorellana@cervezacadejo.com', 'Ana Marcela Orellana')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compras.auditoria-conteo',
        );
    }
}
