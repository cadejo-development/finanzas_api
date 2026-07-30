<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConteoProdSegNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $sucursalNombre,
        public string $fechaConteo,
        public string $aplicadoPor,
        public array  $items,        // [{nombre, unidad, brilo_stock, conteo, diferencia, tipo}]
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "✅ Conteo físico aplicado — {$this->sucursalNombre} ({$this->fechaConteo})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.conteo_prod_seg',
        );
    }
}
