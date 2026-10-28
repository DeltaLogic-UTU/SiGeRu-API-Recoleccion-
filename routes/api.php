<?php

use App\Http\Controllers\RecoleccionController;
use Illuminate\Support\Facades\Route;

// Ruta pública para verificar estado del servidor
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'SiGeRu-API-Recoleccion',
        'port' => 8002,
    ]);
});

// Rutas protegidas con JWT (requieren Header Authorization: Bearer <token>)
Route::middleware('auth:api')->group(function () {
    Route::get('recolecciones', [RecoleccionController::class, 'index']);
    Route::get('chofer/ruta', [RecoleccionController::class, 'rutaDeChofer']);
    Route::post('recolecciones/iniciar', [RecoleccionController::class, 'iniciarRecoleccion']);
    Route::post('recolecciones/{id}/contenedor', [RecoleccionController::class, 'recolectarContenedor'])->whereNumber('id');
    Route::post('recolecciones/{id}/finalizar', [RecoleccionController::class, 'finalizarRecoleccion'])->whereNumber('id');
    Route::put('camiones/{id}/gps', [RecoleccionController::class, 'actualizarGpsCamion'])->whereNumber('id');
});