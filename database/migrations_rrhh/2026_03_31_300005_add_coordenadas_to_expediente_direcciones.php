<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        Schema::connection('rrhh')->table('expediente_direcciones', function (Blueprint $table) {
            $table->decimal('latitud',  10, 7)->nullable()->after('referencia');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('expediente_direcciones', function (Blueprint $table) {
            $table->dropColumn(['latitud', 'longitud']);
        });
    }
};
