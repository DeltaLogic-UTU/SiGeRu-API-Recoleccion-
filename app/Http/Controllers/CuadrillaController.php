<?php

namespace App\Http\Controllers;

use App\Models\Cuadrilla;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CuadrillaController extends Controller
{
    public function index()
    {
        $cuadrillas = Cuadrilla::with('responsable')
            ->orderBy('id_cuadrilla')
            ->get()
            ->map(fn ($cuadrilla) => $this->formatearCuadrilla($cuadrilla))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $cuadrillas
        ]);
    }

    public function responsables()
    {
        $barrenderos = DB::table('usuarios as u')
            ->join('rol as r', 'u.id_rol', '=', 'r.id_rol')
            ->where('r.nombre', 'barrendero')
            ->where('u.estado', 'activo')
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido'
            )
            ->orderBy('u.nombre')
            ->orderBy('u.apellido')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $barrenderos
        ]);
    }

    public function integrantesDisponibles(string $id)
    {
        Cuadrilla::findOrFail($id);

        $integrantesActuales = DB::table('cuadrilla_integrantes')
            ->whereNull('fecha_finalizacion')
            ->pluck('id_usuario');

        $responsablesActuales = DB::table('cuadrilla')
            ->whereNotNull('id_responsable')
            ->pluck('id_responsable');

        $barrenderos = DB::table('usuarios as u')
            ->join('rol as r', 'u.id_rol', '=', 'r.id_rol')
            ->where('r.nombre', 'barrendero')
            ->where('u.estado', 'activo')
            ->whereNotIn('u.id_usuario', $integrantesActuales)
            ->whereNotIn('u.id_usuario', $responsablesActuales)
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido'
            )
            ->orderBy('u.nombre')
            ->orderBy('u.apellido')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $barrenderos
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'id_cuadrilla' => [
                'required',
                'string',
                'max:20',
                'unique:cuadrilla,id_cuadrilla'
            ],

            'id_responsable' => [
                'required',
                'integer',
                'exists:usuarios,id_usuario'
            ],

            'turno' => [
                'required',
                Rule::in([
                    'Diurno',
                    'Nocturno'
                ])
            ],

            'estado' => [
                'required',
                Rule::in([
                    'En servicio',
                    'Regresando',
                    'En base',
                    'Esperando circuito'
                ])
            ],

            'ubicacion_actual' => [
                'required',
                'string',
                'max:100'
            ]
        ]);

        if (!$this->esBarrenderoActivo($datos['id_responsable'])) {
            return response()->json([
                'success' => false,
                'message' => 'El responsable debe ser un barrendero activo.'
            ], 422);
        }

        if ($this->esResponsableActual($datos['id_responsable'])) {
            return response()->json([
                'success' => false,
                'message' => 'El barrendero ya es responsable de otra cuadrilla.'
            ], 422);
        }

        if ($this->esIntegranteDeAlgunaCuadrilla($datos['id_responsable'])) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El responsable no puede ser integrante activo de una cuadrilla.'
            ], 422);
        }

        $cuadrilla = Cuadrilla::create($datos);
        $cuadrilla->load('responsable');

        return response()->json([
            'success' => true,
            'message' => 'Cuadrilla creada correctamente.',
            'data' => $this->formatearCuadrilla($cuadrilla)
        ], 201);
    }

    public function update(
        Request $request,
        string $id
    ) {
        $cuadrilla = Cuadrilla::findOrFail($id);

        $datos = $request->validate([
            'id_responsable' => [
                'required',
                'integer',
                'exists:usuarios,id_usuario'
            ],

            'turno' => [
                'required',
                Rule::in([
                    'Diurno',
                    'Nocturno'
                ])
            ],

            'estado' => [
                'required',
                Rule::in([
                    'En servicio',
                    'Regresando',
                    'En base',
                    'Esperando circuito'
                ])
            ],

            'ubicacion_actual' => [
                'required',
                'string',
                'max:100'
            ]
        ]);

        if (!$this->esBarrenderoActivo($datos['id_responsable'])) {
            return response()->json([
                'success' => false,
                'message' => 'El responsable debe ser un barrendero activo.'
            ], 422);
        }

        if (
            $this->esResponsableActual(
                $datos['id_responsable'],
                $id
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'El barrendero ya es responsable de otra cuadrilla.'
            ], 422);
        }

        if ($this->esIntegranteActivo($id, $datos['id_responsable'])) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El responsable no puede ser integrante de la misma cuadrilla.'
            ], 422);
        }

        if (
            $this->esIntegranteDeAlgunaCuadrilla(
                $datos['id_responsable'],
                $id
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El responsable pertenece actualmente a otra cuadrilla.'
            ], 422);
        }

        $cuadrilla->update($datos);
        $cuadrilla->load('responsable');

        return response()->json([
            'success' => true,
            'message' => 'Cuadrilla actualizada correctamente.',
            'data' => $this->formatearCuadrilla($cuadrilla)
        ]);
    }

    public function destroy(string $id)
    {
        $cuadrilla = Cuadrilla::findOrFail($id);

        $tieneIntegrantes = DB::table('cuadrilla_integrantes')
            ->where('id_cuadrilla', $id)
            ->exists();

        $tieneRutas = DB::table('cuadrilla_rutas')
            ->where('id_cuadrilla', $id)
            ->exists();

        $tieneReclamos = DB::table('reclamos')
            ->where('id_cuadrilla', $id)
            ->exists();

        $tieneIncidencias = DB::table('incidencias')
            ->where('id_cuadrilla', $id)
            ->exists();

        $tieneCamiones = DB::table('camiones')
            ->where('id_cuadrilla', $id)
            ->exists();

        $tieneAsignacionesCamion = DB::table('camion_asignaciones')
            ->where('id_cuadrilla', $id)
            ->exists();

        $tieneTareas = DB::table('tareas')
            ->where('id_cuadrilla', $id)
            ->exists();

        if (
            $tieneIntegrantes ||
            $tieneRutas ||
            $tieneReclamos ||
            $tieneIncidencias ||
            $tieneCamiones ||
            $tieneAsignacionesCamion ||
            $tieneTareas
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'La cuadrilla tiene información relacionada y no puede eliminarse.'
            ], 422);
        }

        $cuadrilla->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cuadrilla eliminada correctamente.'
        ]);
    }

    public function integrantes(string $id)
    {
        Cuadrilla::findOrFail($id);

        $integrantes = DB::table('cuadrilla_integrantes as ci')
            ->join(
                'usuarios as u',
                'ci.id_usuario',
                '=',
                'u.id_usuario'
            )
            ->where('ci.id_cuadrilla', $id)
            ->whereNull('ci.fecha_finalizacion')
            ->select(
                'u.id_usuario',
                'u.nombre',
                'u.apellido',
                'u.estado',
                'ci.fecha_asignacion'
            )
            ->orderBy('u.nombre')
            ->orderBy('u.apellido')
            ->get()
            ->map(fn ($integrante) => [
                'id_usuario' => (int) $integrante->id_usuario,
                'nombre' => $integrante->nombre,
                'apellido' => $integrante->apellido,
                'estado' => ucfirst($integrante->estado),
                'fecha_asignacion' =>
                    $this->formatearFecha($integrante->fecha_asignacion)
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $integrantes
        ]);
    }

    public function agregarIntegrante(
        Request $request,
        string $id
    ) {
        Cuadrilla::findOrFail($id);

        $datos = $request->validate([
            'id_usuario' => [
                'required',
                'integer',
                'exists:usuarios,id_usuario'
            ]
        ]);

        $idUsuario = (int) $datos['id_usuario'];

        if (!$this->esBarrenderoActivo($idUsuario)) {
            return response()->json([
                'success' => false,
                'message' => 'El integrante debe ser un barrendero activo.'
            ], 422);
        }

        if ($this->esResponsableActual($idUsuario)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Un responsable de cuadrilla no puede agregarse como integrante.'
            ], 422);
        }

        if ($this->esIntegranteDeAlgunaCuadrilla($idUsuario)) {
            return response()->json([
                'success' => false,
                'message' => 'El barrendero ya pertenece a una cuadrilla.'
            ], 422);
        }

        DB::table('cuadrilla_integrantes')->insert([
            'id_cuadrilla' => $id,
            'id_usuario' => $idUsuario,
            'fecha_asignacion' => now(),
            'fecha_finalizacion' => null
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Integrante agregado correctamente.'
        ], 201);
    }

    public function actualizarIntegrante(
        Request $request,
        string $id,
        int $idUsuario
    ) {
        Cuadrilla::findOrFail($id);

        $datos = $request->validate([
            'id_usuario' => [
                'required',
                'integer',
                'exists:usuarios,id_usuario'
            ]
        ]);

        $nuevoId = (int) $datos['id_usuario'];

        if (!$this->esIntegranteActivo($id, $idUsuario)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El integrante actual no pertenece a la cuadrilla.'
            ], 404);
        }

        if ($nuevoId === $idUsuario) {
            return response()->json([
                'success' => true,
                'message' => 'El integrante no tuvo cambios.'
            ]);
        }

        if (!$this->esBarrenderoActivo($nuevoId)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El nuevo integrante debe ser un barrendero activo.'
            ], 422);
        }

        if ($this->esResponsableActual($nuevoId)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Un responsable de cuadrilla no puede agregarse como integrante.'
            ], 422);
        }

        if ($this->esIntegranteDeAlgunaCuadrilla($nuevoId)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El nuevo barrendero ya pertenece a una cuadrilla.'
            ], 422);
        }

        DB::transaction(function () use (
            $id,
            $idUsuario,
            $nuevoId
        ) {
            DB::table('cuadrilla_integrantes')
                ->where('id_cuadrilla', $id)
                ->where('id_usuario', $idUsuario)
                ->whereNull('fecha_finalizacion')
                ->update([
                    'fecha_finalizacion' => now()
                ]);

            DB::table('cuadrilla_integrantes')->insert([
                'id_cuadrilla' => $id,
                'id_usuario' => $nuevoId,
                'fecha_asignacion' => now(),
                'fecha_finalizacion' => null
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Integrante actualizado correctamente.'
        ]);
    }

    public function quitarIntegrante(
        string $id,
        int $idUsuario
    ) {
        Cuadrilla::findOrFail($id);

        $actualizados = DB::table('cuadrilla_integrantes')
            ->where('id_cuadrilla', $id)
            ->where('id_usuario', $idUsuario)
            ->whereNull('fecha_finalizacion')
            ->update([
                'fecha_finalizacion' => now()
            ]);

        if (!$actualizados) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El integrante no pertenece actualmente a esta cuadrilla.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Integrante retirado correctamente.'
        ]);
    }

    public function rutas(string $id)
    {
        Cuadrilla::findOrFail($id);

        $rutas = DB::table('cuadrilla_rutas as cr')
            ->join(
                'rutas as r',
                'cr.id_ruta',
                '=',
                'r.id_ruta'
            )
            ->where('cr.id_cuadrilla', $id)
            ->whereNull('cr.fecha_finalizacion')
            ->select(
                'cr.id_asignacion',
                'cr.id_ruta',
                'cr.estado',
                'cr.prioridad',
                'cr.fecha_asignacion',
                'r.circuito',
                'r.municipio',
                'r.nombre'
            )
            ->orderBy('cr.fecha_asignacion')
            ->get()
            ->map(fn ($item) => [
                'id_asignacion' => (int) $item->id_asignacion,
                'id_ruta' => (int) $item->id_ruta,
                'circuito' => $item->circuito,
                'municipio' => $item->municipio,
                'nombre' => $item->nombre,
                'prioridad' => $item->prioridad,
                'estado' => $item->estado,
                'fecha_asignacion' => $item->fecha_asignacion
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rutas
        ]);
    }

    public function asignarRuta(
        Request $request,
        string $id
    ) {
        Cuadrilla::findOrFail($id);

        $datos = $request->validate([
            'id_ruta' => [
                'required',
                'integer',
                'exists:rutas,id_ruta'
            ],

            'prioridad' => [
                'required',
                Rule::in([
                    'Baja',
                    'Media',
                    'Alta'
                ])
            ]
        ]);

        $yaAsignada = DB::table('cuadrilla_rutas')
            ->where('id_cuadrilla', $id)
            ->where('id_ruta', $datos['id_ruta'])
            ->whereNull('fecha_finalizacion')
            ->exists();

        if ($yaAsignada) {
            return response()->json([
                'success' => false,
                'message' =>
                    'La ruta ya está asignada a esta cuadrilla.'
            ], 422);
        }

        $idAsignacion = DB::table('cuadrilla_rutas')
            ->insertGetId([
                'id_cuadrilla' => $id,
                'id_ruta' => $datos['id_ruta'],
                'fecha_asignacion' => now(),
                'fecha_finalizacion' => null,
                'estado' => 'Asignada',
                'prioridad' => $datos['prioridad']
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Ruta asignada correctamente.',
            'data' => [
                'id_asignacion' => (int) $idAsignacion
            ]
        ], 201);
    }

    public function actualizarRuta(
        Request $request,
        string $id,
        int $idAsignacion
    ) {
        Cuadrilla::findOrFail($id);

        $asignacion = DB::table('cuadrilla_rutas')
            ->where('id_asignacion', $idAsignacion)
            ->where('id_cuadrilla', $id)
            ->whereNull('fecha_finalizacion')
            ->first();

        if (!$asignacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Asignación de ruta no encontrada.'
            ], 404);
        }

        $datos = $request->validate([
            'prioridad' => [
                'required',
                Rule::in([
                    'Baja',
                    'Media',
                    'Alta'
                ])
            ],

            'estado' => [
                'required',
                Rule::in([
                    'Asignada',
                    'En curso',
                    'Finalizada'
                ])
            ]
        ]);

        $actualizacion = [
            'prioridad' => $datos['prioridad'],
            'estado' => $datos['estado']
        ];

        if ($datos['estado'] === 'Finalizada') {
            $actualizacion['fecha_finalizacion'] = now();
        }

        DB::table('cuadrilla_rutas')
            ->where('id_asignacion', $idAsignacion)
            ->where('id_cuadrilla', $id)
            ->update($actualizacion);

        return response()->json([
            'success' => true,
            'message' =>
                'Asignación de ruta actualizada correctamente.'
        ]);
    }

    public function quitarRuta(
        string $id,
        int $idAsignacion
    ) {
        Cuadrilla::findOrFail($id);

        $actualizados = DB::table('cuadrilla_rutas')
            ->where('id_asignacion', $idAsignacion)
            ->where('id_cuadrilla', $id)
            ->whereNull('fecha_finalizacion')
            ->update([
                'estado' => 'Finalizada',
                'fecha_finalizacion' => now()
            ]);

        if (!$actualizados) {
            return response()->json([
                'success' => false,
                'message' =>
                    'La asignación de ruta no está activa.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Ruta quitada de la cuadrilla correctamente.'
        ]);
    }

    public function historiales(string $id)
    {
        Cuadrilla::findOrFail($id);

        $limpieza = DB::table('cuadrilla_rutas as cr')
            ->join(
                'rutas as r',
                'cr.id_ruta',
                '=',
                'r.id_ruta'
            )
            ->where('cr.id_cuadrilla', $id)
            ->select(
                'r.circuito',
                'cr.prioridad',
                'cr.fecha_asignacion',
                'cr.fecha_finalizacion',
                'cr.estado'
            )
            ->orderBy('cr.fecha_asignacion')
            ->get()
            ->map(fn ($item) => [
                'circuito' => $item->circuito,
                'prioridad' => $item->prioridad,

                'asignacion' =>
                    $this->formatearFecha($item->fecha_asignacion),

                'finalizacion' =>
                    $item->fecha_finalizacion
                        ? $this->formatearFecha($item->fecha_finalizacion)
                        : 'Pendiente',

                'estado' => $item->estado
            ])
            ->values();

        $reclamos = DB::table('reclamos')
            ->where('id_cuadrilla', $id)
            ->select(
                'id_reclamo',
                'estado',
                'fecha_reporte',
                'fecha_finalizacion'
            )
            ->orderBy('fecha_reporte')
            ->get()
            ->map(fn ($item) => [
                'reclamo' => $item->id_reclamo,

                'asignacion' =>
                    $this->formatearFecha($item->fecha_reporte),

                'finalizacion' =>
                    $item->fecha_finalizacion
                        ? $this->formatearFecha($item->fecha_finalizacion)
                        : 'Pendiente',

                'estado' => $item->estado
            ])
            ->values();

        $incidenciasBD = DB::table('incidencias')
            ->where('id_cuadrilla', $id)
            ->select(
                'id_incidencia',
                'tipo',
                'estado',
                'fecha_reporte',
                'fecha_resolucion',
                'descripcion',
                'resolucion'
            )
            ->orderBy('fecha_reporte')
            ->orderBy('id_incidencia')
            ->get();

        $codigoCuadrilla = strtoupper(
            str_replace(
                [
                    '-',
                    '_'
                ],
                '',
                $id
            )
        );

        $incidencias = $incidenciasBD
            ->values()
            ->map(function ($item, $index) use ($codigoCuadrilla) {
                return [
                    'id_incidencia' =>
                        (int) $item->id_incidencia,

                    'codigo' =>
                        'INC-' .
                        $codigoCuadrilla .
                        '-' .
                        str_pad(
                            $index + 1,
                            2,
                            '0',
                            STR_PAD_LEFT
                        ),

                    'tipo' => $item->tipo,
                    'estado' => $item->estado,

                    'reporte' =>
                        $this->formatearFecha($item->fecha_reporte),

                    'resolucion' =>
                        $item->fecha_resolucion
                            ? $this->formatearFecha(
                                $item->fecha_resolucion
                            )
                            : 'Pendiente',

                    'descripcion' => $item->descripcion,

                    'detalle_resolucion' =>
                        $item->resolucion
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'limpieza' => $limpieza,
                'reclamos' => $reclamos,
                'incidencias' => $incidencias
            ]
        ]);
    }

    private function esBarrenderoActivo(
        int $idUsuario
    ): bool {
        return DB::table('usuarios as u')
            ->join(
                'rol as r',
                'u.id_rol',
                '=',
                'r.id_rol'
            )
            ->where('u.id_usuario', $idUsuario)
            ->where('u.estado', 'activo')
            ->where('r.nombre', 'barrendero')
            ->exists();
    }

    private function esResponsableActual(
        int $idUsuario,
        ?string $exceptoCuadrilla = null
    ): bool {
        $query = DB::table('cuadrilla')
            ->where('id_responsable', $idUsuario);

        if ($exceptoCuadrilla !== null) {
            $query->where(
                'id_cuadrilla',
                '<>',
                $exceptoCuadrilla
            );
        }

        return $query->exists();
    }

    private function esIntegranteActivo(
        string $idCuadrilla,
        int $idUsuario
    ): bool {
        return DB::table('cuadrilla_integrantes')
            ->where('id_cuadrilla', $idCuadrilla)
            ->where('id_usuario', $idUsuario)
            ->whereNull('fecha_finalizacion')
            ->exists();
    }

    private function esIntegranteDeAlgunaCuadrilla(
        int $idUsuario,
        ?string $exceptoCuadrilla = null
    ): bool {
        $query = DB::table('cuadrilla_integrantes')
            ->where('id_usuario', $idUsuario)
            ->whereNull('fecha_finalizacion');

        if ($exceptoCuadrilla !== null) {
            $query->where(
                'id_cuadrilla',
                '<>',
                $exceptoCuadrilla
            );
        }

        return $query->exists();
    }

    private function formatearCuadrilla(
        Cuadrilla $cuadrilla
    ): array {
        return [
            'id_cuadrilla' => $cuadrilla->id_cuadrilla,
            'id_responsable' => $cuadrilla->id_responsable,

            'responsable' =>
                $cuadrilla->responsable
                    ? trim(
                        $cuadrilla->responsable->nombre .
                        ' ' .
                        $cuadrilla->responsable->apellido
                    )
                    : null,

            'turno' => $cuadrilla->turno,
            'estado' => $cuadrilla->estado,
            'ubicacion_actual' => $cuadrilla->ubicacion_actual
        ];
    }

    private function formatearFecha(
        $fecha
    ): ?string {
        if (!$fecha) {
            return null;
        }

        return substr(
            (string) $fecha,
            0,
            10
        );
    }
}