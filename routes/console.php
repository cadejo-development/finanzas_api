<?php

use App\Console\Commands\InactivarEmpleadosDesvinculados;
use App\Console\Commands\AlertasPeriodoPrueba;
use App\Console\Commands\AlertasVacacionesVencimiento;
use App\Console\Commands\CerrarAuditoriasVencidas;
use App\Console\Commands\AplicarTrasladosPendientes;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Inactiva empleados cuya fecha efectiva de desvinculación ya llegó.
// Corre cada mañana a las 06:00.
Schedule::command(InactivarEmpleadosDesvinculados::class)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

// Alertas automáticas de períodos de prueba: vencimientos 15d / 7d / sin evaluación / primer día pendiente.
Schedule::command(AlertasPeriodoPrueba::class)
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer();

// Alertas de vacaciones próximas a vencer (30 días y 15 días antes del límite anual).
Schedule::command(AlertasVacacionesVencimiento::class)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Cierra para el gerente las auditorías de calidad cuyo plazo de 48h venció y notifica a Kristian.
Schedule::command(CerrarAuditoriasVencidas::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Aplica traslados aprobados cuya fecha_efectiva ya llegó (futuros que se convirtieron en hoy/pasado).
Schedule::command(AplicarTrasladosPendientes::class)
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

// NOTA: sync-brilo-stock NO corre desde App Runner — requiere VPN FortiClient hacia Brilo.
// Se ejecuta localmente con Windows Task Scheduler. Ver database/sync_brilo_scheduler.ps1
