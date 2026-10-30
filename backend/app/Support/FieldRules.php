<?php

namespace App\Support;

class FieldRules
{
    public const PERSON = '/^[\p{L}]+(?:[ \-][\p{L}]+)*$/u';

    public const CATALOG = '/^[\p{L}0-9]+(?:[ \-][\p{L}0-9]+)*$/u';

    public const COMPANY = '/^[\p{L}0-9]+(?:[\s.,\-]+[\p{L}0-9]+)*\.?$/u';

    public const CODE = '/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/';

    public const PHONE = '/^[2-7][0-9]{7}$/';

    public const DPI = '/^[0-9]{13}$/';

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

    public static function dpi(bool $required = true): array
    {
        return self::pattern($required, 13, self::DPI);
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
            'identificacion.regex' => 'El DPI debe tener exactamente 13 dígitos.',
            'identificacion.max' => 'El DPI debe tener exactamente 13 dígitos.',
            'identificacion_fiscal.regex' => 'La identificación fiscal solo admite letras, números y guiones.',
            'razon_social.regex' => 'La razón social no admite signos especiales.',
            'contacto.regex' => 'El contacto solo admite letras y espacios.',
            'telefono.max' => 'El teléfono debe tener 8 dígitos.',
            'telefono.regex' => 'El teléfono debe tener 8 dígitos y comenzar con 2, 3, 4, 5, 6 o 7.',
            'nit.regex' => 'El NIT admite máximo 8 dígitos (el último puede ser K) o CF.',
            'nit.max' => 'El NIT admite máximo 8 dígitos (el último puede ser K) o CF.',
            'monto.decimal' => 'El monto admite como máximo 2 decimales.',
            'correo.email' => 'El correo debe tener un formato válido, por ejemplo nombre@dominio.com.',
            'correo.regex' => 'El correo debe tener un formato válido, por ejemplo nombre@dominio.com.',
            'correo.unique' => 'El correo ya está registrado.',
            'sku.unique' => 'El SKU ya está registrado.',
            'identificacion.unique' => 'El DPI ya está registrado.',
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
