<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CalidadCriteriosSeeder extends Seeder
{
    public function run(): void
    {
        // Evitar duplicados si se re-ejecuta
        DB::connection('compras')
            ->table('auditoria_criterios')
            ->where('tipo', 'calidad')
            ->delete();

        $secciones = [

            // ── 1. Recepción y Almacenamiento de Materias Primas ─────────────
            [
                'categoria'       => 'Recepción y Almacenamiento de Materias Primas',
                'categoria_orden' => 1,
                'criterios' => [
                    ['orden' => 1,  'peso' => 0.116, 'nombre' => 'Envases de alimentos limpios e íntegros: libres de rupturas, humedad, sin señales de insectos o plagas, materia extraña o presencia de moho. Latas sin abombamientos, abolladuras o corrosión. Latas limpias y desinfectadas.'],
                    ['orden' => 2,  'peso' => 0.116, 'nombre' => 'Se cuenta con bancos, taras de arrastre o alguna superficie que evite que las materias primas se coloquen sobre el piso.'],
                    ['orden' => 3,  'peso' => 0.116, 'nombre' => 'Todos los productos cuentan con etiquetas en español que especifique el nombre del producto y con fecha de caducidad o consumo preferente vigente.'],
                    ['orden' => 4,  'peso' => 0.116, 'nombre' => 'El personal cuenta con las facilidades para lavarse las manos y ejecuta correctamente la técnica de lavado de manos.'],
                    ['orden' => 5,  'peso' => 0.116, 'nombre' => 'Se cuenta con el registro de recepción de materia prima.'],
                    ['orden' => 6,  'peso' => 0.116, 'nombre' => 'Se cuenta con el programa general de limpieza profunda.'],
                    ['orden' => 7,  'peso' => 0.066, 'nombre' => 'Se aplica el procedimiento PEPS: productos secos porcionados con etiqueta de fecha de porcionado y caducidad; todos los productos con fecha de entrada en etiqueta de color blanco.'],
                    ['orden' => 8,  'peso' => 0.066, 'nombre' => 'Los termómetros para medir la temperatura interna de los alimentos se ajustan todos los días por punto de congelación, cuando se caen o se cambia bruscamente de temperatura. Se verifica su funcionamiento y se limpian antes de su uso.'],
                    ['orden' => 9,  'peso' => 0.066, 'nombre' => 'Se evita el resguardo de cajas de cartón, al menos que la etiqueta del producto lo indique.'],
                    ['orden' => 10, 'peso' => 0.011, 'nombre' => 'Se verifican las temperaturas para cada producto: Refrigerados máximo 4°C / Congelados mínimo –18°C. Los alimentos congelados se reciben sin signos de descongelación o recongelación. Productos perecederos enhielados no están en contacto directo con el hielo.'],
                    ['orden' => 11, 'peso' => 0.011, 'nombre' => 'Materias primas, alimentos y bebidas almacenados y agrupados de acuerdo con su naturaleza y recomendaciones del fabricante (huevo en refrigeración).'],
                    ['orden' => 12, 'peso' => 0.011, 'nombre' => 'Los alimentos rechazados están marcados y separados del resto, en área identificada como "área de productos inmovilizados".'],
                    ['orden' => 13, 'peso' => 0.011, 'nombre' => 'Los productos son transportados en vehículos limpios que evitan contaminación física, química, biológica o por plagas; con refrigeración o congelación cuando aplique.'],
                    ['orden' => 14, 'peso' => 0.011, 'nombre' => 'El proveedor cumple con los lineamientos mínimos de higiene.'],
                    ['orden' => 15, 'peso' => 0.011, 'nombre' => 'Anaqueles de superficie inerte, limpios, en buen estado y a una distancia del piso de 20 cm para facilitar la limpieza.'],
                    ['orden' => 16, 'peso' => 0.011, 'nombre' => 'La báscula se encuentra completa, limpia y sin presencia de oxidación en la parte de contacto con los alimentos. Se desinfecta antes y después de su uso.'],
                    ['orden' => 17, 'peso' => 0.011, 'nombre' => 'El área se encuentra limpia, libre de residuos, polvo o basura.'],
                    ['orden' => 18, 'peso' => 0.011, 'nombre' => 'Se cuenta con el plan de recepción de materia prima.'],
                ],
            ],

            // ── 2. Manejo de Productos Químicos y Equipos de Limpieza ────────
            [
                'categoria'       => 'Manejo de Productos Químicos y Equipos de Limpieza',
                'categoria_orden' => 2,
                'criterios' => [
                    ['orden' => 1, 'peso' => 0.233, 'nombre' => 'Recipientes cerrados e identificados que contengan detergentes, agentes de limpieza, químicos y sustancias tóxicas.'],
                    ['orden' => 2, 'peso' => 0.233, 'nombre' => 'Las soluciones de productos químicos se encuentran limpias, etiquetadas y a la concentración establecida por el proveedor, evitando contacto con materias primas, productos en proceso o producto terminado.'],
                    ['orden' => 3, 'peso' => 0.233, 'nombre' => 'Se cuenta con la carpeta con las fichas técnicas y HDS de todos los químicos usados en la unidad.'],
                    ['orden' => 4, 'peso' => 0.066, 'nombre' => 'Los recipientes y envases vacíos que contuvieron alimentos no son utilizados para almacenar productos químicos o viceversa.'],
                    ['orden' => 5, 'peso' => 0.066, 'nombre' => 'Se cuenta con tiras reactivas para la medición de la concentración de las soluciones.'],
                    ['orden' => 6, 'peso' => 0.066, 'nombre' => 'Lugar específico para la guarda de escobas, trapeadores, recogedores, fibras y utensilios de limpieza, separado del área de manipulación de alimentos.'],
                    ['orden' => 7, 'peso' => 0.025, 'nombre' => 'Los productos químicos en uso cuentan con fecha de apertura marcada a 6 dígitos con marcado indeleble.'],
                    ['orden' => 8, 'peso' => 0.025, 'nombre' => 'El área de resguardo de productos químicos se encuentra limpia, seca y en orden.'],
                    ['orden' => 9, 'peso' => 0.025, 'nombre' => 'Almacenamiento de productos de limpieza, desinfectantes y otros químicos en lugar delimitado, debidamente identificado y separado de cualquier área de manejo o almacenamiento de alimentos.'],
                    ['orden' => 10, 'peso' => 0.025, 'nombre' => 'Los artículos de limpieza se encuentran limpios después de su uso y se cuenta con instalaciones exclusivas para el lavado de artículos de limpieza.'],
                ],
            ],

            // ── 3. Equipos y Cámaras de Refrigeración y Congelación ─────────
            [
                'categoria'       => 'Equipos y Cámaras de Refrigeración y Congelación',
                'categoria_orden' => 3,
                'criterios' => [
                    ['orden' => 1,  'peso' => 0.233, 'nombre' => 'Alimentos en recipientes íntegros, limpios y cerrados, libres de rupturas, humedad, sin señales de insectos o plagas, materia extraña o presencia de moho.'],
                    ['orden' => 2,  'peso' => 0.233, 'nombre' => 'Sin alimentos o recipientes colocados directamente sobre el piso.'],
                    ['orden' => 3,  'peso' => 0.233, 'nombre' => 'Todos los productos cuentan con etiquetas en español que especifique el nombre del producto y con fecha de caducidad o consumo preferente vigente.'],
                    ['orden' => 4,  'peso' => 0.05,  'nombre' => 'La temperatura interna de los alimentos refrigerados se encuentra máximo de 4 a 7°C.'],
                    ['orden' => 5,  'peso' => 0.05,  'nombre' => 'La temperatura interna de los alimentos congelados se encuentra a -18°C o inferior.'],
                    ['orden' => 6,  'peso' => 0.05,  'nombre' => 'Se aplica el procedimiento PEPS: alimentos con fecha de elaboración a 6 dígitos en etiqueta verde, productos trasvasados con nombre y fechas, alimentos en descongelación con fecha de inicio de descongelación.'],
                    ['orden' => 7,  'peso' => 0.05,  'nombre' => 'Los alimentos crudos están colocados en la parte inferior o separados. Se realiza buen acomodo de productos, materia prima y alimentos cocidos.'],
                    ['orden' => 8,  'peso' => 0.014, 'nombre' => 'Los alimentos rechazados están marcados y separados del resto, en área identificada como "área de productos inmovilizados".'],
                    ['orden' => 9,  'peso' => 0.014, 'nombre' => 'El termómetro de la unidad se encuentra limpio, en lugar visible, funcionando y en buen estado.'],
                    ['orden' => 10, 'peso' => 0.014, 'nombre' => 'Los equipos y cámaras de refrigeración y congelación se encuentran limpios en todas sus partes.'],
                    ['orden' => 11, 'peso' => 0.014, 'nombre' => 'En caso de congelador horizontal: se observa orden y acomodo de los alimentos.'],
                    ['orden' => 12, 'peso' => 0.014, 'nombre' => 'Equipos sin funcionar se encuentran identificados como "Equipos Fuera de Servicio".'],
                    ['orden' => 13, 'peso' => 0.014, 'nombre' => 'Los alimentos elaborados dentro de la unidad y refrigerados cumplen con el tiempo de vida útil establecido de máximo 3 días.'],
                    ['orden' => 14, 'peso' => 0.014, 'nombre' => 'Los alimentos elaborados dentro de la unidad y congelados cumplen con el tiempo de vida útil establecido de máximo 30 días.'],
                ],
            ],

            // ── 4. Control de Operaciones y Procesos en Cocina ──────────────
            [
                'categoria'       => 'Control de Operaciones y Procesos en Cocina',
                'categoria_orden' => 4,
                'criterios' => [
                    ['orden' => 1,  'peso' => 0.058, 'nombre' => 'Superficies de contacto con alimentos (licuadoras, rebanadoras, procesadoras, mezcladoras, etc.) se lavan y desinfectan después de su uso y se desarman al menos cada 24 horas.'],
                    ['orden' => 2,  'peso' => 0.058, 'nombre' => 'Las tablas y cuchillos se encuentran en buenas condiciones, limpios, libres de malos olores y desfiletados. Se usa de acuerdo al código de colores y se desinfectan antes de su uso.'],
                    ['orden' => 3,  'peso' => 0.058, 'nombre' => 'Los utensilios se lavan y desinfectan después de su uso. Se evita mezclar utensilios limpios con sucios durante el servicio.'],
                    ['orden' => 4,  'peso' => 0.058, 'nombre' => 'Se cuenta con estación LLEDS completa e identificada, se ejecuta correctamente el procedimiento de limpieza y desinfección. Se usan trapos exclusivos por área.'],
                    ['orden' => 5,  'peso' => 0.058, 'nombre' => 'Todos los productos cuentan con etiquetas en español que especifique nombre y fecha de caducidad o consumo preferente vigente.'],
                    ['orden' => 6,  'peso' => 0.058, 'nombre' => 'Se evita mantener productos en contacto directo con el suelo.'],
                    ['orden' => 7,  'peso' => 0.058, 'nombre' => 'El personal se lava las manos antes de manipular alimentos, vajilla limpia y después de cualquier situación que implique contaminación. Técnica y frecuencia correctas.'],
                    ['orden' => 8,  'peso' => 0.058, 'nombre' => 'Los alimentos de origen vegetal se lavan en forma individual con agua potable, jabón o detergente, se enjuagan y desinfectan.'],
                    ['orden' => 9,  'peso' => 0.058, 'nombre' => 'Se planea de antemano la descongelación de alimentos: por refrigeración, horno de microondas seguido de cocción, o como parte del proceso de cocción. En casos excepcionales a chorro de agua máximo 20°C.'],
                    ['orden' => 10, 'peso' => 0.058, 'nombre' => 'Alimentos en recipientes íntegros, limpios y cerrados, libres de rupturas, humedad, sin señales de plagas, materia extraña o moho.'],
                    ['orden' => 11, 'peso' => 0.058, 'nombre' => 'Se cuenta con el Programa General de Limpieza Profunda de cada área.'],
                    ['orden' => 12, 'peso' => 0.058, 'nombre' => 'Se cuenta con resultados de análisis microbiológicos que comprueben la calidad de los alimentos elaborados en la unidad.'],
                    ['orden' => 13, 'peso' => 0.018, 'nombre' => 'La máquina lavaloza se encuentra en buen estado, limpia y desincrustada en su interior.'],
                    ['orden' => 14, 'peso' => 0.018, 'nombre' => 'Las temperaturas de la máquina lavaloza son las especificadas por el fabricante y/o el proveedor de productos químicos.'],
                    ['orden' => 15, 'peso' => 0.018, 'nombre' => 'Se aplica el procedimiento PEPS: alimentos procesados con fecha en etiqueta verde, productos trasvasados con nombre y fechas, alimentos abiertos con fecha de apertura visible.'],
                    ['orden' => 16, 'peso' => 0.018, 'nombre' => 'Estación de lavado de manos exclusiva en cocina: limpia, desinfectada, con agua, jabón antibacterial, cepillo en solución desinfectante, toallas desechables y bote de basura con pedal.'],
                    ['orden' => 17, 'peso' => 0.018, 'nombre' => 'Los termómetros se ajustan diariamente por punto de congelación, cuando se caen o cambia bruscamente de temperatura. Se limpian y desinfectan antes de su uso.'],
                    ['orden' => 18, 'peso' => 0.018, 'nombre' => 'Temperaturas mínimas internas de cocción: Cerdo y carne molida 69°C/15s; Aves, embutidos y carnes rellenas 74°C/15s; Resto de alimentos 63°C/15s.'],
                    ['orden' => 19, 'peso' => 0.018, 'nombre' => 'Los alimentos son recalentados rápidamente a temperatura interna mínima de 74°C por 15 segundos.'],
                    ['orden' => 20, 'peso' => 0.018, 'nombre' => 'Los alimentos preparados que no se sirven de inmediato se someten a enfriamiento rápido (máximo 2 horas), se mantienen tapados e identificados con la hora de proceso.'],
                    ['orden' => 21, 'peso' => 0.018, 'nombre' => 'Se evita la contaminación cruzada entre materia prima, producto en elaboración y producto terminado.'],
                    ['orden' => 22, 'peso' => 0.018, 'nombre' => 'El hielo empleado para enfriamiento de alimentos proviene de fuente confiable y se almacena correctamente. No se usa en preparación de bebidas.'],
                    ['orden' => 23, 'peso' => 0.018, 'nombre' => 'Se cuenta con el registro de temperatura de aparatos y alimentos en refrigeración o congelación.'],
                    ['orden' => 24, 'peso' => 0.006, 'nombre' => 'Estufa, hornos, planchas, salamandras, freidoras, marmitas, vaporeras, mesas calientes, etc., limpias en todas sus partes, sin cochambre y en buen estado.'],
                    ['orden' => 25, 'peso' => 0.006, 'nombre' => 'Las campanas y los filtros se encuentran sin cochambre, escurrimiento de grasa y están limpios.'],
                    ['orden' => 26, 'peso' => 0.006, 'nombre' => 'Solo se emplean utensilios de superficie inerte y en buen estado. Se almacenan en área específica, limpia y que evite su contaminación.'],
                    ['orden' => 27, 'peso' => 0.006, 'nombre' => 'Los pisos, paredes, techos, carros de servicio, entrepaños, gavetas, tarjas y repisas se encuentran limpios y desinfectados.'],
                    ['orden' => 28, 'peso' => 0.006, 'nombre' => 'Se usan recipientes o utensilios específicos o desechables para probar la sazón de los alimentos, minimizando el contacto de los alimentos con la mano.'],
                    ['orden' => 29, 'peso' => 0.006, 'nombre' => 'Los alimentos elaborados en la unidad cumplen con el tiempo de vida útil establecido de máximo 3 días.'],
                    ['orden' => 30, 'peso' => 0.006, 'nombre' => 'No se sirven pescados, mariscos, ni carnes crudas (o se especifica en carta el riesgo que implica para el comensal).'],
                    ['orden' => 31, 'peso' => 0.006, 'nombre' => 'Los alimentos descongelados no se vuelven a congelar.'],
                    ['orden' => 32, 'peso' => 0.006, 'nombre' => 'Registro de procesos de carnicería y descongelamiento programado.'],
                    ['orden' => 33, 'peso' => 0.006, 'nombre' => 'Registro de ajuste de termómetro.'],
                    ['orden' => 34, 'peso' => 0.006, 'nombre' => 'Registro de cocción.'],
                    ['orden' => 35, 'peso' => 0.006, 'nombre' => 'Registro de control de LLEDS de frutas y verduras.'],
                    ['orden' => 36, 'peso' => 0.006, 'nombre' => 'Registro de descongelamiento de emergencia.'],
                    ['orden' => 37, 'peso' => 0.006, 'nombre' => 'Registro de enfriamiento seguro.'],
                    ['orden' => 38, 'peso' => 0.006, 'nombre' => 'Registro de exposición de alimentos calientes y fríos.'],
                    ['orden' => 39, 'peso' => 0.006, 'nombre' => 'Monitoreo de máquina de hielo.'],
                    ['orden' => 40, 'peso' => 0.006, 'nombre' => 'Registro de recalentamiento seguro.'],
                    ['orden' => 41, 'peso' => 0.006, 'nombre' => 'Se cuenta con el plan de acción derivado de las desviaciones de inspecciones anteriores.'],
                ],
            ],

            // ── 5. Manejo de Residuos y Control de Plagas ───────────────────
            [
                'categoria'       => 'Manejo de Residuos y Control de Plagas',
                'categoria_orden' => 5,
                'criterios' => [
                    ['orden' => 1,  'peso' => 0.175, 'nombre' => 'Área general de basura limpia y separada del área de alimentos. Contenedores limpios e identificados, en buen estado con tapa y bolsa de plástico según el caso.'],
                    ['orden' => 2,  'peso' => 0.175, 'nombre' => 'Se aplica correctamente la separación de basura para el reciclaje de los diferentes tipos de materiales.'],
                    ['orden' => 3,  'peso' => 0.175, 'nombre' => 'En las áreas de procesos no hay evidencias de plagas o fauna nociva.'],
                    ['orden' => 4,  'peso' => 0.175, 'nombre' => 'Servicio profesional para el control de plagas con: licencia sanitaria, hojas de seguridad del plaguicida, programa de control de plagas mensual y anual, registros de los últimos 3 meses y contrato de servicio vigente.'],
                    ['orden' => 5,  'peso' => 0.15,  'nombre' => 'Los botes de basura se encuentran limpios, desinfectados y en buen estado. Se realiza limpieza y desinfección diaria al final de la jornada.'],
                    ['orden' => 6,  'peso' => 0.03,  'nombre' => 'Área exclusiva para el lavado y desinfección de botes de basura.'],
                    ['orden' => 7,  'peso' => 0.03,  'nombre' => 'Los botes de basura están identificados por tipo de desecho (orgánico/inorgánico), cuentan con bolsa de plástico y están tapados.'],
                    ['orden' => 8,  'peso' => 0.03,  'nombre' => 'Se evita la acumulación excesiva de basura en las áreas de manejo de alimentos.'],
                    ['orden' => 9,  'peso' => 0.03,  'nombre' => 'Se hace uso exclusivo de las bolsas para basura únicamente para desechos y no para alimentos, bebidas u otros usos.'],
                    ['orden' => 10, 'peso' => 0.03,  'nombre' => 'Existen dispositivos en buenas condiciones y localizados adecuadamente para el control de insectos voladores y roedores (cebos, trampas, etc.), fuera de áreas de preparación de alimentos.'],
                ],
            ],

            // ── 6. Personal ──────────────────────────────────────────────────
            [
                'categoria'       => 'Personal',
                'categoria_orden' => 6,
                'criterios' => [
                    ['orden' => 1,  'peso' => 0.233, 'nombre' => 'Manos limpias, uñas cortas hasta el ras de la yema de los dedos y sin esmalte ni decoración.'],
                    ['orden' => 2,  'peso' => 0.233, 'nombre' => 'El personal no utiliza celulares, joyas (reloj, pulseras, anillos, aretes, etc.) u otros objetos ornamentales en cara, orejas, cuello, manos ni brazos.'],
                    ['orden' => 3,  'peso' => 0.233, 'nombre' => 'El personal masculino no utiliza barba o bigote (excepto Gerentes, Mandiles y Mixólogo según política de la marca).'],
                    ['orden' => 4,  'peso' => 0.033, 'nombre' => 'El personal afectado con infecciones respiratorias, gastrointestinales o cutáneas no labora en el área de preparación y servicio de alimentos.'],
                    ['orden' => 5,  'peso' => 0.033, 'nombre' => 'El personal no come, masca chicle ni bebe en el área de preparación de alimentos; hace uso exclusivo del comedor.'],
                    ['orden' => 6,  'peso' => 0.033, 'nombre' => 'El personal cuenta con su tarjeta de salud vigente o documento equivalente.'],
                    ['orden' => 7,  'peso' => 0.033, 'nombre' => 'El personal omite fumar, escupir y mascar chicle en áreas de cocina, servicio, almacenes, baños y lockers.'],
                    ['orden' => 8,  'peso' => 0.033, 'nombre' => 'El personal femenino de cocina no usa maquillaje. El personal de frente al invitado y meseras usa maquillaje discreto.'],
                    ['orden' => 9,  'peso' => 0.033, 'nombre' => 'Se cuenta con evidencias de capacitación (listas de asistencia, fotografías y constancias).'],
                    ['orden' => 10, 'peso' => 0.033, 'nombre' => 'El personal cuenta con capacitación de al menos una vez al año en buenas prácticas de higiene o manejo higiénico de alimentos.'],
                    ['orden' => 11, 'peso' => 0.033, 'nombre' => 'El personal demuestra competencia en el manejo higiénico de alimentos.'],
                    ['orden' => 12, 'peso' => 0.033, 'nombre' => 'El personal se presenta a trabajar con el uniforme limpio y completo (uso de malla cuando se requiere entrar a cocina).'],
                ],
            ],

        ];

        $now  = now();
        $rows = [];

        foreach ($secciones as $sec) {
            foreach ($sec['criterios'] as $c) {
                $rows[] = [
                    'tipo'            => 'calidad',
                    'categoria'       => $sec['categoria'],
                    'categoria_orden' => $sec['categoria_orden'],
                    'nombre'          => $c['nombre'],
                    'peso'            => $c['peso'],
                    'orden'           => $c['orden'],
                    'activo'          => true,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        DB::connection('compras')->table('auditoria_criterios')->insert($rows);

        $total = count($rows);
        $this->command->info("Calidad: {$total} criterios insertados en 6 secciones.");
    }
}
