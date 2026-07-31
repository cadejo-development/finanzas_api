<?php

namespace App\Console\Commands;

use App\Models\AuditoriaReceta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * php artisan compras:cerrar-auditorias-vencidas
 *
 * Revisa auditorías en estado pendiente_respuesta cuyo plazo de 48h ya venció.
 * Las transiciona a 'respondida' (gerente_respondio=false) y notifica a Kristian.
 * Se programa para correr cada hora.
 */
class CerrarAuditoriasVencidas extends Command
{
    protected $signature = 'compras:cerrar-auditorias-vencidas
                            {--dry-run : Lista auditorías vencidas sin procesarlas}';

    protected $description = 'Cierra para el gerente las auditorías cuyo plazo de 48h venció y notifica a Kristian.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $vencidas = AuditoriaReceta::where('estado', 'pendiente_respuesta')
            ->whereNotNull('gerente_deadline_at')
            ->where('gerente_deadline_at', '<', now())
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('No hay auditorías vencidas.');
            return 0;
        }

        $this->info("Auditorías vencidas encontradas: {$vencidas->count()}");

        foreach ($vencidas as $auditoria) {
            if ($dryRun) {
                $this->line("  [dry-run] ID {$auditoria->id} — sucursal {$auditoria->sucursal_id}");
                continue;
            }

            try {
                $auditoria->update([
                    'estado'                 => 'respondida',
                    'gerente_respondio'      => false,
                    'kristian_notificado_at' => now(),
                    'kristian_deadline_at'   => now()->addHours(48),
                ]);

                $sucursalNombre = DB::connection('pgsql')
                    ->table('sucursales')
                    ->where('id', $auditoria->sucursal_id)
                    ->value('nombre') ?? "Sucursal {$auditoria->sucursal_id}";

                $linkUrl = config('app.frontend_compras_url', 'https://gestion-operaciones.cervezacadejo.com') . '/auditorias-calidad';

                Mail::to('kristian@cervezacadejo.com')->send(
                    new \App\Mail\Compras\KristianAuditoriaNotificacion(
                        sucursalNombre:    $sucursalNombre,
                        fecha:             $auditoria->fecha?->format('d/m/Y') ?? '',
                        evaluadorNombre:   $auditoria->evaluador_nombre ?? '',
                        calificacion:      $auditoria->calificacion !== null ? (float) $auditoria->calificacion : null,
                        clasificacion:     $auditoria->clasificacion,
                        comentarioGerente: null,
                        apelo:             false,
                        linkUrl:           $linkUrl,
                    )
                );

                DB::connection('pgsql')->table('email_logs')->insert([
                    'sistema'         => 'compras',
                    'tipo'            => 'auditoria_kristian',
                    'destinatario'    => 'kristian@cervezacadejo.com',
                    'asunto'          => "Auditoría sin apelar — {$sucursalNombre}",
                    'estado'          => 'enviado',
                    'enviado_por'     => 'sistema',
                    'referencia_id'   => $auditoria->id,
                    'referencia_tipo' => 'auditoria',
                    'created_at'      => now(),
                ]);

                $this->info("  ✓ ID {$auditoria->id} cerrada para gerente · Kristian notificado");
            } catch (\Throwable $e) {
                Log::error('CerrarAuditoriasVencidas: error procesando auditoría', [
                    'auditoria_id' => $auditoria->id,
                    'error'        => $e->getMessage(),
                ]);
                $this->error("  ✗ ID {$auditoria->id} — {$e->getMessage()}");
            }
        }

        return 0;
    }
}
