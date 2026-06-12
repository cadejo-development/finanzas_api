<?php

namespace App\Console\Commands;

use App\Models\RRHH\SaldoVacaciones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\RRHH\AccionPersonalNotificacion;

/**
 * php artisan rrhh:alertas-vacaciones-vencimiento
 *
 * Revisa colaboradores con saldo de vacaciones disponibles que aún no han
 * programado sus vacaciones y cuya fecha límite se acerca:
 *
 *  - Personal operativo de restaurantes: límite 31 de octubre de cada año.
 *  - Personal administrativo:            límite 31 de diciembre de cada año.
 *
 * Envía alerta cuando faltan 30 días y cuando faltan 15 días para el límite.
 * Se programa para correr diariamente a las 08:00.
 */
class AlertasVacacionesVencimiento extends Command
{
    protected $signature = 'rrhh:alertas-vacaciones-vencimiento
                            {--dry-run : Muestra alertas sin enviar emails}';

    protected $description = 'Envía alertas a RH sobre colaboradores con vacaciones próximas a vencer.';

    public function handle(): int
    {
        $hoy    = now();
        $anio   = $hoy->year;
        $dryRun = $this->option('dry-run');
        $total  = 0;

        // Límites por tipo de personal
        $limiteOperativo     = \Carbon\Carbon::create($anio, 10, 31); // 31 oct
        $limiteAdministrativo= \Carbon\Carbon::create($anio, 12, 31); // 31 dic

        // Saldos con días disponibles no usados
        $saldos = SaldoVacaciones::where('anio', $anio)
            ->whereRaw('dias_disponibles - dias_usados > 0')
            ->get();

        foreach ($saldos as $saldo) {
            $diasRestantes = (float) $saldo->dias_disponibles - (float) $saldo->dias_usados;
            if ($diasRestantes <= 0) continue;

            // Determinar si es restaurante para elegir el límite
            $esRestaurante = DB::connection('pgsql')
                ->table('empleados as e')
                ->join('sucursales as s', 's.id', '=', 'e.sucursal_id')
                ->where('e.id', $saldo->empleado_id)
                ->whereRaw("lower(s.nombre) NOT LIKE '%casa matriz%' AND lower(s.nombre) NOT LIKE '%corporativ%'")
                ->exists();

            $limite     = $esRestaurante ? $limiteOperativo : $limiteAdministrativo;
            $diasAlLimite = (int) $hoy->diffInDays($limite, false);

            if ($diasAlLimite <= 0 || $diasAlLimite > 30) continue; // Solo alertar dentro de 30 días

            // Obtener nombre del empleado
            $empleado = DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $saldo->empleado_id)
                ->select('nombres', 'apellidos', 'cargo')
                ->first();

            if (!$empleado) continue;

            $nombre = trim(($empleado->apellidos ?? '') . ', ' . ($empleado->nombres ?? ''));
            $tipo   = $diasAlLimite <= 15 ? '15 dias' : '30 dias';

            $this->line("  [{$tipo}] {$nombre} — {$diasRestantes} días pendientes — vence {$limite->toDateString()}");
            $total++;

            if (!$dryRun) {
                $this->enviarAlerta($nombre, $empleado->cargo ?? '', $diasRestantes, $limite->toDateString(), $diasAlLimite);
            }
        }

        if ($dryRun) {
            $this->warn("Modo --dry-run: {$total} alertas detectadas, no se enviaron emails.");
        } else {
            $this->info("Alertas de vencimiento enviadas: {$total}");
        }

        return 0;
    }

    private function enviarAlerta(string $nombre, string $cargo, float $diasRestantes, string $limite, int $diasAlLimite): void
    {
        try {
            $admins = DB::connection('pgsql')
                ->table('user_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.role_id')
                ->join('users as u', 'u.id', '=', 'ur.user_id')
                ->where('r.name', 'rrhh_admin')
                ->whereNotNull('u.email')
                ->pluck('u.email')
                ->unique()
                ->all();

            $frontendUrl = rtrim(env('FRONTEND_RRHH_URL', ''), '/') . '/vacaciones';

            foreach ($admins as $email) {
                Mail::to($email)->send(new AccionPersonalNotificacion(
                    'Vacaciones Proximas a Vencer',
                    $nombre,
                    'Recursos Humanos',
                    array_filter([
                        'Cargo'              => $cargo,
                        'Dias pendientes'    => number_format($diasRestantes, 1) . ' día(s)',
                        'Fecha limite'       => $limite,
                        'Dias para el limite'=> $diasAlLimite . ' día(s)',
                    ]),
                    $frontendUrl
                ));
            }

            Log::info('rrhh:alertas-vacaciones-vencimiento', [
                'empleado' => $nombre, 'dias_restantes' => $diasRestantes, 'limite' => $limite,
            ]);
        } catch (\Throwable $e) {
            Log::error('rrhh:alertas-vacaciones-vencimiento error', ['error' => $e->getMessage()]);
        }
    }
}
