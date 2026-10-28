<?php

namespace App\Models;

//se importa librerias authenticable de laravel y jwt 
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Model;


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

    //  Devuelve la clave primaria (id_datos) que viaja dentro del token JWT
public function getJWTIdentifier()
{
    return $this->getKey();
}

 //  Datos personalizados que viajan en el cuerpo (payload) del token JWT

 public function getJWTCustomClaims(): array
 {
    $this->loadMissing('usuario.rol');

    return [
        'id_usuario'=> $this->id_usuario,
        'rol'=> $this->usuario->rol->nombre,
    ];
 }

}

