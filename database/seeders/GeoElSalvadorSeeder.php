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
            // ─── 01. AHUACHAPÁN ─── 3 municipios / 12 distritos ──────────
            [
                'codigo' => '01', 'nombre' => 'Ahuachapán',
                'distritos' => [
                    ['codigo' => '0101', 'nombre' => 'Ahuachapán Norte', 'municipios' => [
                        'Atiquizaya', 'El Refugio', 'San Lorenzo', 'Turín',
                    ]],
                    ['codigo' => '0102', 'nombre' => 'Ahuachapán Centro', 'municipios' => [
                        'Ahuachapán', 'Apaneca', 'Concepción de Ataco', 'Tacuba',
                    ]],
                    ['codigo' => '0103', 'nombre' => 'Ahuachapán Sur', 'municipios' => [
                        'Guaymango', 'Jujutla', 'San Francisco Menéndez', 'San Pedro Puxtla',
                    ]],
                ],
            ],
            // ─── 02. SONSONATE ─── 4 municipios / 16 distritos ───────────
            [
                'codigo' => '02', 'nombre' => 'Sonsonate',
                'distritos' => [
                    ['codigo' => '0201', 'nombre' => 'Sonsonate Norte', 'municipios' => [
                        'Juayúa', 'Nahuizalco', 'Salcoatitán', 'Santa Catarina Masahuat',
                    ]],
                    ['codigo' => '0202', 'nombre' => 'Sonsonate Centro', 'municipios' => [
                        'Nahulingo', 'San Antonio del Monte', 'Sonsonate', 'Sonzacate', 'Santo Domingo de Guzmán',
                    ]],
                    ['codigo' => '0203', 'nombre' => 'Sonsonate Este', 'municipios' => [
                        'Armenia', 'Caluco', 'Cuisnahuat', 'Izalco', 'San Julián', 'Santa Isabel Ishuatán',
                    ]],
                    ['codigo' => '0204', 'nombre' => 'Sonsonate Oeste', 'municipios' => [
                        'Acajutla',
                    ]],
                ],
            ],
            // ─── 03. SANTA ANA ─── 4 municipios / 13 distritos ───────────
            [
                'codigo' => '03', 'nombre' => 'Santa Ana',
                'distritos' => [
                    ['codigo' => '0301', 'nombre' => 'Santa Ana Norte', 'municipios' => [
                        'Masahuat', 'Metapán', 'Santa Rosa Guachipilín', 'Texistepeque',
                    ]],
                    ['codigo' => '0302', 'nombre' => 'Santa Ana Centro', 'municipios' => [
                        'Santa Ana',
                    ]],
                    ['codigo' => '0303', 'nombre' => 'Santa Ana Este', 'municipios' => [
                        'Coatepeque', 'El Congo',
                    ]],
                    ['codigo' => '0304', 'nombre' => 'Santa Ana Oeste', 'municipios' => [
                        'Candelaria de la Frontera', 'Chalchuapa', 'El Porvenir',
                        'San Antonio Pajonal', 'San Sebastián Salitrillo', 'Santiago de la Frontera',
                    ]],
                ],
            ],
            // ─── 04. CHALATENANGO ─── 3 municipios / 33 distritos ────────
            [
                'codigo' => '04', 'nombre' => 'Chalatenango',
                'distritos' => [
                    ['codigo' => '0401', 'nombre' => 'Chalatenango Norte', 'municipios' => [
                        'Citalá', 'La Palma', 'San Ignacio',
                    ]],
                    ['codigo' => '0402', 'nombre' => 'Chalatenango Centro', 'municipios' => [
                        'Agua Caliente', 'Dulce Nombre de María', 'El Paraíso', 'La Reina',
                        'Nueva Concepción', 'San Fernando', 'San Francisco Morazán',
                        'San Rafael', 'Santa Rita', 'Tejutla',
                    ]],
                    ['codigo' => '0403', 'nombre' => 'Chalatenango Sur', 'municipios' => [
                        'Arcatao', 'Azacualpa', 'Cancasque', 'Chalatenango', 'Comalapa',
                        'Concepción Quezaltepeque', 'El Carrizal', 'La Laguna', 'Las Flores',
                        'Las Vueltas', 'Nombre de Jesús', 'Nueva Trinidad', 'Ojos de Agua',
                        'Potonico', 'San Antonio de la Cruz', 'San Antonio Los Ranchos',
                        'San Francisco Lempa', 'San Isidro Labrador', 'San Luis del Carmen',
                        'San Miguel de Mercedes',
                    ]],
                ],
            ],
            // ─── 05. LA LIBERTAD ─── 6 municipios / 22 distritos ─────────
            [
                'codigo' => '05', 'nombre' => 'La Libertad',
                'distritos' => [
                    ['codigo' => '0501', 'nombre' => 'La Libertad Norte', 'municipios' => [
                        'Quezaltepeque', 'San Matías', 'San Pablo Tacachico',
                    ]],
                    ['codigo' => '0502', 'nombre' => 'La Libertad Centro', 'municipios' => [
                        'Ciudad Arce', 'San Juan Opico',
                    ]],
                    ['codigo' => '0503', 'nombre' => 'La Libertad Oeste', 'municipios' => [
                        'Colón', 'Jayaque', 'Sacacoyo', 'Talnique', 'Tepecoyo',
                    ]],
                    ['codigo' => '0504', 'nombre' => 'La Libertad Este', 'municipios' => [
                        'Antiguo Cuscatlán', 'Huizúcar', 'Nuevo Cuscatlán', 'San José Villanueva', 'Zaragoza',
                    ]],
                    ['codigo' => '0505', 'nombre' => 'La Libertad Costa', 'municipios' => [
                        'Chiltiupán', 'Jicalapa', 'La Libertad', 'Tamanique', 'Teotepeque',
                    ]],
                    ['codigo' => '0506', 'nombre' => 'La Libertad Sur', 'municipios' => [
                        'Comasagua', 'Santa Tecla',
                    ]],
                ],
            ],
            // ─── 06. SAN SALVADOR ─── 5 municipios / 19 distritos ────────
            [
                'codigo' => '06', 'nombre' => 'San Salvador',
                'distritos' => [
                    ['codigo' => '0601', 'nombre' => 'San Salvador Norte', 'municipios' => [
                        'Aguilares', 'El Paisnal', 'Guazapa',
                    ]],
                    ['codigo' => '0602', 'nombre' => 'San Salvador Oeste', 'municipios' => [
                        'Apopa', 'Nejapa',
                    ]],
                    ['codigo' => '0603', 'nombre' => 'San Salvador Este', 'municipios' => [
                        'Ilopango', 'San Martín', 'Soyapango', 'Tonacatepeque',
                    ]],
                    ['codigo' => '0604', 'nombre' => 'San Salvador Centro', 'municipios' => [
                        'Ayutuxtepeque', 'Ciudad Delgado', 'Cuscatancingo', 'Mejicanos', 'San Salvador',
                    ]],
                    ['codigo' => '0605', 'nombre' => 'San Salvador Sur', 'municipios' => [
                        'Panchimalco', 'Rosario de Mora', 'San Marcos', 'Santiago Texacuangos', 'Santo Tomás',
                    ]],
                ],
            ],
            // ─── 07. CUSCATLÁN ─── 2 municipios / 16 distritos ───────────
            [
                'codigo' => '07', 'nombre' => 'Cuscatlán',
                'distritos' => [
                    ['codigo' => '0701', 'nombre' => 'Cuscatlán Norte', 'municipios' => [
                        'Oratorio de Concepción', 'San Bartolomé Perulapía', 'San José Guayabal',
                        'San Pedro Perulapán', 'Suchitoto',
                    ]],
                    ['codigo' => '0702', 'nombre' => 'Cuscatlán Sur', 'municipios' => [
                        'Candelaria', 'Cojutepeque', 'El Carmen', 'El Rosario', 'Monte San Juan',
                        'San Cristóbal', 'San Rafael Cedros', 'San Ramón',
                        'Santa Cruz Analquito', 'Santa Cruz Michapa', 'Tenancingo',
                    ]],
                ],
            ],
            // ─── 08. LA PAZ ─── 3 municipios / 22 distritos ──────────────
            [
                'codigo' => '08', 'nombre' => 'La Paz',
                'distritos' => [
                    ['codigo' => '0801', 'nombre' => 'La Paz Oeste', 'municipios' => [
                        'Cuyultitán', 'Olocuilta', 'San Francisco Chinameca', 'San Juan Talpa',
                        'San Luis Talpa', 'San Pedro Masahuat', 'Tapalhuaca',
                    ]],
                    ['codigo' => '0802', 'nombre' => 'La Paz Centro', 'municipios' => [
                        'El Rosario', 'Jerusalén', 'Mercedes La Ceiba', 'Paraíso de Osorio',
                        'San Antonio Masahuat', 'San Emigdio', 'San Juan Tepezontes',
                        'San Luis La Herradura', 'San Miguel Tepezontes', 'San Pedro Nonualco',
                        'Santa María Ostuma', 'Santiago Nonualco',
                    ]],
                    ['codigo' => '0803', 'nombre' => 'La Paz Este', 'municipios' => [
                        'San Juan Nonualco', 'San Rafael Obrajuelo', 'Zacatecoluca',
                    ]],
                ],
            ],
            // ─── 09. CABAÑAS ─── 2 municipios / 9 distritos ──────────────
            [
                'codigo' => '09', 'nombre' => 'Cabañas',
                'distritos' => [
                    ['codigo' => '0901', 'nombre' => 'Cabañas Este', 'municipios' => [
                        'Dolores', 'Guacotecti', 'San Isidro', 'Sensuntepeque', 'Victoria',
                    ]],
                    ['codigo' => '0902', 'nombre' => 'Cabañas Oeste', 'municipios' => [
                        'Cinquera', 'Ilobasco', 'Jutiapa', 'Tejutepeque',
                    ]],
                ],
            ],
            // ─── 10. SAN VICENTE ─── 2 municipios / 13 distritos ─────────
            [
                'codigo' => '10', 'nombre' => 'San Vicente',
                'distritos' => [
                    ['codigo' => '1001', 'nombre' => 'San Vicente Norte', 'municipios' => [
                        'Apastepeque', 'San Esteban Catarina', 'San Ildefonso', 'San Lorenzo',
                        'San Sebastián', 'Santa Clara', 'Santo Domingo',
                    ]],
                    ['codigo' => '1002', 'nombre' => 'San Vicente Sur', 'municipios' => [
                        'Guadalupe', 'San Cayetano Istepeque', 'San Vicente',
                        'Tecoluca', 'Tepetitán', 'Verapaz',
                    ]],
                ],
            ],
            // ─── 11. USULUTÁN ─── 3 municipios / 23 distritos ────────────
            [
                'codigo' => '11', 'nombre' => 'Usulután',
                'distritos' => [
                    ['codigo' => '1101', 'nombre' => 'Usulután Norte', 'municipios' => [
                        'Alegría', 'Berlín', 'El Triunfo', 'Estanzuelas', 'Jucuapa',
                        'Mercedes Umaña', 'Nueva Granada', 'San Buenaventura', 'Santiago de María',
                    ]],
                    ['codigo' => '1102', 'nombre' => 'Usulután Este', 'municipios' => [
                        'California', 'Concepción Batres', 'Ereguayquín', 'Jucuarán',
                        'Ozatlán', 'San Dionisio', 'Santa Elena', 'Santa María',
                        'Tecapán', 'Usulután',
                    ]],
                    ['codigo' => '1103', 'nombre' => 'Usulután Oeste', 'municipios' => [
                        'Jiquilisco', 'Puerto El Triunfo', 'San Agustín', 'San Francisco Javier',
                    ]],
                ],
            ],
            // ─── 12. SAN MIGUEL ─── 3 municipios / 20 distritos ──────────
            [
                'codigo' => '12', 'nombre' => 'San Miguel',
                'distritos' => [
                    ['codigo' => '1201', 'nombre' => 'San Miguel Norte', 'municipios' => [
                        'Carolina', 'Chapeltique', 'Ciudad Barrios', 'Nuevo Edén de San Juan',
                        'San Antonio', 'San Gerardo', 'San Luis de la Reina', 'Sesori',
                    ]],
                    ['codigo' => '1202', 'nombre' => 'San Miguel Centro', 'municipios' => [
                        'Chirilagua', 'Comacarán', 'Moncagua', 'Quelepa', 'San Miguel', 'Uluazapa',
                    ]],
                    ['codigo' => '1203', 'nombre' => 'San Miguel Oeste', 'municipios' => [
                        'Chinameca', 'El Tránsito', 'Lolotique', 'Nueva Guadalupe',
                        'San Jorge', 'San Rafael Oriente',
                    ]],
                ],
            ],
            // ─── 13. MORAZÁN ─── 2 municipios / 26 distritos ─────────────
            [
                'codigo' => '13', 'nombre' => 'Morazán',
                'distritos' => [
                    ['codigo' => '1301', 'nombre' => 'Morazán Norte', 'municipios' => [
                        'Arambala', 'Cacaopera', 'Corinto', 'El Rosario', 'Joateca',
                        'Jocoaitique', 'Meanguera', 'Perquín', 'San Fernando',
                        'San Isidro', 'Torola',
                    ]],
                    ['codigo' => '1302', 'nombre' => 'Morazán Sur', 'municipios' => [
                        'Chilanga', 'Delicias de Concepción', 'El Divisadero', 'Gualococti',
                        'Guatajiagua', 'Jocoro', 'Lolotiquillo', 'Osicala', 'San Carlos',
                        'San Francisco Gotera', 'San Simón', 'Sensembra', 'Sociedad',
                        'Yamabal', 'Yoloaiquín',
                    ]],
                ],
            ],
            // ─── 14. LA UNIÓN ─── 2 municipios / 18 distritos ────────────
            [
                'codigo' => '14', 'nombre' => 'La Unión',
                'distritos' => [
                    ['codigo' => '1401', 'nombre' => 'La Unión Norte', 'municipios' => [
                        'Anamorós', 'Bolívar', 'Concepción de Oriente', 'El Sauce',
                        'Lislique', 'Nueva Esparta', 'Pasaquina', 'Polorós',
                        'San José', 'Santa Rosa de Lima',
                    ]],
                    ['codigo' => '1402', 'nombre' => 'La Unión Sur', 'municipios' => [
                        'Conchagua', 'El Carmen', 'Intipucá', 'La Unión',
                        'Meanguera del Golfo', 'San Alejo', 'Yayantique', 'Yucuaiquín',
                    ]],
                ],
            ],
        ];
    }
}
