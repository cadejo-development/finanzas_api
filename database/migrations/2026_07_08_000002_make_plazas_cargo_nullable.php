<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->table('plazas', function (Blueprint $table) {
            $table->unsignedBigInteger('cargo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('plazas', function (Blueprint $table) {
            $table->unsignedBigInteger('cargo_id')->nullable(false)->change();
        });
    }
};
