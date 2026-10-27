<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReclamoController extends Controller
{
    public function index(Request $request)
    {
        $datos = $request->validate([
            'estado' => [
                'nullable',
                Rule::in([
                    'sin_asignar',
                    'asignado',
                    'completado'
                ])
            ],

            'prioridad' => [
                'nullable',
                Rule::in([
                    'Baja',
                    'Media',
                    'Alta'
                ])
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

        $query = $this->consultaReclamos();

        if (!empty($datos['estado'])) {
            $query->where(
                'r.estado',
                $datos['estado']
            );
        }

        if (!empty($datos['prioridad'])) {
            $query->where(
                'r.prioridad',
                $datos['prioridad']
            );
        }

        if (!empty($datos['id_cuadrilla'])) {
            $query->where(
                'r.id_cuadrilla',
                $datos['id_cuadrilla']
            );
        }

        $reclamos = $query
            ->orderByDesc('r.fecha_reporte')
            ->get()
            ->map(fn ($item) => $this->formatearReclamo($item))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $reclamos
        ]);
    }

    public function show(string $id)
    {
        $reclamo = $this->obtenerReclamo($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatearReclamo($reclamo)
        ]);
    }

    public function asignar(
        Request $request,
        string $id
    ) {
        $reclamo = $this->buscarBase($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        if ($reclamo->estado === 'completado') {
            return response()->json([
                'success' => false,
                'message' =>
                    'El reclamo debe reabrirse antes de asignarlo.'
            ], 422);
        }

        $datos = $request->validate([
            'id_cuadrilla' => [
                'required',
                'string',
                'max:20',
                Rule::exists(
                    'cuadrilla',
                    'id_cuadrilla'
                )
            ],

            'prioridad' => [
                'nullable',
                Rule::in([
                    'Baja',
                    'Media',
                    'Alta'
                ])
            ]
        ]);

        $actualizacion = [
            'id_cuadrilla' => $datos['id_cuadrilla'],
            'estado' => 'asignado',
            'fecha_finalizacion' => null,
            'notificado' => 0
        ];

        if (array_key_exists('prioridad', $datos)) {
            $actualizacion['prioridad'] =
                $datos['prioridad'];
        }

        DB::table('reclamos')
            ->where('id_reclamo', $id)
            ->update($actualizacion);

        return $this->respuestaActualizada(
            $id,
            'Reclamo asignado correctamente.'
        );
    }

    public function quitarAsignacion(string $id)
    {
        $reclamo = $this->buscarBase($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        if ($reclamo->estado !== 'asignado') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Solo se puede quitar la asignación de un reclamo asignado.'
            ], 422);
        }

        DB::table('reclamos')
            ->where('id_reclamo', $id)
            ->update([
                'estado' => 'sin_asignar',
                'id_cuadrilla' => null,
                'fecha_finalizacion' => null,
                'resultado' => null,
                'notificado' => 0
            ]);

        return $this->respuestaActualizada(
            $id,
            'Asignación eliminada correctamente.'
        );
    }

    public function completar(
        Request $request,
        string $id
    ) {
        $reclamo = $this->buscarBase($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        if (
            $reclamo->estado !== 'asignado' ||
            !$reclamo->id_cuadrilla
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El reclamo debe estar asignado a una cuadrilla antes de completarse.'
            ], 422);
        }

        $datos = $request->validate([
            'resultado' => [
                'required',
                'string',
                'max:255'
            ]
        ]);

        $resultado = trim($datos['resultado']);

        if ($resultado === '') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Debe registrarse un resultado antes de completar el reclamo.'
            ], 422);
        }

        DB::table('reclamos')
            ->where('id_reclamo', $id)
            ->update([
                'estado' => 'completado',
                'fecha_finalizacion' => now(),
                'resultado' => $resultado,
                'notificado' => 0
            ]);

        return $this->respuestaActualizada(
            $id,
            'Reclamo completado correctamente.'
        );
    }

    public function reabrir(string $id)
    {
        $reclamo = $this->buscarBase($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        if ($reclamo->estado !== 'completado') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Solo se puede reabrir un reclamo completado.'
            ], 422);
        }

        $estado = $reclamo->id_cuadrilla
            ? 'asignado'
            : 'sin_asignar';

        DB::table('reclamos')
            ->where('id_reclamo', $id)
            ->update([
                'estado' => $estado,
                'fecha_finalizacion' => null,
                'notificado' => 0
            ]);

        return $this->respuestaActualizada(
            $id,
            'Reclamo reabierto correctamente.'
        );
    }

    public function registrarResultado(
        Request $request,
        string $id
    ) {
        $reclamo = $this->buscarBase($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        if ($reclamo->estado !== 'completado') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Solo se puede modificar el resultado de un reclamo completado.'
            ], 422);
        }

        $datos = $request->validate([
            'resultado' => [
                'required',
                'string',
                'max:255'
            ]
        ]);

        $resultado = trim($datos['resultado']);

        if ($resultado === '') {
            return response()->json([
                'success' => false,
                'message' =>
                    'El resultado no puede estar vacío.'
            ], 422);
        }

        DB::table('reclamos')
            ->where('id_reclamo', $id)
            ->update([
                'resultado' => $resultado,
                'notificado' => 0
            ]);

        return $this->respuestaActualizada(
            $id,
            'Resultado actualizado correctamente.'
        );
    }

    public function notificar(string $id)
    {
        $reclamo = $this->buscarBase($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        if ($reclamo->estado !== 'completado') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Solo se puede enviar el resultado de un reclamo completado.'
            ], 422);
        }

        if (!$reclamo->resultado) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El reclamo no tiene un resultado para enviar.'
            ], 422);
        }

        if (!$reclamo->id_usuario) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El reclamo no está asociado a una cuenta de vecino.'
            ], 422);
        }

        $usuarioExiste = DB::table('usuarios')
            ->where(
                'id_usuario',
                $reclamo->id_usuario
            )
            ->exists();

        if (!$usuarioExiste) {
            return response()->json([
                'success' => false,
                'message' =>
                    'No se encontró la cuenta asociada al reclamo.'
            ], 422);
        }

        if ((bool) $reclamo->notificado) {
            return response()->json([
                'success' => true,
                'message' =>
                    'El resultado ya fue enviado a la cuenta del vecino.',
                'data' => $this->formatearReclamo(
                    $this->obtenerReclamo($id)
                )
            ]);
        }

        DB::table('reclamos')
            ->where('id_reclamo', $id)
            ->update([
                'notificado' => 1
            ]);

        return $this->respuestaActualizada(
            $id,
            'Resultado enviado a la cuenta del vecino correctamente.'
        );
    }

    private function consultaReclamos()
    {
        return DB::table('reclamos as r')
            ->leftJoin(
                'cuadrilla as q',
                'r.id_cuadrilla',
                '=',
                'q.id_cuadrilla'
            )
            ->leftJoin(
                'usuarios as responsable',
                'q.id_responsable',
                '=',
                'responsable.id_usuario'
            )
            ->leftJoin(
                'usuarios as vecino',
                'r.id_usuario',
                '=',
                'vecino.id_usuario'
            )
            ->leftJoin(
                'datos as datos_vecino',
                'r.id_usuario',
                '=',
                'datos_vecino.id_usuario'
            )
            ->leftJoin(
                'contenedores as c',
                'r.id_contenedor',
                '=',
                'c.id_contenedor'
            )
            ->leftJoin(
                'ubicacion as ub',
                'c.id_ubicacion',
                '=',
                'ub.id_ubicacion'
            )
            ->select(
                'r.id_reclamo',
                'r.id_usuario',
                'r.reclamante',
                'r.contacto',
                'r.categoria',
                'r.tipo',
                'r.zona',
                'r.referencia',
                'r.ubicacion',
                'r.descripcion',
                'r.foto',
                'r.estado',
                'r.prioridad',
                'r.id_cuadrilla',
                'r.id_contenedor',
                'r.fecha_reporte',
                'r.fecha_finalizacion',
                'r.resultado',
                'r.notificado',

                'c.codigo as contenedor_codigo',

                'q.turno as cuadrilla_turno',
                'q.estado as cuadrilla_estado',
                'q.ubicacion_actual as cuadrilla_ubicacion',

                'responsable.nombre as responsable_nombre',
                'responsable.apellido as responsable_apellido',

                'vecino.nombre as vecino_nombre',
                'vecino.apellido as vecino_apellido',

                'datos_vecino.email as vecino_email',
                'datos_vecino.telefono as vecino_telefono',

                'ub.municipio as contenedor_municipio',
                'ub.barrio as contenedor_barrio',
                'ub.latitud as contenedor_latitud',
                'ub.longitud as contenedor_longitud'
            );
    }

    private function obtenerReclamo(string $id)
    {
        return $this->consultaReclamos()
            ->where('r.id_reclamo', $id)
            ->first();
    }

    private function buscarBase(string $id)
    {
        return DB::table('reclamos')
            ->where('id_reclamo', $id)
            ->first();
    }

    private function formatearReclamo($item): array
    {
        $responsable = trim(
            ($item->responsable_nombre ?? '') .
            ' ' .
            ($item->responsable_apellido ?? '')
        );

        $vecino = trim(
            ($item->vecino_nombre ?? '') .
            ' ' .
            ($item->vecino_apellido ?? '')
        );

        return [
            'id_reclamo' => $item->id_reclamo,

            'id_usuario' =>
                $item->id_usuario !== null
                    ? (int) $item->id_usuario
                    : null,

            'reclamante' => $item->reclamante,
            'contacto' => $item->contacto,
            'categoria' => $item->categoria,
            'tipo' => $item->tipo,
            'zona' => $item->zona,
            'referencia' => $item->referencia,
            'ubicacion' => $item->ubicacion,
            'descripcion' => $item->descripcion,
            'foto' => $item->foto,
            'estado' => $item->estado,
            'prioridad' => $item->prioridad,
            'fecha_reporte' => $item->fecha_reporte,
            'fecha_finalizacion' => $item->fecha_finalizacion,
            'resultado' => $item->resultado,
            'notificado' => (bool) $item->notificado,

            'id_cuadrilla' => $item->id_cuadrilla,
            'cuadrilla' => $item->id_cuadrilla,

            'cuadrilla_responsable' =>
                $responsable !== ''
                    ? $responsable
                    : null,

            'cuadrilla_turno' =>
                $item->cuadrilla_turno,

            'cuadrilla_estado' =>
                $item->cuadrilla_estado,

            'cuadrilla_ubicacion' =>
                $item->cuadrilla_ubicacion,

            'id_contenedor' =>
                $item->id_contenedor !== null
                    ? (int) $item->id_contenedor
                    : null,

            'contenedor' =>
                $item->contenedor_codigo,

            'municipio' =>
                $item->contenedor_municipio,

            'barrio' =>
                $item->contenedor_barrio,

            'latitud' =>
                $item->contenedor_latitud !== null
                    ? (float) $item->contenedor_latitud
                    : null,

            'longitud' =>
                $item->contenedor_longitud !== null
                    ? (float) $item->contenedor_longitud
                    : null,

            'vecino_nombre' =>
                $vecino !== ''
                    ? $vecino
                    : null,

            'vecino_email' =>
                $item->vecino_email,

            'vecino_telefono' =>
                $item->vecino_telefono
        ];
    }

    private function respuestaActualizada(
        string $id,
        string $message
    ) {
        $reclamo = $this->obtenerReclamo($id);

        if (!$reclamo) {
            return $this->noEncontrado();
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->formatearReclamo($reclamo)
        ]);
    }

    private function noEncontrado()
    {
        return response()->json([
            'success' => false,
            'message' => 'Reclamo no encontrado.'
        ], 404);
    }
}