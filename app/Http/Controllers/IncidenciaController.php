<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidenciaController extends Controller
{
    public function index(Request $request)
    {
        $datos = $request->validate([
            'tipo_recurso' => [
                'nullable',
                Rule::in([
                    'camion',
                    'contenedor',
                    'cuadrilla'
                ])
            ]
        ]);

        $query =
            $this->consultaIncidencias();

        if (!empty($datos['tipo_recurso'])) {
            $query->where(
                'i.tipo_recurso',
                $datos['tipo_recurso']
            );
        }

        $incidencias = $query
            ->orderByDesc(
                'i.fecha_reporte'
            )
            ->orderByDesc(
                'i.id_incidencia'
            )
            ->get()
            ->map(
                fn ($item) =>
                    $this->formatearIncidencia(
                        $item
                    )
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => $incidencias
        ]);
    }


    public function show(int $id)
    {
        $incidencia =
            $this->obtenerIncidencia(
                $id
            );

        if (!$incidencia) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Incidencia no encontrada.'
            ], 404);
        }

        return response()->json([
            'success' => true,

            'data' =>
                $this->formatearIncidencia(
                    $incidencia
                )
        ]);
    }


    public function update(
        Request $request,
        int $id
    ) {
        $incidencia =
            DB::table('incidencias')
                ->where(
                    'id_incidencia',
                    $id
                )
                ->first();

        if (!$incidencia) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Incidencia no encontrada.'
            ], 404);
        }

        $this->normalizarGestion(
            $request
        );

        $datos =
            $request->validate([
                'estado' => [
                    'required',
                    Rule::in([
                        'Abierta',
                        'En curso',
                        'Resuelta'
                    ])
                ],

                'responsable' => [
                    'nullable',
                    'string',
                    'max:150'
                ],

                'resolucion' => [
                    'nullable',
                    'string'
                ],

                'id_cuadrilla' => [
                    'nullable',
                    'string',
                    'max:20',

                    Rule::exists(
                        'cuadrilla',
                        'id_cuadrilla'
                    )
                ]
            ]);

        $fechaResolucion =
            null;

        if (
            $datos['estado'] ===
            'Resuelta'
        ) {
            $fechaResolucion =
                $incidencia
                    ->fecha_resolucion
                ?? now();
        }

        $tipoRecurso =
            mb_strtolower(
                trim(
                    (string)
                    $incidencia
                        ->tipo_recurso
                )
            );

        if (
            $tipoRecurso ===
            'contenedor'
        ) {
            $idCuadrilla =
                $datos[
                    'id_cuadrilla'
                ] ?? null;

            $responsable =
                $idCuadrilla
                    ? 'Cuadrilla ' .
                        $idCuadrilla
                    : null;
        } else {
            $idCuadrilla =
                $incidencia
                    ->id_cuadrilla;

            $responsable =
                $datos[
                    'responsable'
                ] ?? null;
        }

        DB::table('incidencias')
            ->where(
                'id_incidencia',
                $id
            )
            ->update([
                'estado' =>
                    $datos['estado'],

                'responsable' =>
                    $responsable,

                'resolucion' =>
                    $datos[
                        'resolucion'
                    ] ?? null,

                'fecha_resolucion' =>
                    $fechaResolucion,

                'id_cuadrilla' =>
                    $idCuadrilla
            ]);

        return response()->json([
            'success' => true,

            'message' =>
                'Incidencia administrada correctamente.',

            'data' =>
                $this->formatearIncidencia(
                    $this->obtenerIncidencia(
                        $id
                    )
                )
        ]);
    }


    private function normalizarGestion(
        Request $request
    ): void {
        $datos = [];

        foreach (
            [
                'responsable',
                'resolucion',
                'id_cuadrilla'
            ] as $campo
        ) {
            if (
                $request->has(
                    $campo
                )
            ) {
                $valor =
                    trim(
                        (string)
                        $request->{$campo}
                    );

                $datos[$campo] =
                    $valor !== ''
                        ? $valor
                        : null;
            }
        }

        $request->merge(
            $datos
        );
    }


    private function consultaIncidencias()
    {
        return DB::table(
            'incidencias as i'
        )
            ->leftJoin(
                'camiones as c',
                'i.id_camion',
                '=',
                'c.id_camion'
            )
            ->leftJoin(
                'contenedores as co',
                'i.id_contenedor',
                '=',
                'co.id_contenedor'
            )
            ->select(
                'i.id_incidencia',
                'i.tipo_recurso',
                'i.id_camion',
                'i.id_contenedor',
                'i.tipo',
                'i.estado',
                'i.fecha_reporte',
                'i.fecha_resolucion',
                'i.descripcion',
                'i.responsable',
                'i.resolucion',
                'i.id_cuadrilla',

                'c.matricula as camion_matricula',

                'co.codigo as contenedor_codigo'
            );
    }


    private function obtenerIncidencia(
        int $id
    ) {
        return $this
            ->consultaIncidencias()
            ->where(
                'i.id_incidencia',
                $id
            )
            ->first();
    }


    private function formatearIncidencia(
        $item
    ): array {
        $tipoRecurso =
            mb_strtolower(
                trim(
                    (string)
                    $item->tipo_recurso
                )
            );

        $recurso = null;

        if (
            $tipoRecurso ===
            'camion'
        ) {
            $recurso =
                $item
                    ->camion_matricula;
        }

        if (
            $tipoRecurso ===
            'contenedor'
        ) {
            $recurso =
                $item
                    ->contenedor_codigo;
        }

        if (
            $tipoRecurso ===
            'cuadrilla'
        ) {
            $recurso =
                $item
                    ->id_cuadrilla;
        }

        return [
            'id_incidencia' =>
                (int)
                $item
                    ->id_incidencia,

            'tipo_recurso' =>
                $tipoRecurso,

            'recurso' =>
                $recurso,

            'id_camion' =>
                $item->id_camion
                    !== null
                    ? (int)
                        $item
                            ->id_camion
                    : null,

            'id_contenedor' =>
                $item->id_contenedor
                    !== null
                    ? (int)
                        $item
                            ->id_contenedor
                    : null,

            'id_cuadrilla' =>
                $item
                    ->id_cuadrilla,

            'tipo' =>
                $item->tipo,

            'estado' =>
                $item->estado,

            'fecha_reporte' =>
                $item
                    ->fecha_reporte,

            'fecha_resolucion' =>
                $item
                    ->fecha_resolucion,

            'descripcion' =>
                $item
                    ->descripcion,

            'responsable' =>
                $item
                    ->responsable,

            'resolucion' =>
                $item
                    ->resolucion
        ];
    }
}