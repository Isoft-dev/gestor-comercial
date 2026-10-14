<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SafeErrorResponse
{
    public static function finalize(Response $response, Throwable $exception, Request $request): Response
    {
        if ($exception instanceof ValidationException || $response instanceof RedirectResponse) {
            return $response;
        }

        [$status, $message, $errors] = self::describe($exception, $response->getStatusCode());

        if (self::wantsJson($request)) {
            $payload = ['message' => $message];
            if ($errors !== []) {
                $payload['errors'] = $errors;
            }

            return new JsonResponse($payload, $status);
        }

        if ($status < 500 && ! self::isTechnical((string) $exception->getMessage()) && $exception instanceof HttpExceptionInterface) {
            return $response;
        }

        return response()->view('errors.user', [
            'message' => $message,
            'status' => $status,
        ], $status);
    }

    public static function describe(Throwable $exception, int $fallbackStatus = 500): array
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            [$field, $message] = self::uniqueConflict($exception);

            return [422, $message, [$field => [$message]]];
        }

        if ($exception instanceof AuthenticationException) {
            return [401, 'Debe iniciar sesión para continuar.', []];
        }

        if (self::isTimeout($exception)) {
            return [504, 'La operación tardó demasiado. Intente de nuevo.', []];
        }

        if (self::isDatabaseUnavailable($exception)) {
            return [503, 'No hay conexión con la base de datos. Intente de nuevo en un momento.', []];
        }

        if ($exception instanceof QueryException) {
            return [500, 'No se pudo guardar la información. Verifique los datos e intente de nuevo.', []];
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $message = $exception->getMessage();
            if (! self::isTechnical($message)) {
                return [$status, $message, []];
            }

            return [$status, self::genericMessage($status), []];
        }

        $status = $fallbackStatus >= 400 ? $fallbackStatus : 500;

        return [$status, self::genericMessage($status), []];
    }

    private static function uniqueConflict(UniqueConstraintViolationException $exception): array
    {
        $haystack = strtolower(implode(' ', array_filter([
            $exception->index,
            implode(' ', $exception->columns),
            $exception->getMessage(),
        ])));

        foreach ([
            'correo' => ['correo', 'El correo ya está registrado.'],
            'sku' => ['sku', 'El SKU ya está registrado.'],
            'identificacion_fiscal' => ['identificacion_fiscal', 'La identificación fiscal ya está registrada.'],
            'identificacion' => ['identificacion', 'La identificación ya está registrada.'],
            'rol_nombre' => ['nombre', 'Ese nombre de rol ya está registrado.'],
            'nombre' => ['nombre', 'Ese nombre ya está registrado.'],
        ] as $needle => [$field, $message]) {
            if (str_contains($haystack, $needle)) {
                return [$field, $message];
            }
        }

        return ['registro', 'El registro ya existe. Verifique los datos e intente de nuevo.'];
    }

    private static function isTimeout(Throwable $exception): bool
    {
        return $exception instanceof FatalError
            || str_contains(strtolower($exception->getMessage()), 'maximum execution time');
    }

    private static function isDatabaseUnavailable(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'sqlstate[hy000] [2002]')
            || str_contains($message, 'sqlstate[hy000] [2006]')
            || str_contains($message, 'sqlstate[hy000] [2013]')
            || str_contains($message, 'refused')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'no se puede establecer una conexión');
    }

    private static function isTechnical(string $message): bool
    {
        $message = trim($message);

        return $message === ''
            || $message === 'Server Error'
            || strlen($message) > 220
            || (bool) preg_match('/SQLSTATE|stack trace|Whoops|Maximum execution|Illuminate\\\\|vendor\\\\|Connection: mysql|file_put_contents|Fatal error|PDOException/i', $message);
    }

    private static function genericMessage(int $status): string
    {
        return match (true) {
            $status === 401 => 'Debe iniciar sesión para continuar.',
            $status === 403 => 'No tiene permiso para esta operación.',
            $status === 404 => 'No se encontró el registro solicitado.',
            $status === 419 => 'La sesión expiró. Recargue e intente de nuevo.',
            $status === 503, $status === 504 => 'El servicio no está disponible. Intente de nuevo en un momento.',
            default => 'Ocurrió un problema. Intente de nuevo.',
        };
    }

    private static function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson() || $request->isJson();
    }
}
