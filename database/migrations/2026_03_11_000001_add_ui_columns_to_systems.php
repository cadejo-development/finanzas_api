<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->string('url')->nullable()->after('codigo');
            $table->string('color', 20)->default('#6366f1')->after('url');
            $table->string('icon')->nullable()->after('color');
            $table->string('descripcion')->nullable()->after('icon');
        });

        // Actualizar los sistemas existentes con sus URLs y metadata visual
        DB::table('systems')->where('codigo', 'pagos')->update([
            'url'         => env('APP_PAGOS_URL', 'https://pagos.cadejo.app'),
            'color'       => '#f59e0b',
            'icon'        => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'descripcion' => 'Gestión de solicitudes de pago y proveedores',
        ]);

        DB::table('systems')->where('codigo', 'compras')->update([
            'url'         => env('APP_COMPRAS_URL', 'https://compras.cadejo.app'),
            'color'       => '#10b981',
            'icon'        => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
            'descripcion' => 'Pedidos semanales y gestión de compras',
        ]);

        DB::table('systems')->where('codigo', 'mansion')->update([
            'url'         => env('APP_MANSION_URL', 'https://mansion.cadejo.app'),
            'color'       => '#8b5cf6',
            'icon'        => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
            'descripcion' => 'Reservas y gestión de Cadejo Mansión',
        ]);
    }

    public function down(): void
    {
        Schema::table('systems', function (Blueprint $table) {
            $table->dropColumn(['url', 'color', 'icon', 'descripcion']);
        });
    }
};
