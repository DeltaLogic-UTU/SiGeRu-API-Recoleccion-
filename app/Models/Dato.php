<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dato extends Model
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
}
