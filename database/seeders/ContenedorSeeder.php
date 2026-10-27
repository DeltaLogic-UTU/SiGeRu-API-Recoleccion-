<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContenedorSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tipo_residuo')->updateOrInsert(
            ['tipo' => 'general'],
            ['descripcion' => 'Residuos urbanos generales']
        );

        DB::table('tipo_residuo')->updateOrInsert(
            ['tipo' => 'reciclaje'],
            ['descripcion' => 'Materiales reciclables']
        );

        DB::table('tipo_residuo')->updateOrInsert(
            ['tipo' => 'mixto'],
            ['descripcion' => 'Residuos generales y reciclables']
        );

        $distribuciones = [
            'B_01' => [
                'verde' => 7,
                'amarillo' => 2,
                'rojo' => 1
            ],
            'B_02' => [
                'verde' => 4,
                'amarillo' => 4,
                'rojo' => 2
            ],
            'B_03' => [
                'verde' => 2,
                'amarillo' => 3,
                'rojo' => 5
            ],
            'B_04' => [
                'verde' => 8,
                'amarillo' => 2,
                'rojo' => 0
            ],
            'CH_102' => [
                'verde' => 5,
                'amarillo' => 3,
                'rojo' => 2
            ],
            'CH_105' => [
                'verde' => 3,
                'amarillo' => 3,
                'rojo' => 4
            ],
            'CH_108' => [
                'verde' => 6,
                'amarillo' => 3,
                'rojo' => 1
            ],
            'CH_109' => [
                'verde' => 3,
                'amarillo' => 5,
                'rojo' => 2
            ],
            'CH_114' => [
                'verde' => 2,
                'amarillo' => 4,
                'rojo' => 4
            ]
        ];

        $rutas = DB::table('rutas')
            ->orderBy('circuito')
            ->get();

        $tipos = [
            'general',
            'reciclaje',
            'mixto'
        ];

        $modelos = [
            'Ecopunto',
            'EASY',
            'Metálico'
        ];

        $capacidades = [
            3,
            2.25,
            3
        ];

        // Coordenadas centrales de referencia en Montevideo para cada circuito del dump
        $centrosCircuito = [
            'A_101' => [-34.8620, -56.2180],
            'A_102' => [-34.8540, -56.2250],
            'A_103' => [-34.8680, -56.2320],
            'B_01'  => [-34.9045, -56.1910],
            'B_02'  => [-34.9020, -56.1780],
            'B_03'  => [-34.9070, -56.1710],
            'B_04'  => [-34.9075, -56.1830],
            'B_05'  => [-34.8920, -56.1740],
            'B_06'  => [-34.8950, -56.1660],
            'B_7'   => [-34.8950, -56.1880],
            'C_102' => [-34.8720, -56.1920],
            'CH_102'=> [-34.9080, -56.1530],
            'CH_105'=> [-34.9150, -56.1580],
            'CH_108'=> [-34.8980, -56.1360],
            'CH_109'=> [-34.8960, -56.1550],
            'CH_114'=> [-34.8870, -56.1530],
            'CH_115'=> [-34.9010, -56.1460],
            'D_101' => [-34.8580, -56.1700],
            'D_102' => [-34.8720, -56.1420],
            'D_103' => [-34.8550, -56.1520],
            'E_102' => [-34.8850, -56.1180],
            'E_103' => [-34.8890, -56.1080],
            'E_106' => [-34.8780, -56.0820],
            'F_101' => [-34.8580, -56.1280],
            'F_102' => [-34.8520, -56.1150],
            'F_103' => [-34.8350, -56.1350],
            'G_101' => [-34.8380, -56.2120],
            'G_102' => [-34.8280, -56.1980],
            'G_103' => [-34.8080, -56.2220],
        ];

        // Días variados para los 10 contenedores de cada circuito (muestra los 5 colores: verde, amarillo, naranja, rojo y negro)
        $diasEjemplo = [1, 2, 2, 3, 3, 4, 4, 5, 6, 7];

        foreach ($rutas as $ruta) {
            $centro = $centrosCircuito[$ruta->circuito] ?? [-34.8950, -56.1650];

            foreach ($diasEjemplo as $indice => $diasSinRecoleccion) {
                $numero =
                    $indice + 1;

                // Ubicamos los 10 contenedores del circuito en una cuadrícula prolija de 2 filas x 5 columnas
                $fila = intdiv($indice, 5);
                $columna = ($indice % 5) - 2;
                $latitud = round($centro[0] + ($fila * 0.0018), 6);
                $longitud = round($centro[1] + ($columna * 0.0022), 6);

                $codigoCircuito =
                    str_replace(
                        '_',
                        '',
                        $ruta->circuito
                    );

                $codigo =
                    'CONT-' .
                    $codigoCircuito .
                    '-' .
                    str_pad(
                        $numero,
                        2,
                        '0',
                        STR_PAD_LEFT
                    );

                $existente = DB::table('contenedores')
                    ->where('codigo', $codigo)
                    ->first();

                // Si el contenedor ya existe del dump SQL, actualizamos sus coordenadas y días de ejemplo para verlo en el mapa
                if ($existente) {
                    if ($existente->id_ubicacion) {
                        DB::table('ubicacion')
                            ->where('id_ubicacion', $existente->id_ubicacion)
                            ->update([
                                'latitud' => $latitud,
                                'longitud' => $longitud
                            ]);
                    }

                    DB::table('contenedores')
                        ->where('id_contenedor', $existente->id_contenedor)
                        ->update([
                            'dias_sin_recoleccion' => $diasSinRecoleccion
                        ]);

                    continue;
                }

                $idUbicacion = DB::table('ubicacion')
                    ->insertGetId([
                        'calle' =>
                            'Calle ' .
                            $numero,
                        'esquina_1' =>
                            'Esquina ' .
                            $numero,
                        'esquina_2' =>
                            null,
                        'municipio' =>
                            $ruta->municipio,
                        'barrio' =>
                            $ruta->zona
                            ?: 'Zona ' .
                            $ruta->municipio,
                        'descripcion' =>
                            'Ubicación del contenedor ' .
                            $codigo,
                        'latitud' =>
                            $latitud,
                        'longitud' =>
                            $longitud
                    ]);

                if ($diasSinRecoleccion >= 6) {
                    $estado =
                        $numero % 2 === 0
                            ? 'roto'
                            : 'desbordado';
                } else {
                    $estado =
                        'funcional';
                }

                $idContenedor = DB::table('contenedores')
                    ->insertGetId([
                        'codigo' =>
                            $codigo,
                        'modelo' =>
                            $modelos[
                                $indice %
                                count($modelos)
                            ],
                        'tipo' =>
                            $tipos[
                                $indice %
                                count($tipos)
                            ],
                        'capacidad' =>
                            $capacidades[
                                $indice %
                                count($capacidades)
                            ],
                        'estado' =>
                            $estado,
                        'dias_sin_recoleccion' =>
                            $diasSinRecoleccion,
                        'id_ubicacion' =>
                            $idUbicacion,
                        'circuito' =>
                            $ruta->circuito,
                        'ultimo_registro' =>
                            Carbon::today()
                                ->subDays(
                                    $diasSinRecoleccion
                                )
                                ->toDateString(),
                        'activo' =>
                            1
                    ]);

                DB::table('historial_contenedores')
                    ->insert([
                        'id_contenedor' =>
                            $idContenedor,
                        'fecha' =>
                            now(),
                        'estado_anterior' =>
                            null,
                        'estado_nuevo' =>
                            $estado,
                        'id_ubicacion_anterior' =>
                            null,
                        'id_ubicacion_nueva' =>
                            $idUbicacion,
                        'motivo' =>
                            'Alta: Contenedor incorporado al circuito ' .
                            $ruta->circuito .
                            '.'
                    ]);
            }
        }
    }
}