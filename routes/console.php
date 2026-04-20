<?php

use App\Console\Commands\InactivarEmpleadosDesvinculados;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Inactiva empleados cuya fecha efectiva de desvinculación ya llegó.
// Corre cada mañana a las 06:00.
Schedule::command('rrhh:inactivar-desvinculados')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/inactivar-desvinculados.log'));
