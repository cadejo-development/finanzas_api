<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->string('observacion', 1000)->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->dropColumn('observacion');
        });
    }
};
