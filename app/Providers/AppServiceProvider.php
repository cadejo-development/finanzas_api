<?php

namespace App\Providers;

use App\Models\Empleado;
use App\Models\PersonalAccessToken;
use App\Observers\EmpleadoObserver;
use Illuminate\Support\Facades\Mail;
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

        // Si MAIL_TEST_RECIPIENT está definido, todos los correos van solo a esa dirección.
        // Útil para pruebas en producción sin afectar destinatarios reales.
        // Quitar del .env cuando la prueba termine.
        if ($override = config('mail.test_recipient')) {
            Mail::alwaysTo($override);
        }
    }
}
