<?php

namespace App\Providers;

use App\Models\Empleado;
use App\Models\PersonalAccessToken;
use App\Observers\EmpleadoObserver;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Empleado::observe(EmpleadoObserver::class);

        // Migraciones del schema de finanzas/pagos (DB secundaria)
        $this->loadMigrationsFrom(database_path('migrations_finanzas'));
    }
}
