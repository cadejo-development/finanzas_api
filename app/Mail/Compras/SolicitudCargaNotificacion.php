<?php

namespace App\Mail\Compras;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SolicitudCargaNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $solicitadoPorNombre,
        public readonly string $fechaRequerida,
        public readonly array  $recetaNombres,
        public readonly int    $totalRecetas,
        public readonly string $recetaIdsParam,
        public readonly ?string $nota,
        public readonly string $linkUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Solicitud de Carga a BRILO — {$this->totalRecetas} receta(s) para el {$this->fechaRequerida}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compras.solicitud-carga',
        );
    }
}
