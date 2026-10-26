<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class KpiPlantillaCreada extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string  $kpiNombre,
        public readonly string  $kpiDescripcion,
        public readonly string  $creadoPor,
        public readonly ?string $sucursalNombre,
        public readonly bool    $tieneBono,
        public readonly string  $linkUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Nuevo KPI creado: {$this->kpiNombre}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rrhh.kpi-plantilla-creada',
        );
    }
}
