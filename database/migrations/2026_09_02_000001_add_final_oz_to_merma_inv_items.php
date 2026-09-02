<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('merma_inv_items', function (Blueprint $table) {
            $table->decimal('final_oz', 10, 2)->nullable()->after('final_cerrados_g');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('merma_inv_items', function (Blueprint $table) {
            $table->dropColumn('final_oz');
        });
    }
};
