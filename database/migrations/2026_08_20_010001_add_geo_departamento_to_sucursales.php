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
        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->unsignedSmallInteger('geo_departamento_id')->nullable()->after('id');
            $table->foreign('geo_departamento_id')->references('id')->on('geo_departamentos')->nullOnDelete();
        });

        // Seed: La Libertad = 5, San Salvador = 6, La Paz = 8
        DB::connection('pgsql')->table('sucursales')
            ->whereIn('id', [3, 8, 9, 10, 11])
            ->update(['geo_departamento_id' => 5]); // La Libertad

        DB::connection('pgsql')->table('sucursales')
            ->whereIn('id', [1, 7, 14, 21])
            ->update(['geo_departamento_id' => 6]); // San Salvador

        DB::connection('pgsql')->table('sucursales')
            ->whereIn('id', [4, 5, 16])
            ->update(['geo_departamento_id' => 8]); // La Paz
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->dropForeign(['geo_departamento_id']);
            $table->dropColumn('geo_departamento_id');
        });
    }
};
