<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'TEC_USUARIO';

    protected $primaryKey = 'USU_Usuario';

    protected $fillable = [
        'ROL_Rol',
        'USU_Primer_Nombre',
        'USU_Segundo_Nombre',
        'USU_Primer_Apellido',
        'USU_Segundo_Apellido',
        'USU_Correo',
        'USU_Contrasena',
        'USU_Estado',
        'USU_Intentos_Fallidos',
        'USU_Bloqueado_Hasta',
    ];

    protected $hidden = [
        'USU_Contrasena',
        'remember_token',
    ];

    public function getAuthPassword(): string
    {
        return $this->USU_Contrasena;
    }

    protected function casts(): array
    {
        return [
            'USU_Contrasena' => 'hashed',
            'USU_Estado' => 'boolean',
            'USU_Bloqueado_Hasta' => 'datetime',
        ];
    }
}
