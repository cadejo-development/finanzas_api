<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // Re-seed con la nueva estructura de distritos/municipios
        DB::connection('pgsql')->statement(
            'TRUNCATE geo_municipios, geo_distritos, geo_departamentos RESTART IDENTITY CASCADE'
        );
        Artisan::call('db:seed', ['--class' => 'GeoElSalvadorSeeder', '--force' => true]);
    }

    public function down(): void
    {
        // No reversible: datos geográficos
    }
};
