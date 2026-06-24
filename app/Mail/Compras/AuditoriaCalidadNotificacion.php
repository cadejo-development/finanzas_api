<?php

namespace App\Mail\Compras;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditoriaCalidadNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string  $sucursalNombre,
        public readonly string  $fecha,
        public readonly string  $evaluadorNombre,
        public readonly ?float  $calificacion,
        public readonly ?string $clasificacion,
        public readonly ?string $observaciones,
        public readonly string  $linkUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Auditoría de Calidad finalizada — {$this->sucursalNombre} ({$this->fecha})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compras.auditoria-calidad',
        );
    }
}
