<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InstalacionController extends Controller
{
    public function index()
    {
        $instalaciones = $this->consultaInstalaciones()
            ->orderBy('i.nombre')
            ->get()
            ->map(
                fn ($item) =>
                    $this->formatearInstalacion($item)
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => $instalaciones
        ]);
    }


    public function store(Request $request)
    {
        $this->normalizarInstalacion(
            $request
        );

        $datos =
            $this->validarInstalacion(
                $request
            );

        $id = DB::transaction(
            function () use ($datos) {
                $idUbicacion =
                    $this->obtenerOCrearUbicacion(
                        $datos
                    );

                $idInstalacion =
                    DB::table(
                        'instalaciones'
                    )->insertGetId([
                        'nombre' =>
                            $datos['nombre'],

                        'tipo' =>
                            $datos['tipo'],

                        'capacidad' =>
                            100,

                        'capacidad_actual' =>
                            $this->capacidadDesdeEstado(
                                $datos[
                                    'capacidad_estado'
                                ]
                            ),

                        'unidad_capacidad' =>
                            'porcentaje',

                        'estado' =>
                            $datos['estado'],

                        'id_ubicacion' =>
                            $idUbicacion
                    ]);

                DB::table(
                    'instalacion_tipo_residuo'
                )->insert([
                    'id_instalacion' =>
                        $idInstalacion,

                    'tipo' =>
                        $datos['tipo_residuo']
                ]);

                return $idInstalacion;
            }
        );

        return response()->json([
            'success' => true,

            'message' =>
                'Instalación creada correctamente.',

            'data' =>
                $this->formatearInstalacion(
                    $this->obtenerInstalacion(
                        $id
                    )
                )
        ], 201);
    }


    public function update(
        Request $request,
        int $id
    ) {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        $this->normalizarInstalacion(
            $request
        );

        $datos =
            $this->validarInstalacion(
                $request
            );

        DB::transaction(
            function () use (
                $id,
                $datos
            ) {
                $idUbicacion =
                    $this->obtenerOCrearUbicacion(
                        $datos
                    );

                DB::table(
                    'instalaciones'
                )
                    ->where(
                        'id_instalacion',
                        $id
                    )
                    ->update([
                        'nombre' =>
                            $datos['nombre'],

                        'tipo' =>
                            $datos['tipo'],

                        'capacidad' =>
                            100,

                        'capacidad_actual' =>
                            $this->capacidadDesdeEstado(
                                $datos[
                                    'capacidad_estado'
                                ]
                            ),

                        'unidad_capacidad' =>
                            'porcentaje',

                        'estado' =>
                            $datos['estado'],

                        'id_ubicacion' =>
                            $idUbicacion
                    ]);

                DB::table(
                    'instalacion_tipo_residuo'
                )
                    ->where(
                        'id_instalacion',
                        $id
                    )
                    ->delete();

                DB::table(
                    'instalacion_tipo_residuo'
                )->insert([
                    'id_instalacion' =>
                        $id,

                    'tipo' =>
                        $datos['tipo_residuo']
                ]);
            }
        );

        return response()->json([
            'success' => true,

            'message' =>
                'Instalación actualizada correctamente.',

            'data' =>
                $this->formatearInstalacion(
                    $this->obtenerInstalacion(
                        $id
                    )
                )
        ]);
    }


    public function destroy(int $id)
    {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        DB::transaction(
            function () use ($id) {
                DB::table(
                    'instalacion_tipo_residuo'
                )
                    ->where(
                        'id_instalacion',
                        $id
                    )
                    ->delete();

                DB::table(
                    'instalaciones'
                )
                    ->where(
                        'id_instalacion',
                        $id
                    )
                    ->delete();
            }
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Instalación eliminada correctamente.'
        ]);
    }


    public function inventario(
        Request $request,
        int $id
    ) {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        if (
            $instalacion->tipo !==
            'Depósito'
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El inventario solo está disponible para depósitos.'
            ], 422);
        }

        $tipo = trim(
            (string)
            $request->query(
                'tipo',
                ''
            )
        );

        $tiposPermitidos = [
            'Limpieza',
            'Seguridad',
            'Equipamiento',
            'Mantenimiento',
            'Insumo'
        ];

        if (
            $tipo !== '' &&
            !in_array(
                $tipo,
                $tiposPermitidos,
                true
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El tipo de artículo no es válido.'
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' =>
                $this->obtenerInventario(
                    $id,
                    $tipo
                )
        ]);
    }


    public function agregarInventario(
        Request $request,
        int $id
    ) {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        if (
            $instalacion->tipo !==
            'Depósito'
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El inventario solo está disponible para depósitos.'
            ], 422);
        }

        $datos =
            $this->validarInventario(
                $request
            );

        $idInventario =
            DB::table(
                'inventario'
            )->insertGetId([
                'id_instalacion' =>
                    $id,

                'nombre_item' =>
                    $datos['nombre_item'],

                'estado' =>
                    $datos['estado'],

                'tipo' =>
                    $datos['tipo'],

                'cantidad' =>
                    $datos['cantidad']
            ]);

        return response()->json([
            'success' => true,

            'message' =>
                'Artículo agregado correctamente.',

            'data' =>
                $this->obtenerItemInventario(
                    $id,
                    $idInventario
                )
        ], 201);
    }


    public function actualizarInventario(
        Request $request,
        int $id,
        int $idInventario
    ) {
        $item =
            $this->obtenerItemInventario(
                $id,
                $idInventario
            );

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Artículo no encontrado.'
            ], 404);
        }

        $datos =
            $this->validarInventario(
                $request
            );

        DB::table(
            'inventario'
        )
            ->where(
                'id_inventario',
                $idInventario
            )
            ->where(
                'id_instalacion',
                $id
            )
            ->update([
                'nombre_item' =>
                    $datos['nombre_item'],

                'estado' =>
                    $datos['estado'],

                'tipo' =>
                    $datos['tipo'],

                'cantidad' =>
                    $datos['cantidad']
            ]);

        return response()->json([
            'success' => true,

            'message' =>
                'Artículo actualizado correctamente.',

            'data' =>
                $this->obtenerItemInventario(
                    $id,
                    $idInventario
                )
        ]);
    }


    public function eliminarInventario(
        int $id,
        int $idInventario
    ) {
        $item =
            $this->obtenerItemInventario(
                $id,
                $idInventario
            );

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Artículo no encontrado.'
            ], 404);
        }

        DB::table(
            'inventario'
        )
            ->where(
                'id_inventario',
                $idInventario
            )
            ->where(
                'id_instalacion',
                $id
            )
            ->delete();

        return response()->json([
            'success' => true,
            'message' =>
                'Artículo eliminado correctamente.'
        ]);
    }


    public function operarios(int $id)
    {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        $operarios =
            DB::table(
                'instalacion_operario as io'
            )
                ->join(
                    'usuarios as u',
                    'io.id_usuario',
                    '=',
                    'u.id_usuario'
                )
                ->leftJoin(
                    'datos as d',
                    'u.id_usuario',
                    '=',
                    'd.id_usuario'
                )
                ->where(
                    'io.id_instalacion',
                    $id
                )
                ->whereNull(
                    'io.fecha_finalizacion'
                )
                ->select(
                    'io.id_asignacion',
                    'u.id_usuario',
                    'u.nombre',
                    'u.apellido',
                    'u.estado',
                    'd.documento',
                    'd.telefono',
                    'd.email',
                    'io.fecha_asignacion'
                )
                ->orderBy(
                    'u.apellido'
                )
                ->orderBy(
                    'u.nombre'
                )
                ->get();

        return response()->json([
            'success' => true,
            'data' => $operarios
        ]);
    }


    public function operariosDisponibles(
        int $id
    ) {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        $operarios =
            DB::table(
                'operario as o'
            )
                ->join(
                    'usuarios as u',
                    'o.id_usuario',
                    '=',
                    'u.id_usuario'
                )
                ->leftJoin(
                    'datos as d',
                    'u.id_usuario',
                    '=',
                    'd.id_usuario'
                )
                ->where(
                    'u.estado',
                    'activo'
                )
                ->whereNotExists(
                    function ($query) {
                        $query
                            ->select(
                                DB::raw(1)
                            )
                            ->from(
                                'instalacion_operario as io'
                            )
                            ->whereColumn(
                                'io.id_usuario',
                                'o.id_usuario'
                            )
                            ->whereNull(
                                'io.fecha_finalizacion'
                            );
                    }
                )
                ->select(
                    'u.id_usuario',
                    'u.nombre',
                    'u.apellido',
                    'd.documento',
                    'd.telefono',
                    'd.email'
                )
                ->orderBy(
                    'u.apellido'
                )
                ->orderBy(
                    'u.nombre'
                )
                ->get();

        return response()->json([
            'success' => true,
            'data' => $operarios
        ]);
    }


    public function asignarOperario(
        Request $request,
        int $id
    ) {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        $datos =
            $request->validate([
                'id_usuario' => [
                    'required',
                    'integer',
                    'exists:operario,id_usuario'
                ]
            ]);

        $usuarioActivo =
            DB::table('usuarios')
                ->where(
                    'id_usuario',
                    $datos['id_usuario']
                )
                ->where(
                    'estado',
                    'activo'
                )
                ->exists();

        if (!$usuarioActivo) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El operario seleccionado no está activo.'
            ], 422);
        }

        $asignacionActiva =
            DB::table(
                'instalacion_operario'
            )
                ->where(
                    'id_usuario',
                    $datos['id_usuario']
                )
                ->whereNull(
                    'fecha_finalizacion'
                )
                ->exists();

        if ($asignacionActiva) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El operario ya está asignado a una instalación.'
            ], 422);
        }

        DB::table(
            'instalacion_operario'
        )->insert([
            'id_usuario' =>
                $datos['id_usuario'],

            'id_instalacion' =>
                $id,

            'fecha_asignacion' =>
                now(),

            'fecha_finalizacion' =>
                null
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Operario asignado correctamente.'
        ], 201);
    }


    public function actualizarOperario(
        Request $request,
        int $id,
        int $idUsuario
    ) {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        $asignacion =
            DB::table(
                'instalacion_operario'
            )
                ->where(
                    'id_instalacion',
                    $id
                )
                ->where(
                    'id_usuario',
                    $idUsuario
                )
                ->whereNull(
                    'fecha_finalizacion'
                )
                ->first();

        if (!$asignacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El operario no está asignado a esta instalación.'
            ], 404);
        }

        $datos =
            $request->validate([
                'id_usuario' => [
                    'required',
                    'integer',
                    'exists:operario,id_usuario'
                ]
            ]);

        $idUsuarioNuevo =
            (int)
            $datos['id_usuario'];

        if (
            $idUsuarioNuevo ===
            $idUsuario
        ) {
            return response()->json([
                'success' => true,
                'message' =>
                    'La asignación no tuvo cambios.'
            ]);
        }

        $usuarioActivo =
            DB::table(
                'usuarios'
            )
                ->where(
                    'id_usuario',
                    $idUsuarioNuevo
                )
                ->where(
                    'estado',
                    'activo'
                )
                ->exists();

        if (!$usuarioActivo) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El operario seleccionado no está activo.'
            ], 422);
        }

        $asignacionActiva =
            DB::table(
                'instalacion_operario'
            )
                ->where(
                    'id_usuario',
                    $idUsuarioNuevo
                )
                ->whereNull(
                    'fecha_finalizacion'
                )
                ->exists();

        if ($asignacionActiva) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El operario seleccionado ya está asignado a una instalación.'
            ], 422);
        }

        DB::transaction(
            function () use (
                $asignacion,
                $id,
                $idUsuarioNuevo
            ) {
                DB::table(
                    'instalacion_operario'
                )
                    ->where(
                        'id_asignacion',
                        $asignacion
                            ->id_asignacion
                    )
                    ->update([
                        'fecha_finalizacion' =>
                            now()
                    ]);

                DB::table(
                    'instalacion_operario'
                )->insert([
                    'id_usuario' =>
                        $idUsuarioNuevo,

                    'id_instalacion' =>
                        $id,

                    'fecha_asignacion' =>
                        now(),

                    'fecha_finalizacion' =>
                        null
                ]);
            }
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Asignación actualizada correctamente.'
        ]);
    }


    public function quitarOperario(
        int $id,
        int $idUsuario
    ) {
        $instalacion =
            $this->obtenerInstalacion(
                $id
            );

        if (!$instalacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Instalación no encontrada.'
            ], 404);
        }

        $asignacion =
            DB::table(
                'instalacion_operario'
            )
                ->where(
                    'id_instalacion',
                    $id
                )
                ->where(
                    'id_usuario',
                    $idUsuario
                )
                ->whereNull(
                    'fecha_finalizacion'
                )
                ->first();

        if (!$asignacion) {
            return response()->json([
                'success' => false,
                'message' =>
                    'El operario no está asignado a esta instalación.'
            ], 404);
        }

        DB::table(
            'instalacion_operario'
        )
            ->where(
                'id_asignacion',
                $asignacion->id_asignacion
            )
            ->update([
                'fecha_finalizacion' =>
                    now()
            ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Operario quitado de la instalación correctamente.'
        ]);
    }


    private function validarInstalacion(
        Request $request
    ): array {
        return $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100'
            ],

            'tipo' => [
                'required',
                Rule::in([
                    'Depósito',
                    'Centro de acopio',
                    'Vertedero'
                ])
            ],

            'tipo_residuo' => [
                'required',
                Rule::in([
                    'general',
                    'reciclaje'
                ])
            ],

            'estado' => [
                'required',
                Rule::in([
                    'Operativo',
                    'No operativo'
                ])
            ],

            'calle' => [
                'required',
                'string',
                'max:150'
            ],

            'esquina_1' => [
                'nullable',
                'string',
                'max:150'
            ],

            'esquina_2' => [
                'nullable',
                'string',
                'max:150'
            ],

            'capacidad_estado' => [
                'required',
                Rule::in([
                    'Disponible',
                    'Casi lleno',
                    'Lleno'
                ])
            ]
        ]);
    }


    private function validarInventario(
        Request $request
    ): array {
        return $request->validate([
            'nombre_item' => [
                'required',
                'string',
                'max:100'
            ],

            'estado' => [
                'required',
                Rule::in([
                    'Disponible',
                    'Excelente',
                    'Óptimo',
                    'Carencia',
                    'Fuera de servicio'
                ])
            ],

            'tipo' => [
                'required',
                Rule::in([
                    'Limpieza',
                    'Seguridad',
                    'Equipamiento',
                    'Mantenimiento',
                    'Insumo'
                ])
            ],

            'cantidad' => [
                'required',
                'integer',
                'min:0'
            ]
        ]);
    }


    private function normalizarInstalacion(
        Request $request
    ): void {
        $datos = [];

        if (
            $request->has('nombre')
        ) {
            $datos['nombre'] =
                trim(
                    (string)
                    $request->nombre
                );
        }

        if (
            $request->has('tipo')
        ) {
            $tipo =
                mb_strtolower(
                    trim(
                        (string)
                        $request->tipo
                    )
                );

            $datos['tipo'] =
                match ($tipo) {
                    'depósito',
                    'deposito' =>
                        'Depósito',

                    'centro de acopio' =>
                        'Centro de acopio',

                    'vertedero' =>
                        'Vertedero',

                    default =>
                        trim(
                            (string)
                            $request->tipo
                        )
                };
        }

        if (
            $request->has(
                'tipo_residuo'
            )
        ) {
            $tipoResiduo =
                mb_strtolower(
                    trim(
                        (string)
                        $request->tipo_residuo
                    )
                );

            $datos['tipo_residuo'] =
                match ($tipoResiduo) {
                    'general' =>
                        'general',

                    'reciclaje' =>
                        'reciclaje',

                    default =>
                        $tipoResiduo
                };
        }

        if (
            $request->has('estado')
        ) {
            $estado =
                mb_strtolower(
                    trim(
                        (string)
                        $request->estado
                    )
                );

            $datos['estado'] =
                match ($estado) {
                    'operativo' =>
                        'Operativo',

                    'no operativo' =>
                        'No operativo',

                    default =>
                        trim(
                            (string)
                            $request->estado
                        )
                };
        }

        if (
            $request->has(
                'capacidad_estado'
            )
        ) {
            $capacidad =
                mb_strtolower(
                    trim(
                        (string)
                        $request
                            ->capacidad_estado
                    )
                );

            $datos[
                'capacidad_estado'
            ] =
                match ($capacidad) {
                    'disponible' =>
                        'Disponible',

                    'casi lleno' =>
                        'Casi lleno',

                    'lleno' =>
                        'Lleno',

                    default =>
                        trim(
                            (string)
                            $request
                                ->capacidad_estado
                        )
                };
        }

        $request->merge(
            $datos
        );
    }


    private function obtenerOCrearUbicacion(
        array $datos
    ): int {
        $calle =
            trim(
                $datos['calle']
            );

        $esquina1 =
            !empty(
                $datos['esquina_1']
            )
                ? trim(
                    $datos['esquina_1']
                )
                : null;

        $esquina2 =
            !empty(
                $datos['esquina_2']
            )
                ? trim(
                    $datos['esquina_2']
                )
                : null;

        $existente =
            DB::table(
                'ubicacion'
            )
                ->where(
                    'calle',
                    $calle
                )
                ->where(
                    'esquina_1',
                    $esquina1
                )
                ->where(
                    'esquina_2',
                    $esquina2
                )
                ->first();

        if ($existente) {
            return (int)
                $existente
                    ->id_ubicacion;
        }

        return DB::table(
            'ubicacion'
        )->insertGetId([
            'calle' =>
                $calle,

            'esquina_1' =>
                $esquina1,

            'esquina_2' =>
                $esquina2,

            'municipio' =>
                null,

            'barrio' =>
                null,

            'descripcion' =>
                null,

            'latitud' =>
                null,

            'longitud' =>
                null
        ]);
    }


    private function consultaInstalaciones()
    {
        return DB::table(
            'instalaciones as i'
        )
            ->leftJoin(
                'ubicacion as u',
                'i.id_ubicacion',
                '=',
                'u.id_ubicacion'
            )
            ->leftJoin(
                'instalacion_tipo_residuo as itr',
                'i.id_instalacion',
                '=',
                'itr.id_instalacion'
            )
            ->select(
                'i.id_instalacion',
                'i.nombre',
                'i.tipo',
                'itr.tipo as tipo_residuo',
                'i.capacidad',
                'i.capacidad_actual',
                'i.unidad_capacidad',
                'i.estado',
                'i.id_ubicacion',
                'u.calle',
                'u.esquina_1',
                'u.esquina_2'
            );
    }


    private function obtenerInstalacion(
        int $id
    ) {
        return $this
            ->consultaInstalaciones()
            ->where(
                'i.id_instalacion',
                $id
            )
            ->first();
    }


    private function formatearInstalacion(
        $item
    ): array {
        return [
            'id_instalacion' =>
                (int)
                $item->id_instalacion,

            'nombre' =>
                $item->nombre,

            'tipo' =>
                $item->tipo,

            'tipo_residuo' =>
                $item->tipo_residuo,

            'estado' =>
                $item->estado,

            'calle' =>
                $item->calle,

            'esquina_1' =>
                $item->esquina_1,

            'esquina_2' =>
                $item->esquina_2,

            'capacidad' =>
                (float)
                $item->capacidad,

            'capacidad_actual' =>
                (float)
                $item
                    ->capacidad_actual,

            'capacidad_estado' =>
                $this->estadoDesdeCapacidad(
                    (float)
                    $item
                        ->capacidad_actual
                ),

            'unidad_capacidad' =>
                $item
                    ->unidad_capacidad
        ];
    }


    private function capacidadDesdeEstado(
        string $estado
    ): float {
        return match ($estado) {
            'Disponible' =>
                50,

            'Casi lleno' =>
                85,

            'Lleno' =>
                100
        };
    }


    private function estadoDesdeCapacidad(
        float $capacidad
    ): string {
        if (
            $capacidad >= 100
        ) {
            return 'Lleno';
        }

        if (
            $capacidad >= 85
        ) {
            return 'Casi lleno';
        }

        return 'Disponible';
    }


    private function obtenerInventario(
        int $id,
        string $tipo = ''
    ) {
        $query =
            DB::table(
                'inventario'
            )
                ->where(
                    'id_instalacion',
                    $id
                );

        if ($tipo !== '') {
            $query->where(
                'tipo',
                $tipo
            );
        }

        return $query
            ->select(
                'id_inventario',
                'id_instalacion',
                'nombre_item',
                'estado',
                'tipo',
                'cantidad'
            )
            ->orderBy(
                'nombre_item'
            )
            ->get();
    }


    private function obtenerItemInventario(
        int $id,
        int $idInventario
    ) {
        return DB::table(
            'inventario'
        )
            ->where(
                'id_instalacion',
                $id
            )
            ->where(
                'id_inventario',
                $idInventario
            )
            ->select(
                'id_inventario',
                'id_instalacion',
                'nombre_item',
                'estado',
                'tipo',
                'cantidad'
            )
            ->first();
    }
}