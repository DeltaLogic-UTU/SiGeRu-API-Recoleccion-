<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoleccionController extends Controller
{
    private function archivoDespachos(): string
    {
        $rutaCompartida = dirname(base_path()) . DIRECTORY_SEPARATOR . 'SiGeRu-API-Recoleccion' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'rutas_despachadas.json';
        if (is_dir(dirname($rutaCompartida))) {
            return $rutaCompartida;
        }
        return storage_path('app/rutas_despachadas.json');
    }

    private function leerDespachos(): array
    {
        $archivo = $this->archivoDespachos();
        if (!file_exists($archivo)) {
            return [];
        }

        $datos = json_decode(file_get_contents($archivo) ?: '{}', true);
        return is_array($datos) ? $datos : [];
    }

    private function guardarDespachos(array $despachos): void
    {
        $archivo = $this->archivoDespachos();
        if (!is_dir(dirname($archivo))) {
            mkdir(dirname($archivo), 0775, true);
        }

        file_put_contents(
            $archivo,
            json_encode($despachos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    // Lista todas las recolecciones registradas
    public function index()
    {
        $recolecciones = DB::table('recolecciones as r')
            ->leftJoin('camiones as c', 'c.id_camion', '=', 'r.id_camion')
            ->leftJoin('rutas as ru', 'ru.id_ruta', '=', 'r.id_ruta')
            ->select(
                'r.*',
                'c.matricula as camion_matricula',
                'ru.circuito as ruta_circuito'
            )
            ->orderByDesc('r.id_recoleccion')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $recolecciones
        ]);
    }

    // Recibe la ruta enviada por el Administrador y la deja asignada al chofer
    public function despacharRuta(Request $request)
    {
        $datos = $request->validate([
            'id_camion' => 'required|integer|exists:camiones,id_camion',
            'id_ruta' => 'nullable|integer',
            'circuitos' => 'required|array|min:1',
            'contenedores' => 'required|array|min:1',
            'geometriaCalles' => 'required|array|min:2',
            'distanciaKm' => 'required|numeric|min:0'
        ]);

        $camion = DB::table('camiones')->where('id_camion', $datos['id_camion'])->first();

        $idsContenedores = array_values(array_filter(array_map(
            fn ($c) => isset($c['id_contenedor']) ? (int) $c['id_contenedor'] : null,
            $datos['contenedores']
        )));

        $idRuta = $datos['id_ruta'] ?? $camion->id_ruta;
        if (!empty($datos['circuitos'][0])) {
            $primerCircuito = $datos['circuitos'][0];
            $rutaRow = DB::table('rutas')->where('circuito', $primerCircuito)->first();
            if (!$rutaRow) {
                $circuitoRow = DB::table('circuitos')->where('id_circuito', $primerCircuito)->first();
                if ($circuitoRow) {
                    $idRuta = DB::table('rutas')->insertGetId([
                        'circuito' => $primerCircuito,
                        'nombre' => 'Circuito ' . $primerCircuito,
                        'municipio' => $circuitoRow->municipio ?? 'A',
                        'estado' => 'Asignada'
                    ]);
                }
            } else {
                $idRuta = $rutaRow->id_ruta;
            }
        }

        $despachos = $this->leerDespachos();
        $despacho = [
            'id_camion' => (int) $camion->id_camion,
            'matricula' => $camion->matricula,
            'id_chofer' => $camion->id_chofer ? (int) $camion->id_chofer : null,
            'id_ruta' => $idRuta,
            'circuitos' => $datos['circuitos'],
            'ids_contenedores' => $idsContenedores,
            'contenedores_procesados' => [],
            'geometriaCalles' => $datos['geometriaCalles'],
            'distanciaKm' => (float) $datos['distanciaKm'],
            'km_recorridos' => 0,
            'incidencias_creadas' => 0,
            'estado' => 'Enviada al chofer',
            'fecha_envio' => now()->toIso8601String()
        ];

        $despachos[(string) $camion->id_camion] = $despacho;
        $this->guardarDespachos($despachos);

        DB::table('camiones')
            ->where('id_camion', $camion->id_camion)
            ->update([
                'id_ruta' => $idRuta,
                'contenedores_asignados' => count($idsContenedores),
                'contenedores_recolectados' => 0,
                'km_en_turno' => (float) $datos['distanciaKm']
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Ruta enviada al chofer correctamente.',
            'data' => $despacho
        ]);
    }

    // Devuelve al chofer su camión, la ruta enviada por el administrador y los contenedores a levantar
    public function rutaDeChofer(Request $request)
    {
        $usuarioToken = auth('api')->user();
        $idChofer = $request->query('id_chofer') ?: ($usuarioToken->id_usuario ?? null);
        $matricula = $request->query('matricula');

        $despachos = $this->leerDespachos();

        $baseQuery = fn () => DB::table('camiones as c')
            ->leftJoin('rutas as r', 'r.id_ruta', '=', 'c.id_ruta')
            ->leftJoin('usuarios as u', 'u.id_usuario', '=', 'c.id_chofer')
            ->where('c.activo', 1)
            ->select(
                'c.*',
                'r.circuito as ruta_circuito',
                'r.municipio as ruta_municipio',
                DB::raw("TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) as chofer_nombre")
            );

        $camion = null;

        if ($matricula) {
            $camion = $baseQuery()->where('c.matricula', $matricula)->first();
        }

        if (!$camion && $idChofer) {
            $camion = $baseQuery()->where('c.id_chofer', (int) $idChofer)->first();
        }

        // Si el chofer logueado no tiene camión vinculado pero hay una ruta recién enviada por el admin, usamos ese camión
        if (!$camion && count($despachos) > 0) {
            $ultimo = end($despachos);
            if (!empty($ultimo['id_camion'])) {
                $camion = $baseQuery()->where('c.id_camion', (int) $ultimo['id_camion'])->first();
            }
        }

        if (!$camion) {
            $camion = $baseQuery()->orderBy('c.id_camion')->first();
        }

        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un camión asignado.'
            ], 404);
        }

        $despachoCamion = $despachos[(string) $camion->id_camion] ?? null;
        $contenedores = [];

        if ($despachoCamion && !empty($despachoCamion['ids_contenedores'])) {
            $ids = array_map('intval', $despachoCamion['ids_contenedores']);
            $filas = DB::table('contenedores as ct')
                ->leftJoin('ubicacion as ub', 'ub.id_ubicacion', '=', 'ct.id_ubicacion')
                ->whereIn('ct.id_contenedor', $ids)
                ->select(
                    'ct.id_contenedor',
                    'ct.codigo',
                    'ct.tipo',
                    'ct.capacidad',
                    'ct.estado',
                    'ct.dias_sin_recoleccion',
                    'ct.ultimo_registro',
                    'ct.circuito',
                    'ub.calle',
                    'ub.esquina_1 as esquina',
                    'ub.latitud',
                    'ub.longitud'
                )
                ->get()
                ->keyBy('id_contenedor');

            $procesados = $despachoCamion['contenedores_procesados'] ?? [];
            foreach ($ids as $idCont) {
                if (isset($filas[$idCont])) {
                    $item = (array) $filas[$idCont];
                    $item['procesado_en_ruta'] = $procesados[(string) $idCont] ?? null;
                    $contenedores[] = $item;
                }
            }
        } elseif ($camion->ruta_circuito) {
            $contenedores = DB::table('contenedores as ct')
                ->leftJoin('ubicacion as ub', 'ub.id_ubicacion', '=', 'ct.id_ubicacion')
                ->where('ct.activo', 1)
                ->where('ct.circuito', $camion->ruta_circuito)
                ->where('ct.dias_sin_recoleccion', '>=', 3)
                ->select(
                    'ct.id_contenedor',
                    'ct.codigo',
                    'ct.tipo',
                    'ct.capacidad',
                    'ct.estado',
                    'ct.dias_sin_recoleccion',
                    'ct.ultimo_registro',
                    'ct.circuito',
                    'ub.calle',
                    'ub.esquina_1 as esquina',
                    'ub.latitud',
                    'ub.longitud'
                )
                ->get();
        }

        $recoleccionActiva = DB::table('recolecciones')
            ->where('id_camion', $camion->id_camion)
            ->where('estado', 'En curso')
            ->orderByDesc('id_recoleccion')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'camion' => $camion,
                'ruta_despachada' => $despachoCamion,
                'contenedores' => $contenedores,
                'recoleccion_activa' => $recoleccionActiva
            ]
        ]);
    }

    // Inicia el viaje cuando el chofer presiona "Iniciar recorrido"
    public function iniciarRecoleccion(Request $request)
    {
        $datos = $request->validate([
            'id_camion' => 'required|integer|exists:camiones,id_camion',
            'id_ruta' => 'nullable|integer',
            'tipo' => 'nullable|string|max:30'
        ]);

        $camion = DB::table('camiones')->where('id_camion', $datos['id_camion'])->first();

        $existente = DB::table('recolecciones')
            ->where('id_camion', $datos['id_camion'])
            ->where('estado', 'En curso')
            ->orderByDesc('id_recoleccion')
            ->first();

        if ($existente) {
            return response()->json([
                'success' => true,
                'message' => 'Recorrido en curso.',
                'data' => [
                    'id_recoleccion' => $existente->id_recoleccion
                ]
            ]);
        }

        $idRecoleccion = DB::table('recolecciones')->insertGetId([
            'id_camion' => $datos['id_camion'],
            'id_ruta' => $datos['id_ruta'] ?? $camion->id_ruta,
            'id_cuadrilla' => $camion->id_cuadrilla ?? null,
            'fecha_inicio' => now(),
            'tipo' => $datos['tipo'] ?? ($camion->tipo ?? 'general'),
            'volumen_recolectado' => 0,
            'peso_recolectado_kg' => 0,
            'contenedores_recolectados' => 0,
            'estado' => 'En curso'
        ]);

        DB::table('camiones')
            ->where('id_camion', $datos['id_camion'])
            ->update([
                'estado' => 'Recolectando',
                'contenedores_recolectados' => 0,
                'peso_recolectado_kg' => 0
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Recorrido de recolección iniciado.',
            'data' => [
                'id_recoleccion' => $idRecoleccion
            ]
        ], 201);
    }

    // Registra contenedor levantado o incidencia (no levantado + motivo) y guarda en el historial del contenedor
    public function recolectarContenedor(Request $request, int $idRecoleccion)
    {
        $datos = $request->validate([
            'id_contenedor' => 'required|integer|exists:contenedores,id_contenedor',
            'accion' => 'nullable|string|in:levantado,no_levantado',
            'motivo' => 'nullable|string|max:180',
            'descripcion' => 'nullable|string|max:500',
            'peso_kg' => 'nullable|numeric|min:0'
        ]);

        $recoleccion = DB::table('recolecciones')->where('id_recoleccion', $idRecoleccion)->first();
        if (!$recoleccion) {
            return response()->json([
                'success' => false,
                'message' => 'Recolección no encontrada.'
            ], 404);
        }

        $camion = DB::table('camiones')->where('id_camion', $recoleccion->id_camion)->first();
        $contenedor = DB::table('contenedores')->where('id_contenedor', $datos['id_contenedor'])->first();
        $accion = $datos['accion'] ?? 'levantado';
        $matricula = $camion->matricula ?? ('#' . $recoleccion->id_camion);
        $pesoSumado = (float) ($datos['peso_kg'] ?? 180);
        $fechaHoy = now()->toDateString();
        $idIncidenciaCreada = null;

        DB::transaction(function () use (
            $idRecoleccion,
            $recoleccion,
            $contenedor,
            $datos,
            $accion,
            $matricula,
            $pesoSumado,
            $fechaHoy,
            &$idIncidenciaCreada
        ) {
            $estadoAnterior = $contenedor->estado ?? 'funcional';

            if ($accion === 'levantado') {
                // 1. Guardamos en recoleccion_contenedor
                DB::table('recoleccion_contenedor')->updateOrInsert(
                    [
                        'id_recoleccion' => $idRecoleccion,
                        'id_contenedor' => $datos['id_contenedor']
                    ],
                    [
                        'fecha' => now()
                    ]
                );

                // 2. Actualizamos fecha de última vez recolectado y días sin recolección
                DB::table('contenedores')
                    ->where('id_contenedor', $datos['id_contenedor'])
                    ->update([
                        'dias_sin_recoleccion' => 1,
                        'ultimo_registro' => $fechaHoy,
                        'estado' => 'funcional'
                    ]);

                // 3. Guardamos en historial_contenedores para futuras estadísticas
                $obs = !empty($datos['descripcion']) ? ' (' . $datos['descripcion'] . ')' : '';
                if (Schema::hasTable('historial_contenedores')) {
                    DB::table('historial_contenedores')->insert([
                        'id_contenedor' => $datos['id_contenedor'],
                        'fecha' => now(),
                        'estado_anterior' => $estadoAnterior,
                        'estado_nuevo' => 'funcional',
                        'id_ubicacion_anterior' => null,
                        'id_ubicacion_nueva' => null,
                        'motivo' => 'Limpieza: Levantado por camión ' . $matricula . ' — Última recolección: ' . $fechaHoy . $obs
                    ]);
                }

                // Si fue levantado pero con observación de incidencia, también la registramos
                if (!empty($datos['motivo'])) {
                    $idIncidenciaCreada = DB::table('incidencias')->insertGetId([
                        'tipo_recurso' => 'contenedor',
                        'id_camion' => $recoleccion->id_camion,
                        'id_contenedor' => $datos['id_contenedor'],
                        'id_cuadrilla' => $recoleccion->id_cuadrilla,
                        'tipo' => $datos['motivo'],
                        'estado' => 'Abierta',
                        'fecha_reporte' => now(),
                        'descripcion' => 'Levantado con incidencia: ' . $datos['motivo'] . $obs,
                        'responsable' => 'Camión ' . $matricula
                    ]);
                }

                // 4. Actualizamos contadores en recolecciones y camiones
                $totalRecolectados = DB::table('recoleccion_contenedor')
                    ->where('id_recoleccion', $idRecoleccion)
                    ->count();

                DB::table('recolecciones')
                    ->where('id_recoleccion', $idRecoleccion)
                    ->update([
                        'contenedores_recolectados' => $totalRecolectados,
                        'peso_recolectado_kg' => DB::raw("COALESCE(peso_recolectado_kg, 0) + $pesoSumado")
                    ]);

                DB::table('camiones')
                    ->where('id_camion', $recoleccion->id_camion)
                    ->update([
                        'contenedores_recolectados' => $totalRecolectados,
                        'peso_recolectado_kg' => DB::raw("COALESCE(peso_recolectado_kg, 0) + $pesoSumado")
                    ]);
            } else {
                // Caso NO LEVANTADO: registramos la incidencia y el motivo en el historial del contenedor
                $motivo = $datos['motivo'] ?? 'No se pudo levantar';
                $obs = !empty($datos['descripcion']) ? ' — ' . $datos['descripcion'] : '';
                $nuevoEstado = stripos($motivo, 'roto') !== false ? 'roto' : $estadoAnterior;

                if ($nuevoEstado !== $estadoAnterior) {
                    DB::table('contenedores')
                        ->where('id_contenedor', $datos['id_contenedor'])
                        ->update(['estado' => $nuevoEstado]);
                }

                $idIncidenciaCreada = DB::table('incidencias')->insertGetId([
                    'tipo_recurso' => 'contenedor',
                    'id_camion' => $recoleccion->id_camion,
                    'id_contenedor' => $datos['id_contenedor'],
                    'id_cuadrilla' => $recoleccion->id_cuadrilla,
                    'tipo' => $motivo,
                    'estado' => 'Abierta',
                    'fecha_reporte' => now(),
                    'descripcion' => 'No levantado por camión ' . $matricula . '. Motivo: ' . $motivo . $obs,
                    'responsable' => 'Camión ' . $matricula
                ]);

                if (Schema::hasTable('historial_contenedores')) {
                    DB::table('historial_contenedores')->insert([
                        'id_contenedor' => $datos['id_contenedor'],
                        'fecha' => now(),
                        'estado_anterior' => $estadoAnterior,
                        'estado_nuevo' => $nuevoEstado,
                        'id_ubicacion_anterior' => null,
                        'id_ubicacion_nueva' => null,
                        'motivo' => 'Inspección: Contenedor NO levantado por camión ' . $matricula . ' — Motivo: ' . $motivo . $obs
                    ]);
                }
            }
        });

        $despachos = $this->leerDespachos();
        $keyCamion = (string) $recoleccion->id_camion;
        if (isset($despachos[$keyCamion])) {
            $despachos[$keyCamion]['contenedores_procesados'][(string) $datos['id_contenedor']] = [
                'accion' => $accion,
                'motivo' => $datos['motivo'] ?? null,
                'fecha' => now()->toIso8601String()
            ];
            if ($idIncidenciaCreada) {
                $despachos[$keyCamion]['incidencias_creadas'] = (int) ($despachos[$keyCamion]['incidencias_creadas'] ?? 0) + 1;
            }
            $this->guardarDespachos($despachos);
        }

        return response()->json([
            'success' => true,
            'message' => $accion === 'levantado'
                ? 'Contenedor levantado e historial guardado.'
                : 'Incidencia registrada en el contenedor.',
            'data' => [
                'id_contenedor' => (int) $datos['id_contenedor'],
                'accion' => $accion,
                'ultimo_registro' => $fechaHoy,
                'id_incidencia' => $idIncidenciaCreada
            ]
        ]);
    }

    // Actualiza la posición GPS del camión durante el recorrido
    public function actualizarGpsCamion(Request $request, int $idCamion)
    {
        $datos = $request->validate([
            'latitud' => 'required|numeric',
            'longitud' => 'required|numeric',
            'estado' => 'nullable|string|max:50'
        ]);

        $campos = [
            'gps' => $datos['latitud'] . ', ' . $datos['longitud']
        ];

        if (!empty($datos['estado'])) {
            $campos['estado'] = $datos['estado'];
        }

        DB::table('camiones')
            ->where('id_camion', $idCamion)
            ->update($campos);

        return response()->json([
            'success' => true,
            'message' => 'Ubicación GPS actualizada.'
        ]);
    }

    // Finaliza el recorrido cuando el camión descarga en el vertedero y vuelve a la base
    public function finalizarRecoleccion(Request $request, int $idRecoleccion)
    {
        $recoleccion = DB::table('recolecciones')->where('id_recoleccion', $idRecoleccion)->first();
        if (!$recoleccion) {
            return response()->json([
                'success' => false,
                'message' => 'Recolección no encontrada.'
            ], 404);
        }

        DB::transaction(function () use ($idRecoleccion, $recoleccion) {
            DB::table('recolecciones')
                ->where('id_recoleccion', $idRecoleccion)
                ->update([
                    'fecha_fin' => now(),
                    'estado' => 'Finalizada'
                ]);

            DB::table('camiones')
                ->where('id_camion', $recoleccion->id_camion)
                ->update([
                    'estado' => 'En base',
                    'peso_recolectado_kg' => 0
                ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Recolección finalizada.'
        ]);
    }
}