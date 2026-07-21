<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement(
            'ALTER TABLE kpi_plantillas ALTER COLUMN monto_objetivo TYPE VARCHAR(50) USING monto_objetivo::text'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            "ALTER TABLE kpi_plantillas ALTER COLUMN monto_objetivo TYPE DECIMAL(10,2) USING NULLIF(regexp_replace(monto_objetivo, '[^0-9.]', '', 'g'), '')::decimal"
        );
    }
};
