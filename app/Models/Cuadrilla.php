<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cuadrilla extends Model
{
    protected $table = 'cuadrilla';
    protected $primaryKey = 'id_cuadrilla';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id_cuadrilla',
        'id_responsable',
        'turno',
        'estado',
        'ubicacion_actual'
    ];

    public function responsable()
    {
        return $this->belongsTo(
            Usuario::class,
            'id_responsable',
            'id_usuario'
        );
    }
}