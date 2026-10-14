<?php

namespace App\Support;

class FieldRules
{
    public const PERSON = '/^[\p{L}]+(?:[ \-][\p{L}]+)*$/u';

    public const CATALOG = '/^[\p{L}0-9]+(?:[ \-][\p{L}0-9]+)*$/u';

    public const COMPANY = '/^[\p{L}0-9]+(?:[\s.,\-]+[\p{L}0-9]+)*\.?$/u';

    public const CODE = '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/';

    public const PHONE = '/^[0-9]{1,8}$/';

    public const EMAIL = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    public static function personName(bool $required = true, int $max = 60): array
    {
        return self::pattern($required, $max, self::PERSON);
    }

    public static function catalogName(bool $required = true, int $max = 80): array
    {
        return self::pattern($required, $max, self::CATALOG);
    }

    public static function companyName(bool $required = true, int $max = 120): array
    {
        return self::pattern($required, $max, self::COMPANY);
    }

    public static function identification(bool $required = true, int $max = 30): array
    {
        return self::pattern($required, $max, self::CODE);
    }

    public static function sku(bool $required = true, int $max = 40): array
    {
        return self::pattern($required, $max, self::CODE);
    }

    public static function phone(bool $required = false, int $max = 8): array
    {
        return self::pattern($required, $max, self::PHONE);
    }

    public static function email(bool $required = false, int $max = 120): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            "max:{$max}",
            'email',
            'regex:'.self::EMAIL,
        ];
    }

    public static function roleName(bool $required = true, int $max = 30): array
    {
        return self::pattern($required, $max, self::PERSON);
    }

    public static function text(bool $required = false, int $max = 1000): array
    {
        return self::pattern($required, $max, self::COMPANY);
    }

    public static function messages(): array
    {
        return [
            'primer_nombre.regex' => 'El nombre solo admite letras y espacios.',
            'segundo_nombre.regex' => 'El nombre solo admite letras y espacios.',
            'primer_apellido.regex' => 'El apellido solo admite letras y espacios.',
            'segundo_apellido.regex' => 'El apellido solo admite letras y espacios.',
            'nombre.regex' => 'El nombre solo admite letras, números y espacios.',
            'sku.regex' => 'El SKU solo admite letras, números y guiones.',
            'identificacion.regex' => 'La identificación solo admite letras, números y guiones.',
            'identificacion_fiscal.regex' => 'La identificación fiscal solo admite letras, números y guiones.',
            'razon_social.regex' => 'La razón social no admite signos especiales.',
            'contacto.regex' => 'El contacto solo admite letras y espacios.',
            'telefono.max' => 'El teléfono no puede tener más de 8 caracteres.',
            'telefono.regex' => 'El teléfono solo admite hasta 8 números.',
            'correo.email' => 'El correo debe tener un formato válido, por ejemplo nombre@dominio.com.',
            'correo.regex' => 'El correo debe tener un formato válido, por ejemplo nombre@dominio.com.',
            'correo.unique' => 'El correo ya está registrado.',
            'sku.unique' => 'El SKU ya está registrado.',
            'identificacion.unique' => 'La identificación ya está registrada.',
            'identificacion_fiscal.unique' => 'La identificación fiscal ya está registrada.',
            'nombre.unique' => 'Ese nombre ya está registrado.',
            'descripcion.regex' => 'La descripción no admite signos especiales.',
        ];
    }

    private static function pattern(bool $required, int $max, string $regex): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            "max:{$max}",
            'regex:'.$regex,
        ];
    }
}
