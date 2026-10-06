<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'ROL_Rol' => 1,
            'USU_Primer_Nombre' => fake()->firstName(),
            'USU_Primer_Apellido' => fake()->lastName(),
            'USU_Correo' => fake()->unique()->safeEmail(),
            'USU_Contrasena' => static::$password ??= Hash::make('password'),
            'USU_Estado' => true,
        ];
    }
}
