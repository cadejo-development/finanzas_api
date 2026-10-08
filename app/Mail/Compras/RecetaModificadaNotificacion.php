<?php

namespace App\Mail\Compras;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecetaModificadaNotificacion extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array  $recetaData,
        public readonly string $recetaNombre,
        public readonly string $recetaCodigo,
        public readonly string $recetaCategoria,
        public readonly string $recetaEstado,
        public readonly string $modificadoPor,
        public readonly string $modificadoEn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Receta modificada: {$this->recetaNombre}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compras.receta-modificada',
        );
    }

    public function attachments(): array
    {
        $costoTotal = collect($this->recetaData['ingredientes'] ?? [])->sum(
            fn ($i) => (float) ($i['precio_unitario'] ?? 0) * (float) ($i['cantidad_por_plato'] ?? 0)
        );

        $pdf = Pdf::loadView('pdf.receta', [
            'receta'             => $this->recetaData,
            'costo_total'        => $costoTotal,
            'foto_plato'         => null,
            'foto_plateria'      => null,
            'sucursal_nombre'    => 'Cadejo Brewing Company',
            'sucursales_nombres' => null,
        ])->setPaper('letter', 'portrait');

        $nombre = preg_replace('/[^A-Za-z0-9_\-]/', '_', $this->recetaNombre);

        return [
            Attachment::fromData(
                fn () => $pdf->output(),
                "receta_{$nombre}.pdf"
            )->withMime('application/pdf'),
        ];
    }
}
