<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecoleccionController extends Controller
{
    // Lista todas las recolecciones registradas con datos del camión y la ruta
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

    // Devuelve la ruta y el camión asignados al chofer (por id_chofer o matrícula)
    public function rutaDeChofer(Request $request)
    {
        $idChofer = $request->query('id_chofer');
        $matricula = $request->query('matricula');

        $query = DB::table('camiones as c')
            ->leftJoin('rutas as r', 'r.id_ruta', '=', 'c.id_ruta')
            ->leftJoin('usuarios as u', 'u.id_usuario', '=', 'c.id_chofer')
            ->where('c.activo', 1)
            ->select(
                'c.*',
                'r.circuito as ruta_circuito',
                'r.municipio as ruta_municipio',
                DB::raw("TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) as chofer_nombre")
            );

        if ($idChofer) {
            $query->where('c.id_chofer', (int) $idChofer);
        } elseif ($matricula) {
            $query->where('c.matricula', $matricula);
        }

        $camion = $query->first();

        if (!$camion) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontró un camión asignado.'
            ], 404);
        }

        // Obtenemos los contenedores del circuito asignado que requieren recolección (3+ días o desbordados)
        $contenedores = [];
        if ($camion->ruta_circuito) {
            $contenedores = DB::table('contenedores as ct')
                ->leftJoin('ubicacion as ub', 'ub.id_ubicacion', '=', 'ct.id_ubicacion')
                ->where('ct.activo', 1)
                ->where('ct.circuito', $camion->ruta_circuito)
                ->select(
                    'ct.id_contenedor',
                    'ct.codigo',
                    'ct.tipo',
                    'ct.capacidad',
                    'ct.estado',
                    'ct.dias_sin_recoleccion',
                    'ct.circuito',
                    'ub.latitud',
                    'ub.longitud'
                )
                ->get();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'camion' => $camion,
                'contenedores' => $contenedores
            ]
        ]);
    }

    // Inicia un recorrido de recolección cuando el chofer presiona "Empezar"
    public function iniciarRecoleccion(Request $request)
    {
        $datos = $request->validate([
            'id_camion' => 'required|integer|exists:camiones,id_camion',
            'id_ruta' => 'nullable|integer|exists:rutas,id_ruta',
            'tipo' => 'nullable|string|max:30'
        ]);

        $camion = DB::table('camiones')->where('id_camion', $datos['id_camion'])->first();

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

    // Registra que el camión acaba de recolectar un contenedor en su ruta
    public function recolectarContenedor(Request $request, int $idRecoleccion)
    {
        $datos = $request->validate([
            'id_contenedor' => 'required|integer|exists:contenedores,id_contenedor',
            'peso_kg' => 'nullable|numeric|min:0'
        ]);

        $recoleccion = DB::table('recolecciones')->where('id_recoleccion', $idRecoleccion)->first();
        if (!$recoleccion) {
            return response()->json([
                'success' => false,
                'message' => 'Recolección no encontrada.'
            ], 404);
        }

        $pesoSumado = (float) ($datos['peso_kg'] ?? 180);

        DB::transaction(function () use ($idRecoleccion, $recoleccion, $datos, $pesoSumado) {
            // 1. Registramos el contenedor en recoleccion_contenedor
            DB::table('recoleccion_contenedor')->updateOrInsert(
                [
                    'id_recoleccion' => $idRecoleccion,
                    'id_contenedor' => $datos['id_contenedor']
                ],
                [
                    'fecha' => now()
                ]
            );

            // 2. Dejamos el contenedor en verde (1 día sin recolección y fecha de hoy)
            DB::table('contenedores')
                ->where('id_contenedor', $datos['id_contenedor'])
                ->update([
                    'dias_sin_recoleccion' => 1,
                    'ultimo_registro' => now()->toDateString(),
                    'estado' => 'funcional'
                ]);

            if (Schema::hasTable('historial_contenedores')) {
                DB::table('historial_contenedores')->insert([
                    'id_contenedor' => $datos['id_contenedor'],
                    'fecha' => now(),
                    'evento' => 'Recolección completada por camión #' . $recoleccion->id_camion
                ]);
            }

            // 3. Sumamos el contenedor y el peso a la recolección y al camión
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
        });

        return response()->json([
            'success' => true,
            'message' => 'Contenedor recolectado y actualizado a verde.'
        ]);
    }

    // Actualiza la posición GPS y el estado del camión durante el recorrido
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

    // Finaliza el recorrido cuando el camión descarga en el vertedero Felipe Cardoso y vuelve a la base
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
            'message' => 'Recolección finalizada. Camión descargado en vertedero y de regreso en base.'
        ]);
    }
}

