<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('auditoria_criterios', function (Blueprint $table) {
            $table->decimal('peso', 8, 4)->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('auditoria_criterios', function (Blueprint $table) {
            $table->integer('peso')->default(1)->change();
        });
    }
};
