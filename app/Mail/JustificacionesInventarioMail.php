<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JustificacionesInventarioMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $destinatarioNombre,  // "Kristian", "Nelson", "Rosa"
        public string $sucursalNombre,
        public string $fechaConteo,
        public string $gerenteNombre,       // quien envió la justificación
        public array  $items,               // [{codigo, nombre, unidad, diferencia, dif_pct, costo_diff, just_label, obs}, ...]
    ) {}

    public function envelope(): Envelope
    {
        $total = count($this->items);
        $label = $total === 1 ? "1 producto" : "{$total} productos";
        return new Envelope(
            subject: "⚠️ Revisión de inventario — {$label} ({$this->sucursalNombre})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.justificaciones_inventario',
        );
    }
}
