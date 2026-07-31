<?php

namespace App\Mail\Compras;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KristianAuditoriaNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string  $sucursalNombre,
        public readonly string  $fecha,
        public readonly string  $evaluadorNombre,
        public readonly ?float  $calificacion,
        public readonly ?string $clasificacion,
        public readonly ?string $comentarioGerente,
        public readonly bool    $apelo,
        public readonly string  $linkUrl,
    ) {}

    public function envelope(): Envelope
    {
        $asunto = $this->apelo
            ? "Apelación de Gerente — Auditoría Calidad {$this->sucursalNombre} ({$this->fecha})"
            : "Auditoría sin apelar — {$this->sucursalNombre} ({$this->fecha}) · Requiere revisión";

        return new Envelope(subject: $asunto);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.compras.kristian-auditoria');
    }
}
