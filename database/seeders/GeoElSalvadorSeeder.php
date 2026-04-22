<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GeoElSalvadorSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('pgsql')->statement('TRUNCATE geo_municipios, geo_distritos, geo_departamentos RESTART IDENTITY CASCADE');

        $data = $this->geoData();

        foreach ($data as $deptoData) {
            $deptoId = DB::connection('pgsql')->table('geo_departamentos')->insertGetId([
                'codigo' => $deptoData['codigo'],
                'nombre' => $deptoData['nombre'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($deptoData['distritos'] as $distritoData) {
                $distId = DB::connection('pgsql')->table('geo_distritos')->insertGetId([
                    'departamento_id' => $deptoId,
                    'codigo'          => $distritoData['codigo'],
                    'nombre'          => $distritoData['nombre'],
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                foreach ($distritoData['municipios'] as $municipio) {
                    DB::connection('pgsql')->table('geo_municipios')->insert([
                        'departamento_id' => $deptoId,
                        'distrito_id'     => $distId,
                        'nombre'          => $municipio,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }
            }
        }
    }

    private function geoData(): array
    {
        return [
            // ─── 01. AHUACHAPÁN ───────────────────────────────────────────
            [
                'codigo' => '01', 'nombre' => 'Ahuachapán',
                'distritos' => [
                    ['codigo' => '0101', 'nombre' => 'Ahuachapán Norte', 'municipios' => [
                        'Atiquizaya', 'El Refugio', 'San Lorenzo', 'Turín',
                    ]],
                    ['codigo' => '0102', 'nombre' => 'Ahuachapán Centro', 'municipios' => [
                        'Ahuachapán',
                    ]],
                    ['codigo' => '0103', 'nombre' => 'Ahuachapán Sur', 'municipios' => [
                        'Apaneca', 'Concepción de Ataco', 'Tacuba', 'Jujutla', 'San Pedro Puxtla', 'Guaymango',
                    ]],
                ],
            ],
            // ─── 02. SONSONATE ────────────────────────────────────────────
            [
                'codigo' => '02', 'nombre' => 'Sonsonate',
                'distritos' => [
                    ['codigo' => '0201', 'nombre' => 'Sonsonate Norte', 'municipios' => [
                        'Juayúa', 'Nahuizalco', 'Salcoatitán', 'Santa Catarina Masahuat',
                    ]],
                    ['codigo' => '0202', 'nombre' => 'Sonsonate Centro', 'municipios' => [
                        'Sonsonate',
                    ]],
                    ['codigo' => '0203', 'nombre' => 'Sonsonate Este', 'municipios' => [
                        'Izalco', 'Armenia', 'Caluco', 'San Julián', 'Cuisnahuat', 'Santa Isabel Ishuatán',
                    ]],
                ],
            ],
            // ─── 03. SANTA ANA ────────────────────────────────────────────
            [
                'codigo' => '03', 'nombre' => 'Santa Ana',
                'distritos' => [
                    ['codigo' => '0301', 'nombre' => 'Santa Ana Norte', 'municipios' => [
                        'Metapán', 'Masahuat', 'Santa Rosa Guachipilín', 'Texistepeque',
                    ]],
                    ['codigo' => '0302', 'nombre' => 'Santa Ana Centro', 'municipios' => [
                        'Santa Ana',
                    ]],
                    ['codigo' => '0303', 'nombre' => 'Santa Ana Este', 'municipios' => [
                        'Coatepeque', 'El Congo',
                    ]],
                ],
            ],
            // ─── 04. CHALATENANGO ─────────────────────────────────────────
            [
                'codigo' => '04', 'nombre' => 'Chalatenango',
                'distritos' => [
                    ['codigo' => '0401', 'nombre' => 'Chalatenango Norte', 'municipios' => [
                        'La Palma', 'San Ignacio', 'Citalá',
                    ]],
                    ['codigo' => '0402', 'nombre' => 'Chalatenango Centro', 'municipios' => [
                        'Chalatenango', 'Nueva Concepción', 'San Rafael',
                    ]],
                    ['codigo' => '0403', 'nombre' => 'Chalatenango Sur', 'municipios' => [
                        'Dulce Nombre de María', 'El Paraíso', 'La Reina', 'Comalapa', 'Ojos de Agua',
                    ]],
                ],
            ],
            // ─── 05. LA LIBERTAD ──────────────────────────────────────────
            [
                'codigo' => '05', 'nombre' => 'La Libertad',
                'distritos' => [
                    ['codigo' => '0501', 'nombre' => 'La Libertad Norte', 'municipios' => [
                        'Quezaltepeque', 'San Matías', 'San Pablo Tacachico',
                    ]],
                    ['codigo' => '0502', 'nombre' => 'La Libertad Centro', 'municipios' => [
                        'San Juan Opico', 'Ciudad Arce',
                    ]],
                    ['codigo' => '0503', 'nombre' => 'La Libertad Oeste', 'municipios' => [
                        'Colón', 'Jayaque', 'Sacacoyo', 'Talnique', 'Tepecoyo',
                    ]],
                    ['codigo' => '0504', 'nombre' => 'La Libertad Este', 'municipios' => [
                        'La Libertad', 'Tamanique', 'Chiltiupán', 'Teotepeque',
                    ]],
                    ['codigo' => '0505', 'nombre' => 'La Libertad Costa', 'municipios' => [
                        'Comasagua',
                    ]],
                ],
            ],
            // ─── 06. SAN SALVADOR ─────────────────────────────────────────
            [
                'codigo' => '06', 'nombre' => 'San Salvador',
                'distritos' => [
                    ['codigo' => '0601', 'nombre' => 'San Salvador Norte', 'municipios' => [
                        'Aguilares', 'Apopa', 'El Paisnal', 'Guazapa', 'Nejapa',
                    ]],
                    ['codigo' => '0602', 'nombre' => 'San Salvador Centro', 'municipios' => [
                        'Ayutuxtepeque', 'Ciudad Delgado', 'Cuscatancingo', 'Mejicanos', 'San Salvador',
                    ]],
                    ['codigo' => '0603', 'nombre' => 'San Salvador Sur', 'municipios' => [
                        'Panchimalco', 'Rosario de Mora', 'San Marcos', 'Santiago Texacuangos', 'Santo Tomás',
                    ]],
                    ['codigo' => '0604', 'nombre' => 'San Salvador Este', 'municipios' => [
                        'Ilopango', 'San Martín', 'Soyapango', 'Tonacatepeque',
                    ]],
                ],
            ],
            // ─── 07. CUSCATLÁN ────────────────────────────────────────────
            [
                'codigo' => '07', 'nombre' => 'Cuscatlán',
                'distritos' => [
                    ['codigo' => '0701', 'nombre' => 'Cuscatlán Norte', 'municipios' => [
                        'Suchitoto', 'San José Guayabal', 'Oratorio de Concepción',
                    ]],
                    ['codigo' => '0702', 'nombre' => 'Cuscatlán Sur', 'municipios' => [
                        'Cojutepeque', 'San Rafael Cedros', 'El Carmen', 'Monte San Juan',
                        'Santa Cruz Michapa', 'San Cristóbal',
                    ]],
                ],
            ],
            // ─── 08. LA PAZ ───────────────────────────────────────────────
            [
                'codigo' => '08', 'nombre' => 'La Paz',
                'distritos' => [
                    ['codigo' => '0801', 'nombre' => 'La Paz Oeste', 'municipios' => [
                        'Zacatecoluca',
                    ]],
                    ['codigo' => '0802', 'nombre' => 'La Paz Centro', 'municipios' => [
                        'El Rosario', 'San Pedro Masahuat', 'San Juan Nonualco', 'San Rafael Obrajuelo',
                    ]],
                    ['codigo' => '0803', 'nombre' => 'La Paz Este', 'municipios' => [
                        'San Luis Talpa', 'San Juan Talpa', 'Olocuilta', 'San Pedro Nonualco',
                        'Santiago Nonualco', 'San Luis La Herradura',
                    ]],
                ],
            ],
            // ─── 09. CABAÑAS ──────────────────────────────────────────────
            [
                'codigo' => '09', 'nombre' => 'Cabañas',
                'distritos' => [
                    ['codigo' => '0901', 'nombre' => 'Cabañas Oeste', 'municipios' => [
                        'Ilobasco', 'Tejutepeque',
                    ]],
                    ['codigo' => '0902', 'nombre' => 'Cabañas Este', 'municipios' => [
                        'Sensuntepeque', 'Victoria', 'Dolores', 'Guacotecti', 'San Isidro', 'Jutiapa',
                    ]],
                ],
            ],
            // ─── 10. SAN VICENTE ──────────────────────────────────────────
            [
                'codigo' => '10', 'nombre' => 'San Vicente',
                'distritos' => [
                    ['codigo' => '1001', 'nombre' => 'San Vicente Norte', 'municipios' => [
                        'Apastepeque', 'Santa Clara', 'San Ildefonso', 'San Esteban Catarina',
                    ]],
                    ['codigo' => '1002', 'nombre' => 'San Vicente Sur', 'municipios' => [
                        'San Vicente', 'Guadalupe', 'Tepetitán', 'Verapaz', 'Tecoluca',
                    ]],
                ],
            ],
            // ─── 11. USULUTÁN ─────────────────────────────────────────────
            [
                'codigo' => '11', 'nombre' => 'Usulután',
                'distritos' => [
                    ['codigo' => '1101', 'nombre' => 'Usulután Norte', 'municipios' => [
                        'Santiago de María', 'Alegría', 'Mercedes Umaña',
                    ]],
                    ['codigo' => '1102', 'nombre' => 'Usulután Este', 'municipios' => [
                        'Usulután', 'Jiquilisco', 'Puerto El Triunfo',
                    ]],
                    ['codigo' => '1103', 'nombre' => 'Usulután Oeste', 'municipios' => [
                        'Santa Elena', 'Ereguayquín', 'Concepción Batres', 'Ozatlán', 'San Dionisio',
                    ]],
                ],
            ],
            // ─── 12. SAN MIGUEL ───────────────────────────────────────────
            [
                'codigo' => '12', 'nombre' => 'San Miguel',
                'distritos' => [
                    ['codigo' => '1201', 'nombre' => 'San Miguel Norte', 'municipios' => [
                        'Ciudad Barrios', 'Sesori', 'Nuevo Edén de San Juan', 'San Gerardo',
                    ]],
                    ['codigo' => '1202', 'nombre' => 'San Miguel Centro', 'municipios' => [
                        'San Miguel',
                    ]],
                    ['codigo' => '1203', 'nombre' => 'San Miguel Oeste', 'municipios' => [
                        'Chinameca', 'Nueva Guadalupe',
                    ]],
                    ['codigo' => '1204', 'nombre' => 'San Miguel Este', 'municipios' => [
                        'Chirilagua', 'San Jorge', 'Uluazapa', 'Moncagua', 'Quelepa',
                    ]],
                ],
            ],
            // ─── 13. MORAZÁN ──────────────────────────────────────────────
            [
                'codigo' => '13', 'nombre' => 'Morazán',
                'distritos' => [
                    ['codigo' => '1301', 'nombre' => 'Morazán Norte', 'municipios' => [
                        'Perquín', 'Arambala', 'Joateca', 'Cacaopera', 'San Fernando', 'Torola',
                    ]],
                    ['codigo' => '1302', 'nombre' => 'Morazán Sur', 'municipios' => [
                        'San Francisco Gotera', 'Sensembra', 'Yamabal', 'El Divisadero', 'Jocoro', 'Lolotiquillo',
                    ]],
                ],
            ],
            // ─── 14. LA UNIÓN ─────────────────────────────────────────────
            [
                'codigo' => '14', 'nombre' => 'La Unión',
                'distritos' => [
                    ['codigo' => '1401', 'nombre' => 'La Unión Norte', 'municipios' => [
                        'Anamorós', 'Nueva Esparta', 'Polorós', 'Concepción de Oriente',
                    ]],
                    ['codigo' => '1402', 'nombre' => 'La Unión Sur', 'municipios' => [
                        'La Unión', 'Intipucá', 'San Alejo', 'El Carmen', 'Yayantique', 'Bolívar', 'Meanguera del Golfo',
                    ]],
                ],
            ],
        ];
    }
}
