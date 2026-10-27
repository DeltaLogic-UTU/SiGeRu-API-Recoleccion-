<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Modelo de la tabla 'rol' para conocer el perfil del usuario autenticado con JWT.
class Rol extends Model
{
    protected $table = 'rol';

    protected $primaryKey = 'id_rol';

    public $timestamps = false;

    protected $fillable = [
        'nombre'
    ];
}

