<?php

namespace App\Console\Commands;

use App\Mail\Compras\AuditoriaConteoNotificacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ReenviarAuditoriaCorreos extends Command
{
    protected $signature   = 'auditoria:reenviar-correos {--auditoria_ids=19,15}';
    protected $description = 'Reenvía notificación de auditoría cerrada al gerente de sucursal';

    public function handle(): int
    {
        $ids = array_filter(array_map('intval', explode(',', $this->option('auditoria_ids'))));

        foreach ($ids as $audId) {
            $auditoria = DB::connection('compras')->table('conteo_auditorias')->where('id', $audId)->first();
            if (!$auditoria) {
                $this->warn("Auditoría #{$audId} no encontrada.");
                continue;
            }

            $sucursalNombre = DB::table('sucursales')->where('id', $auditoria->sucursal_id)->value('nombre') ?? 'Sucursal';
            $comprobados    = DB::connection('compras')->table('conteo_auditoria_items')
                ->where('auditoria_id', $audId)->where('comprobado', true)->count();

            $gerentes = DB::table('departamentos as d')
                ->join('empleados as e', 'e.id', '=', 'd.jefe_empleado_id')
                ->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('d.sucursal_id', $auditoria->sucursal_id)
                ->where('d.activo', true)->where('e.activo', true)->where('u.activo', true)
                ->whereNotNull('u.email')
                ->selectRaw("u.email, e.nombres || ' ' || e.apellidos as nombre")
                ->distinct()->get();

            if ($gerentes->isEmpty()) {
                $this->warn("#{$audId} ({$sucursalNombre}): no hay gerente en organigrama — omitido.");
                continue;
            }

            $destinatarios = $gerentes->map(fn($g) => ['email' => $g->email, 'name' => $g->nombre])->all();
            Mail::to($destinatarios)->send(new AuditoriaConteoNotificacion(
                sucursalNombre:   $sucursalNombre,
                fecha:            $auditoria->fecha_conteo,
                auditorNombre:    $auditoria->auditado_por_nombre ?? 'Auditor',
                totalComprobados: $comprobados,
                firma:            $auditoria->firma_auditoria ?? '',
            ));
            $this->info("#{$audId} ({$sucursalNombre}) → " . implode(', ', array_column($destinatarios, 'email')));
        }

        return 0;
    }
}
