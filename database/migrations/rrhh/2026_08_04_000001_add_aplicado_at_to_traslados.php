<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('traslados', function (Blueprint $table) {
            $table->timestamp('aplicado_at')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('traslados', function (Blueprint $table) {
            $table->dropColumn('aplicado_at');
        });
    }
};
