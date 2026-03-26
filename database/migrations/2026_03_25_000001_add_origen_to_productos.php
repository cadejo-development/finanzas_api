<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            $table->string('origen', 30)->default('restaurante')->after('precio');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
