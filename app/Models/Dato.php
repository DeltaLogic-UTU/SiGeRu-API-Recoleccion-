<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

// Modelo de credenciales ('datos') que implementa JWTSubject para validar tokens JWT.
class Dato extends Authenticatable implements JWTSubject
{
    protected $table = 'datos';

    protected $primaryKey = 'id_datos';

    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'password',
        'documento',
        'telefono',
        'email'
    ];

    protected $hidden = [
        'password'
    ];

    public function usuario()
    {
        return $this->belongsTo(
            Usuario::class,
            'id_usuario',
            'id_usuario'
        );
    }

    // Devuelve el identificador único que viaja dentro del token JWT.
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    // Datos personalizados que se incluyen en el payload del token JWT.
    public function getJWTCustomClaims(): array
    {
        $this->loadMissing('usuario.rol');

        return [
            'id_usuario' => $this->id_usuario,
            'rol' => $this->usuario?->rol?->nombre,
        ];
    }
}

