<?php

use App\Http\Controllers\RecoleccionController;
use Illuminate\Support\Facades\Route;

// Rutas públicas y operativas de la API de Recolección (Puerto 8002)
Route::get('recolecciones', [RecoleccionController::class, 'index']);
Route::get('chofer/ruta', [RecoleccionController::class, 'rutaDeChofer']);
Route::post('recolecciones/iniciar', [RecoleccionController::class, 'iniciarRecoleccion']);
Route::post('recolecciones/{id}/contenedor', [RecoleccionController::class, 'recolectarContenedor'])->whereNumber('id');
Route::post('recolecciones/{id}/finalizar', [RecoleccionController::class, 'finalizarRecoleccion'])->whereNumber('id');
Route::put('camiones/{id}/gps', [RecoleccionController::class, 'actualizarGpsCamion'])->whereNumber('id');
