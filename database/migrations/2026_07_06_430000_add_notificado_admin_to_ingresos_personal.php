<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('ingresos_personal', function (Blueprint $table) {
            $table->timestamp('notificado_admin_en')->nullable()->after('aud_usuario');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('ingresos_personal', function (Blueprint $table) {
            $table->dropColumn('notificado_admin_en');
        });
    }
};
