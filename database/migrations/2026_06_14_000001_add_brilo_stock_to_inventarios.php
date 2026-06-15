<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            $table->decimal('brilo_stock', 14, 6)->nullable()->after('seccion');
            $table->timestamp('brilo_sync_at')->nullable()->after('brilo_stock');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            $table->dropColumn(['brilo_stock', 'brilo_sync_at']);
        });
    }
};
