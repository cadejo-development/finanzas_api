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
                        'Ahuachapán', 'Atiquizaya', 'Turín', 'El Refugio', 'San Lorenzo', 'Guaymango', 'Tacuba',
                    ]],
                    ['codigo' => '0102', 'nombre' => 'Apaneca', 'municipios' => [
                        'Apaneca', 'Concepción de Ataco',
                    ]],
                    ['codigo' => '0103', 'nombre' => 'Jujutla', 'municipios' => [
                        'Jujutla', 'San Francisco Menéndez', 'San Pedro Puxtla',
                    ]],
                ],
            ],
            // ─── 02. SONSONATE ────────────────────────────────────────────
            [
                'codigo' => '02', 'nombre' => 'Sonsonate',
                'distritos' => [
                    ['codigo' => '0201', 'nombre' => 'Sonsonate', 'municipios' => [
                        'Sonsonate', 'Sonzacate', 'San Antonio del Monte', 'Armenia', 'Caluco', 'San Julián',
                    ]],
                    ['codigo' => '0202', 'nombre' => 'Nahuizalco', 'municipios' => [
                        'Nahuizalco', 'Izalco', 'Nahulingo', 'Cuisnahuat', 'Santa Catarina Masahuat',
                        'Santa Isabel Ishuatán', 'Santo Domingo de Guzmán',
                    ]],
                    ['codigo' => '0203', 'nombre' => 'Acajutla', 'municipios' => [
                        'Acajutla', 'Juayúa', 'San Marcos',
                    ]],
                ],
            ],
            // ─── 03. SANTA ANA ────────────────────────────────────────────
            [
                'codigo' => '03', 'nombre' => 'Santa Ana',
                'distritos' => [
                    ['codigo' => '0301', 'nombre' => 'Santa Ana', 'municipios' => [
                        'Santa Ana', 'El Congo', 'El Porvenir', 'Coatepeque', 'San Antonio Pajonal', 'San Sebastián Salitrillo',
                    ]],
                    ['codigo' => '0302', 'nombre' => 'Chalchuapa', 'municipios' => [
                        'Chalchuapa', 'Texistepeque',
                    ]],
                    ['codigo' => '0303', 'nombre' => 'Metapán', 'municipios' => [
                        'Metapán', 'Candelaria de la Frontera', 'Masahuat', 'Santa Rosa Guachipilín', 'Santiago de la Frontera',
                    ]],
                ],
            ],
            // ─── 04. CHALATENANGO ─────────────────────────────────────────
            [
                'codigo' => '04', 'nombre' => 'Chalatenango',
                'distritos' => [
                    ['codigo' => '0401', 'nombre' => 'Chalatenango', 'municipios' => [
                        'Chalatenango', 'Agua Caliente', 'Azacualpa', 'Cancasque', 'El Carrizal', 'La Laguna',
                        'Las Vueltas', 'Nombre de Dios', 'Norteamérica', 'Ojos de Agua', 'Potonico',
                        'San Antonio de la Cruz', 'San Antonio Los Ranchos', 'San Fernando', 'San Isidro Labrador',
                        'San José Las Flores', 'San Juan Concepción', 'San Luis del Carmen', 'San Rafael',
                    ]],
                    ['codigo' => '0402', 'nombre' => 'La Palma', 'municipios' => [
                        'La Palma', 'San Ignacio', 'Citalá', 'Arcatao', 'Nueva Trinidad',
                    ]],
                    ['codigo' => '0403', 'nombre' => 'Tejutla', 'municipios' => [
                        'Tejutla', 'San Francisco Morazán', 'Dulce Nombre de María', 'La Reina', 'San Miguel de Mercedes',
                    ]],
                    ['codigo' => '0404', 'nombre' => 'Nueva Concepción', 'municipios' => [
                        'Nueva Concepción', 'Comalapa', 'Concepción Quezaltepeque', 'El Paraíso',
                    ]],
                ],
            ],
            // ─── 05. LA LIBERTAD ──────────────────────────────────────────
            [
                'codigo' => '05', 'nombre' => 'La Libertad',
                'distritos' => [
                    ['codigo' => '0501', 'nombre' => 'La Libertad Sur', 'municipios' => [
                        'Nueva San Salvador (Santa Tecla)', 'Antiguo Cuscatlán', 'Nuevo Cuscatlán', 'San José Villanueva',
                        'Zaragoza', 'Huizúcar', 'Rosario de Mora', 'Sacacoyo', 'Jayaque',
                    ]],
                    ['codigo' => '0502', 'nombre' => 'La Libertad Norte', 'municipios' => [
                        'San Juan Opico', 'Quezaltepeque', 'Ciudad Arce', 'San Matías',
                        'San Pablo Tacachico', 'Tepecoyo', 'Talnique',
                    ]],
                    ['codigo' => '0503', 'nombre' => 'La Libertad Costa', 'municipios' => [
                        'La Libertad', 'Comasagua', 'Chiltiupán', 'Jicalapa', 'Teotepeque', 'Tamanique',
                    ]],
                ],
            ],
            // ─── 06. SAN SALVADOR ─────────────────────────────────────────
            [
                'codigo' => '06', 'nombre' => 'San Salvador',
                'distritos' => [
                    ['codigo' => '0601', 'nombre' => 'San Salvador Norte', 'municipios' => [
                        'Aguilares', 'Apopa', 'El Paisnal', 'Guazapa', 'Nejapa', 'Tonacatepeque', 'San Martín',
                    ]],
                    ['codigo' => '0602', 'nombre' => 'San Salvador Centro', 'municipios' => [
                        'San Salvador', 'Cuscatancingo', 'Ciudad Delgado', 'Mejicanos',
                        'Ilopango', 'Ayutuxtepeque', 'Soyapango',
                    ]],
                    ['codigo' => '0603', 'nombre' => 'San Salvador Sur', 'municipios' => [
                        'Panchimalco', 'San Marcos', 'Santiago Texacuangos', 'Santo Tomás', 'Rosario de Mora',
                    ]],
                ],
            ],
            // ─── 07. CUSCATLÁN ────────────────────────────────────────────
            [
                'codigo' => '07', 'nombre' => 'Cuscatlán',
                'distritos' => [
                    ['codigo' => '0701', 'nombre' => 'Cojutepeque', 'municipios' => [
                        'Cojutepeque', 'Oratorio de Concepción', 'San Bartolomé Perulapía', 'San Cristóbal',
                        'San José Guayabal', 'Santa Cruz Analquito', 'Santa Cruz Michapa', 'San Ramón', 'Candelaria',
                    ]],
                    ['codigo' => '0702', 'nombre' => 'Suchitoto', 'municipios' => [
                        'Suchitoto', 'San Pedro Perulapán', 'Monte San Juan', 'El Carmen',
                        'San Isidro', 'San Dionisio', 'San Miguel Tepezontes',
                    ]],
                ],
            ],
            // ─── 08. LA PAZ ───────────────────────────────────────────────
            [
                'codigo' => '08', 'nombre' => 'La Paz',
                'distritos' => [
                    ['codigo' => '0801', 'nombre' => 'Zacatecoluca', 'municipios' => [
                        'Zacatecoluca', 'Cuyultitán', 'El Rosario', 'Olocuilta', 'Paraíso de Osorio',
                        'San Antonio Masahuat', 'San Emigdio', 'San Francisco Chinameca', 'San Juan Tepezontes',
                        'San Miguel Tepezontes', 'Santiago Nonualco',
                    ]],
                    ['codigo' => '0802', 'nombre' => 'San Luis Talpa', 'municipios' => [
                        'San Luis Talpa', 'San Juan Talpa', 'San Luis La Herradura', 'Tapalhuaca', 'Jerusalén',
                    ]],
                    ['codigo' => '0803', 'nombre' => 'San Juan Nonualco', 'municipios' => [
                        'San Juan Nonualco', 'Mercedes La Ceiba', 'San Pedro Masahuat',
                        'San Pedro Nonualco', 'San Rafael Obrajuelo', 'Santa María Ostuma',
                    ]],
                ],
            ],
            // ─── 09. CABAÑAS ──────────────────────────────────────────────
            [
                'codigo' => '09', 'nombre' => 'Cabañas',
                'distritos' => [
                    ['codigo' => '0901', 'nombre' => 'Sensuntepeque', 'municipios' => [
                        'Sensuntepeque', 'Cinquera', 'Dolores', 'Guacotecti', 'Jutiapa',
                    ]],
                    ['codigo' => '0902', 'nombre' => 'Ilobasco', 'municipios' => [
                        'Ilobasco', 'San Isidro', 'Tejutepeque', 'Villa Victoria',
                    ]],
                ],
            ],
            // ─── 10. SAN VICENTE ──────────────────────────────────────────
            [
                'codigo' => '10', 'nombre' => 'San Vicente',
                'distritos' => [
                    ['codigo' => '1001', 'nombre' => 'San Vicente', 'municipios' => [
                        'San Vicente', 'Apastepeque', 'Guadalupe', 'San Cayetano Istepeque',
                        'San Esteban Catarina', 'San Ildefonso', 'San Lorenzo', 'San Sebastián',
                    ]],
                    ['codigo' => '1002', 'nombre' => 'Tecoluca', 'municipios' => [
                        'Tecoluca', 'Santa Clara', 'Santo Domingo', 'Tepetitán', 'Verapaz',
                    ]],
                ],
            ],
            // ─── 11. USULUTÁN ─────────────────────────────────────────────
            [
                'codigo' => '11', 'nombre' => 'Usulután',
                'distritos' => [
                    ['codigo' => '1101', 'nombre' => 'Usulután', 'municipios' => [
                        'Usulután', 'Berlín', 'California', 'El Triunfo', 'Estanzuelas',
                        'Jucuapa', 'Mercedes Umaña', 'Nueva Granada', 'Santiago de María',
                    ]],
                    ['codigo' => '1102', 'nombre' => 'Jiquilisco', 'municipios' => [
                        'Jiquilisco', 'Concepción Batres', 'Ereguayquín', 'Ozatlán',
                        'Puerto El Triunfo', 'San Dionisio', 'San Francisco Javier', 'Tecapán',
                    ]],
                    ['codigo' => '1103', 'nombre' => 'Jucuarán', 'municipios' => [
                        'Jucuarán', 'Alegría', 'San Agustín', 'San Buenaventura', 'Santa Elena', 'Santa María',
                    ]],
                ],
            ],
            // ─── 12. SAN MIGUEL ───────────────────────────────────────────
            [
                'codigo' => '12', 'nombre' => 'San Miguel',
                'distritos' => [
                    ['codigo' => '1201', 'nombre' => 'San Miguel', 'municipios' => [
                        'San Miguel', 'Chinameca', 'El Tránsito', 'Moncagua', 'Nueva Guadalupe',
                        'San Jorge', 'San Rafael Oriente', 'Sesori', 'Uluazapa',
                    ]],
                    ['codigo' => '1202', 'nombre' => 'San Antonio', 'municipios' => [
                        'San Antonio', 'Carolina', 'Ciudad Barrios', 'San Luis de la Reina', 'San Gerardo',
                    ]],
                    ['codigo' => '1203', 'nombre' => 'Chirilagua', 'municipios' => [
                        'Chirilagua', 'Chapeltique', 'Comacarán', 'Lolotique',
                        'Nuevo Edén de San Juan', 'Quelepa',
                    ]],
                ],
            ],
            // ─── 13. MORAZÁN ──────────────────────────────────────────────
            [
                'codigo' => '13', 'nombre' => 'Morazán',
                'distritos' => [
                    ['codigo' => '1301', 'nombre' => 'San Francisco Gotera', 'municipios' => [
                        'San Francisco Gotera', 'Chilanga', 'Delicias de Concepción', 'El Divisadero',
                        'Guatajiagua', 'Jocoro', 'Lolotiquillo', 'Osicala', 'San Carlos', 'San Simón', 'Sensembra',
                    ]],
                    ['codigo' => '1302', 'nombre' => 'Corinto', 'municipios' => [
                        'Corinto', 'Cacaopera', 'Gualococti', 'Guatajiagua', 'Jocoaitique', 'San Isidro',
                    ]],
                    ['codigo' => '1303', 'nombre' => 'Sociedad', 'municipios' => [
                        'Sociedad', 'El Rosario', 'Meanguera', 'San Fernando', 'Torola', 'Yamabal', 'Yoloaiquín',
                    ]],
                    ['codigo' => '1304', 'nombre' => 'Perquín', 'municipios' => [
                        'Perquín', 'Arambala', 'Joateca',
                    ]],
                ],
            ],
            // ─── 14. LA UNIÓN ─────────────────────────────────────────────
            [
                'codigo' => '14', 'nombre' => 'La Unión',
                'distritos' => [
                    ['codigo' => '1401', 'nombre' => 'La Unión', 'municipios' => [
                        'La Unión', 'Conchagua', 'El Carmen', 'Intipucá',
                        'Meanguera del Golfo', 'San Alejo', 'Yayantique', 'Yucuaiquín',
                    ]],
                    ['codigo' => '1402', 'nombre' => 'Santa Rosa de Lima', 'municipios' => [
                        'Santa Rosa de Lima', 'Bolívar', 'El Sauce', 'Lislique', 'Nueva Esparta', 'Pasaquina',
                    ]],
                    ['codigo' => '1403', 'nombre' => 'San José', 'municipios' => [
                        'San José', 'Anamoros', 'Concepción de Oriente', 'Polorós',
                    ]],
                ],
            ],
        ];
    }
}
