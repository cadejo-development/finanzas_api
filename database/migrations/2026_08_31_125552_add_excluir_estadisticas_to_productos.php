<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            $table->boolean('excluir_estadisticas')->default(false)->after('activo');
        });

        // Materiales de empaque, cristalería, utensilios, etc. (no se comparan contra Brilo)
        $codigos = [
            // Cristalería Restaurantes
            'UT0101035','UT0101037','SV0413006','SV0413005','UT0101036',
            'PM0201011','PM0204005','PM0201004','PM0201005','PM0201012',
            'PM0201028','PM0201001',
            // Material de Empaque Restaurante
            'GT0201002','GT0201003','MR1403089','MR1403088',
            'GT07250902','GT07250901','GT0201029','GT0201028','SV0415007',
            'MR1402024','MR1402026','MR1402016','MR1402025','MR1402014',
            'GT0101075','GT0101073','GT0201027',
            'MR1402036','MR1402034','MR1402039','MR1402035','MR1402037','MR1402038',
            'MR0305005','MR1403003','GT0201025','MR1403090',
            'GT0901003','GT0901009','GT0601006',
            'SV0401011','SV0609006','SV0402012',
            'PM0101012','GT0201010','PM0204001','PM0204003',
            'PM03260601',
            // Material Mesa
            'MR0303003','MR0302007','MR0302011','MR0305027','MR0522033','MR0301017',
            // Químicos y Otros
            'MP0601015',
            // Utensilios de cocina
            'UT0101015','GT0101086','GT0101087','GT0101089','GT0101088',
            'SV0411005','SV0401013',
            'MR1403019','MR1403018','MR0302057',
            // Viñetas
            'ME0401013',
            // Adicionales WhatsApp
            'ME0301005', // ENVASE SUCIO 330ML OSCURO
            'MR0301004', // ACEITE VEGETAL PARA FREIDORA
            'MR0522028', // ACEITE VEGETAL PARA COCINAR
            'PM0101396', // DELANTAL DENIM
        ];

        DB::table('productos')
            ->whereIn('codigo', $codigos)
            ->update(['excluir_estadisticas' => true]);
    }

    public function down(): void
    {
        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            $table->dropColumn('excluir_estadisticas');
        });
    }
};
