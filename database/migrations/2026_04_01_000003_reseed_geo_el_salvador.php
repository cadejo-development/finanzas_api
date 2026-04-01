<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'TRUNCATE geo_municipios, geo_distritos, geo_departamentos RESTART IDENTITY CASCADE'
        );
        (new \Database\Seeders\GeoElSalvadorSeeder())->run();
    }

    public function down(): void
    {
        // datos geográficos — no reversible
    }
};
