<?php

namespace App\Support;

class Billing
{
    public static function rules(): array
    {
        return [
            'nit' => ['nullable', 'string', 'max:8', 'regex:/^(?:CF|[0-9]{1,7}[0-9K])$/i'],
            'razon_social' => ['nullable', 'string', 'max:160'],
            'direccion_fiscal' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function clientData(array $data): array
    {
        return [
            'CLI_NIT' => self::clean($data['nit'] ?? null, true) ?? 'CF',
            'CLI_Razon_Social' => self::clean($data['razon_social'] ?? null),
            'CLI_Direccion_Fiscal' => self::clean($data['direccion_fiscal'] ?? null),
        ];
    }

    /**
     * Datos del cliente para la factura; NIT, razón social y dirección fiscal son opcionales.
     */
    public static function invoiceClient(object $client): ?array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
            $client->CLI_Primer_Nombre,
            $client->CLI_Segundo_Nombre ?? null,
            $client->CLI_Primer_Apellido,
            $client->CLI_Segundo_Apellido ?? null,
        ]))));
        if ($fullName === '' || ! $client->CLI_Correo || ! $client->CLI_Telefono) {
            return null;
        }

        return [
            'nit' => $client->CLI_NIT ?: 'CF',
            'nombre' => $fullName,
            'razon_social' => $client->CLI_Razon_Social ?: $fullName,
            'direccion_fiscal' => $client->CLI_Direccion_Fiscal ?: 'Ciudad',
            'correo' => $client->CLI_Correo,
            'telefono' => $client->CLI_Telefono,
        ];
    }

    private static function clean(?string $value, bool $upper = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $upper ? strtoupper($value) : $value;
    }
}
