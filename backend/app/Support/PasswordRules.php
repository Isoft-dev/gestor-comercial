<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function required(): array
    {
        return [
            'required',
            'string',
            Password::min(10)->mixedCase()->numbers()->symbols(),
        ];
    }

    public static function message(): string
    {
        return 'La contraseña debe tener al menos 10 caracteres, una mayúscula, una minúscula, un número y un símbolo.';
    }
}
