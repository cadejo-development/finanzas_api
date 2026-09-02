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
        public array  $item,                // {codigo, nombre, unidad, diferencia, dif_pct, costo_diff, just_label, obs}
        public string $tipoResponsabilidad, // "error_receta" | "error_posteo" | "error_traslado" | "codigo_mp_equivocado"
    ) {}

    public function envelope(): Envelope
    {
        $accion = match($this->tipoResponsabilidad) {
            'error_receta'           => 'Revisión de receta',
            'error_posteo'           => 'Revisión de posteo',
            'error_traslado'         => 'Revisión de traslado',
            'codigo_mp_equivocado'   => 'Revisión de código MP',
            default                  => 'Revisión de inventario',
        };
        return new Envelope(
            subject: "⚠️ {$accion} — {$this->item['nombre']} ({$this->sucursalNombre})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.justificaciones_inventario',
        );
    }
}
