<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReporteMensualAmonestaciones extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mesLabel,       // e.g. "Octubre 2026"
        public readonly int    $total,
        public readonly array  $porSucursal,    // [['sucursal'=>'...','total'=>N], ...]
        public readonly array  $porTipo,        // [['tipo'=>'...','total'=>N], ...]
        public readonly array  $detalle,        // rows enriquecidos
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reporte Mensual de Amonestaciones — {$this->mesLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rrhh.reporte-mensual-amonestaciones',
        );
    }
}
