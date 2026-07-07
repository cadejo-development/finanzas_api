<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->table('ordenes_descuento', function (Blueprint $table) {
            $table->renameColumn('monto', 'monto_q1');
        });

        Schema::connection('pgsql')->table('ordenes_descuento', function (Blueprint $table) {
            $table->decimal('monto_q2', 10, 2)->default(0)->after('monto_q1');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('ordenes_descuento', function (Blueprint $table) {
            $table->dropColumn('monto_q2');
        });

        Schema::connection('pgsql')->table('ordenes_descuento', function (Blueprint $table) {
            $table->renameColumn('monto_q1', 'monto');
        });
    }
};
