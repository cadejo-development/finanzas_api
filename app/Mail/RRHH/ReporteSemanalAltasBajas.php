<?php

namespace App\Mail\RRHH;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReporteSemanalAltasBajas extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $semanaLabel,    // e.g. "13 – 19 oct 2026"
        public readonly int    $totalAltas,
        public readonly int    $totalBajas,
        public readonly array  $altas,          // [['fecha','empleado','cargo','sucursal'], ...]
        public readonly array  $bajas,          // [['fecha','empleado','cargo','sucursal','tipo','motivo'], ...]
        public readonly array  $altasPorSucursal,
        public readonly array  $bajasPorSucursal,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Reporte Semanal de Altas y Bajas — {$this->semanaLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rrhh.reporte-semanal-altas-bajas',
        );
    }
}
