<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CircuitoController extends Controller
{
    // Lista todos los circuitos con sus coordenadas y cantidad de contenedores
    public function index()
    {
        $conteos = DB::table('contenedores')
            ->where('activo', 1)
            ->select('circuito', DB::raw('count(*) as total'))
            ->groupBy('circuito')
            ->pluck('total', 'circuito');

        $circuitos = DB::table('circuitos')
            ->orderBy('id_circuito')
            ->get()
            ->map(function ($item) use ($conteos) {
                $coordenadas = null;
                if (!empty($item->coordenadas)) {
                    $decodificado = json_decode($item->coordenadas, true);
                    if (is_array($decodificado)) {
                        $coordenadas = $decodificado;
                    }
                }

                return [
                    'id_circuito' => $item->id_circuito,
                    'municipio' => $item->municipio ?? null,
                    'zona' => $item->zona ?? null,
                    'descripcion' => $item->descripcion ?? null,
                    'estado' => $item->estado ?? 'activo',
                    'coordenadas' => $coordenadas,
                    'total_contenedores' => (int) ($conteos[$item->id_circuito] ?? 0)
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $circuitos
        ]);
    }

    // Guarda un nuevo circuito dibujado en el mapa o actualiza uno existente
    public function store(Request $request)
    {
        return $this->guardarCircuito($request, null);
    }

    // Actualiza un circuito existente seleccionado en el mapa
    public function update(Request $request, string $id)
    {
        return $this->guardarCircuito($request, $id);
    }

    private function guardarCircuito(Request $request, ?string $idOriginal = null)
    {
        $validated = $request->validate([
            'id_circuito' => 'required|string|max:30',
            'municipio' => 'nullable|string|max:100',
            'zona' => 'nullable|string|max:100',
            'descripcion' => 'nullable|string',
            'coordenadas' => 'required'
        ]);

        $codigo = strtoupper(trim($validated['id_circuito']));

        // Si no se envió municipio, lo deducimos del prefijo del código (ej: CH_101 -> CH)
        $municipio = !empty($validated['municipio']) ? strtoupper(trim($validated['municipio'])) : null;
        if (!$municipio && str_contains($codigo, '_')) {
            $municipio = explode('_', $codigo)[0];
        }
        if (!$municipio) {
            $municipio = 'CH';
        }

        // Zona y descripción son opcionales
        $zona = isset($validated['zona']) && trim((string) $validated['zona']) !== ''
            ? trim((string) $validated['zona'])
            : null;

        $descripcion = isset($validated['descripcion']) && trim((string) $validated['descripcion']) !== ''
            ? trim((string) $validated['descripcion'])
            : null;

        $coordenadas = is_array($validated['coordenadas'])
            ? $validated['coordenadas']
            : json_decode($validated['coordenadas'], true);

        if (!is_array($coordenadas) || count($coordenadas) < 3) {
            return response()->json([
                'success' => false,
                'message' => 'El circuito debe tener al menos 3 puntos para delimitar una zona.'
            ], 422);
        }

        DB::transaction(function () use ($codigo, $idOriginal, $municipio, $zona, $descripcion, $coordenadas) {
            // 1. Armamos los campos seguros para la tabla circuitos
            $datosCircuito = [
                'coordenadas' => json_encode($coordenadas)
            ];
            if (Schema::hasColumn('circuitos', 'municipio')) {
                $datosCircuito['municipio'] = $municipio;
            }
            if (Schema::hasColumn('circuitos', 'zona')) {
                $datosCircuito['zona'] = $zona;
            }
            if (Schema::hasColumn('circuitos', 'descripcion')) {
                $datosCircuito['descripcion'] = $descripcion;
            }
            if (Schema::hasColumn('circuitos', 'estado')) {
                $datosCircuito['estado'] = 'activo';
            }

            DB::table('circuitos')->updateOrInsert(
                ['id_circuito' => $codigo],
                $datosCircuito
            );

            // 2. Armamos los campos seguros para sincronizar la tabla rutas
            $datosRuta = [
                'municipio' => $municipio
            ];
            if (Schema::hasColumn('rutas', 'nombre')) {
                $datosRuta['nombre'] = $zona ?: "Circuito $codigo";
            }
            if (Schema::hasColumn('rutas', 'zona')) {
                $datosRuta['zona'] = $zona;
            }
            if (Schema::hasColumn('rutas', 'descripcion')) {
                $datosRuta['descripcion'] = $descripcion;
            }
            if (Schema::hasColumn('rutas', 'estado')) {
                $datosRuta['estado'] = 'Asignada';
            }

            DB::table('rutas')->updateOrInsert(
                ['circuito' => $codigo],
                $datosRuta
            );

            // Si se estaba editando un circuito y se le cambió el código, reasignamos sus contenedores y borramos el viejo
            if ($idOriginal && strtoupper($idOriginal) !== $codigo) {
                DB::table('contenedores')
                    ->where('circuito', $idOriginal)
                    ->update(['circuito' => $codigo]);

                $rutasViejas = DB::table('rutas')
                    ->where('circuito', $idOriginal)
                    ->pluck('id_ruta')
                    ->toArray();

                $rutaNuevaId = DB::table('rutas')
                    ->where('circuito', $codigo)
                    ->value('id_ruta');

                if (!empty($rutasViejas) && $rutaNuevaId) {
                    if (Schema::hasTable('camiones')) {
                        DB::table('camiones')->whereIn('id_ruta', $rutasViejas)->update(['id_ruta' => $rutaNuevaId]);
                    }
                    if (Schema::hasTable('camion_asignaciones')) {
                        DB::table('camion_asignaciones')->whereIn('id_ruta', $rutasViejas)->update(['id_ruta' => $rutaNuevaId]);
                    }
                    if (Schema::hasTable('cuadrilla_rutas')) {
                        DB::table('cuadrilla_rutas')->whereIn('id_ruta', $rutasViejas)->update(['id_ruta' => $rutaNuevaId]);
                    }
                    if (Schema::hasTable('recolecciones')) {
                        DB::table('recolecciones')->whereIn('id_ruta', $rutasViejas)->update(['id_ruta' => $rutaNuevaId]);
                    }
                    DB::table('rutas')->whereIn('id_ruta', $rutasViejas)->delete();
                }

                DB::table('circuitos')->where('id_circuito', $idOriginal)->delete();
            }

            // 3. Vinculamos automáticamente todos los contenedores que queden dentro del polígono de este circuito
            $contenedoresConUbicacion = DB::table('contenedores')
                ->whereNotNull('latitud')
                ->whereNotNull('longitud')
                ->get(['id_contenedor', 'latitud', 'longitud']);

            $idsDentro = [];
            foreach ($contenedoresConUbicacion as $cont) {
                if ($this->puntoDentroDePoligono((float) $cont->latitud, (float) $cont->longitud, $coordenadas)) {
                    $idsDentro[] = $cont->id_contenedor;
                }
            }

            if (!empty($idsDentro)) {
                DB::table('contenedores')
                    ->whereIn('id_contenedor', $idsDentro)
                    ->update(['circuito' => $codigo]);
            }
        });

        $totalContenedores = DB::table('contenedores')
            ->where('circuito', $codigo)
            ->where('activo', 1)
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Circuito guardado correctamente.',
            'data' => [
                'id_circuito' => $codigo,
                'municipio' => $municipio,
                'zona' => $zona,
                'descripcion' => $descripcion,
                'coordenadas' => $coordenadas,
                'total_contenedores' => $totalContenedores,
                'estado' => 'activo'
            ]
        ], $idOriginal ? 200 : 201);
    }

    // Consulta los detalles de un circuito específico
    public function show(string $id)
    {
        $circuito = DB::table('circuitos')
            ->where('id_circuito', $id)
            ->first();

        if (!$circuito) {
            return response()->json([
                'success' => false,
                'message' => 'Circuito no encontrado.'
            ], 404);
        }

        $coordenadas = null;
        if (!empty($circuito->coordenadas)) {
            $coordenadas = json_decode($circuito->coordenadas, true);
        }

        $totalContenedores = DB::table('contenedores')
            ->where('circuito', $circuito->id_circuito)
            ->where('activo', 1)
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'id_circuito' => $circuito->id_circuito,
                'municipio' => $circuito->municipio ?? null,
                'zona' => $circuito->zona ?? null,
                'descripcion' => $circuito->descripcion ?? null,
                'estado' => $circuito->estado ?? 'activo',
                'coordenadas' => $coordenadas,
                'total_contenedores' => $totalContenedores
            ]
        ]);
    }

    // Comprueba geométricamente si un contenedor (lat, lng) cae dentro del polígono del circuito
    private function puntoDentroDePoligono(float $lat, float $lng, array $poligono): bool
    {
        $dentro = false;
        $total = count($poligono);
        for ($i = 0, $j = $total - 1; $i < $total; $j = $i++) {
            $latI = (float) ($poligono[$i][0] ?? 0);
            $lngI = (float) ($poligono[$i][1] ?? 0);
            $latJ = (float) ($poligono[$j][0] ?? 0);
            $lngJ = (float) ($poligono[$j][1] ?? 0);

            $cruza = (($lngI > $lng) !== ($lngJ > $lng)) &&
                ($lat < ($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 0.0000001) + $latI);

            if ($cruza) {
                $dentro = !$dentro;
            }
        }
        return $dentro;
    }
}
