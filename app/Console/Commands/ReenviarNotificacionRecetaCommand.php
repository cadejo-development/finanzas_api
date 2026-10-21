<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Compras\RecetasController;
use App\Mail\Compras\RecetaAutorizadaNotificacion;
use App\Models\Receta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ReenviarNotificacionRecetaCommand extends Command
{
    protected $signature = 'recetas:reenviar-notificacion
                            {codigos* : Uno o más codigo_origen de receta}
                            {--email=* : Destinatarios (default: javiermejia y marcelaorellana)}';

    protected $description = 'Reenvía el correo de "receta autorizada" para los códigos indicados';

    public function handle(): int
    {
        $codigos      = $this->argument('codigos');
        $destinatarios = $this->option('email') ?: ['javiermejia@cervezacadejo.com', 'marcelaorellana@cervezacadejo.com'];

        $controller = app(RecetasController::class);

        foreach ($codigos as $codigo) {
            $receta = Receta::with([
                'ingredientes.producto',
                'ingredientes.subReceta.productoAsociado',
                'ingredientes.subReceta.ingredientes.producto',
                'categoria', 'estado',
            ])->where('codigo_origen', $codigo)->first();

            if (!$receta) {
                $this->error("Receta no encontrada: {$codigo}");
                continue;
            }

            try {
                $recetaData = $controller->formatReceta($receta, null, true);

                $mailable = new RecetaAutorizadaNotificacion(
                    recetaData:      $recetaData,
                    recetaNombre:    $receta->nombre,
                    recetaCodigo:    $receta->codigo_origen ?? '',
                    recetaCategoria: $receta->categoria?->nombre ?? '',
                    autorizadoPor:   'sistema (reenvío)',
                    autorizadoEn:    now()->format('d/m/Y H:i'),
                );

                Mail::to($destinatarios)->send($mailable);
                $this->info("Enviado: {$receta->nombre} → " . implode(', ', $destinatarios));
            } catch (\Throwable $e) {
                $this->error("Error en {$codigo}: " . $e->getMessage());
            }
        }

        return 0;
    }
}
