<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('expediente_estudios', function (Blueprint $table) {
            $table->string('especializacion', 60)->nullable()->after('nivel');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('expediente_estudios', function (Blueprint $table) {
            $table->dropColumn('especializacion');
        });
    }
};
