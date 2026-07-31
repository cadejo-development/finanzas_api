<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('auditorias_receta', function (Blueprint $table) {
            $table->timestampTz('submitted_at')->nullable()->after('aud_usuario');
            $table->timestampTz('gerente_deadline_at')->nullable()->after('submitted_at');
            $table->boolean('gerente_respondio')->nullable()->after('gerente_deadline_at');
            $table->timestampTz('kristian_notificado_at')->nullable()->after('gerente_respondio');
            $table->timestampTz('kristian_deadline_at')->nullable()->after('kristian_notificado_at');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('auditorias_receta', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_at',
                'gerente_deadline_at',
                'gerente_respondio',
                'kristian_notificado_at',
                'kristian_deadline_at',
            ]);
        });
    }
};
