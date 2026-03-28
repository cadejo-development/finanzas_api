<?php

namespace App\Console\Commands;

use App\Models\Receta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

class TestPdf extends Command
{
    protected $signature = 'app:test-pdf {id=24529}';
    protected $description = 'Test PDF generation for a recipe';

    public function handle()
    {
        $id = $this->argument('id');
        $this->info("Loading receta $id...");

        $receta = Receta::with([
            'categoria',
            'ingredientes.producto',
            'ingredientes.subReceta.productoAsociado',
            'ingredientes.subReceta.ingredientes.producto',
        ])->find($id);

        if (!$receta) { $this->error("Receta $id not found"); return 1; }

        $this->info("Receta: {$receta->nombre}");

        $data = [
            'nombre'             => $receta->nombre,
            'tipo_receta'        => $receta->tipo_receta ?? 'plato',
            'categoria'          => $receta->categoria?->nombre ?? $receta->tipo,
            'tipo'               => $receta->tipo,
            'ingredientes'       => $receta->ingredientes->map(fn($i) => [
                'producto_nombre'    => $i->producto?->nombre ?? $i->subReceta?->nombre ?? '?',
                'es_sub_receta'      => !is_null($i->sub_receta_id),
                'cantidad_por_plato' => (float) $i->cantidad_por_plato,
                'unidad'             => $i->unidad,
                'precio_unitario'    => (float) ($i->producto?->costo ?? 0),
            ])->toArray(),
            'precio'             => (float) ($receta->precio ?? 0),
            'rendimiento'        => $receta->rendimiento,
            'rendimiento_unidad' => $receta->rendimiento_unidad,
            'descripcion'        => $receta->descripcion,
            'instrucciones'      => $receta->instrucciones,
            'platos_semana'      => $receta->platos_semana ?? 0,
        ];

        $logoPath = public_path('logo.png');
        $logo = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : null;

        $this->info("Logo: " . ($logo ? 'found' : 'NOT FOUND at ' . $logoPath));
        $this->info("Generating PDF...");

        try {
            $pdf = Pdf::loadView('pdf.receta', [
                'receta'      => $data,
                'costo_total' => 0,
                'logo'        => $logo,
            ])->setPaper('letter', 'portrait');

            $content = $pdf->output();
            $this->info('PDF OK: ' . strlen($content) . ' bytes');
        } catch (\Throwable $e) {
            $this->error('PDF ERROR: ' . $e->getMessage());
            $this->error($e->getFile() . ':' . $e->getLine());
            return 1;
        }

        return 0;
    }
}
