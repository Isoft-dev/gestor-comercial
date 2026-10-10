<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends ApiController
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'correo' => ['required', 'email'],
            'contrasena' => ['required', 'string'],
        ]);

        $user = User::where('USU_Correo', $credentials['correo'])->first();

        if ($user && $this->isLocked($user)) {
            $minutes = now()->diffInMinutes($user->USU_Bloqueado_Hasta, false);
            $this->audit($request, 'ACCESO', 'BLOQUEO', $user->USU_Usuario, [
                'motivo' => 'Intento con cuenta bloqueada',
            ], $user->USU_Usuario);

            return response()->json([
                'message' => 'Cuenta bloqueada por intentos fallidos. Intente en '.$minutes.' minuto(s) o solicite el desbloqueo al administrador.',
            ], 423);
        }

        if (! $user || ! $user->USU_Estado || ! Hash::check($credentials['contrasena'], $user->USU_Contrasena)) {
            if ($user) {
                $this->registerFailure($request, $user);
            }

            return response()->json(['message' => 'Credenciales inválidas.'], 422);
        }

        if ($user->USU_Intentos_Fallidos || $user->USU_Bloqueado_Hasta) {
            $user->USU_Intentos_Fallidos = 0;
            $user->USU_Bloqueado_Hasta = null;
            $user->save();
        }

        $permisos = $this->permissionCodesForRole((int) $user->ROL_Rol);
        $user->tokens()->where('name', 'techstore-web')->delete();
        $token = $user->createToken('techstore-web', $permisos)->plainTextToken;
        $this->audit($request, 'ACCESO', 'INICIAR_SESION', $user->USU_Usuario, [], $user->USU_Usuario);

        return response()->json([
            'token' => $token,
            'usuario' => $this->profile($user, $permisos),
            'inicio' => $this->homeCounts($permisos),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function home(Request $request): JsonResponse
    {
        return response()->json($this->homeCounts(
            $this->permissionCodesForRole((int) $request->user()->ROL_Rol)
        ));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit($request, 'ACCESO', 'CERRAR_SESION', $request->user()->USU_Usuario);
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión finalizada.']);
    }

    private function isLocked(User $user): bool
    {
        return $user->USU_Bloqueado_Hasta !== null && now()->lt($user->USU_Bloqueado_Hasta);
    }

    private function registerFailure(Request $request, User $user): void
    {
        $user->USU_Intentos_Fallidos++;
        $locked = $user->USU_Intentos_Fallidos >= self::MAX_ATTEMPTS;
        if ($locked) {
            $user->USU_Bloqueado_Hasta = now()->addMinutes(self::LOCK_MINUTES);
            $user->USU_Intentos_Fallidos = 0;
        }
        $user->save();
        $this->audit($request, 'ACCESO', $locked ? 'BLOQUEO' : 'INTENTO_FALLIDO', $user->USU_Usuario, [
            'intentos' => $user->USU_Intentos_Fallidos,
        ], $user->USU_Usuario);
    }

    private function homeCounts(array $codes): array
    {
        $codes = collect($this->expandImpliedPermissions($codes));
        $counts = [];
        if ($codes->contains('USUARIOS.CONSULTAR')) {
            $counts['usuarios'] = DB::table('TEC_USUARIO')->count('USU_Usuario');
            $counts['roles'] = DB::table('TEC_ROL')->count('ROL_Rol');
        }
        if ($codes->contains('CLIENTES.CONSULTAR')) {
            $counts['clientes'] = DB::table('TEC_CLIENTE')->count('CLI_Cliente');
        }
        if ($codes->contains('CATALOGOS.CONSULTAR')) {
            $counts['productos'] = DB::table('TEC_PRODUCTO')->count('PRO_Producto');
        }

        return $counts;
    }

    private function profile(User $user, ?array $permisos = null): array
    {
        $role = DB::table('TEC_ROL')->where('ROL_Rol', $user->ROL_Rol)->value('ROL_Nombre');
        $permisos ??= $this->permissionCodesForRole((int) $user->ROL_Rol);

        return [
            'id' => $user->USU_Usuario,
            'primer_nombre' => $user->USU_Primer_Nombre,
            'segundo_nombre' => $user->USU_Segundo_Nombre,
            'primer_apellido' => $user->USU_Primer_Apellido,
            'segundo_apellido' => $user->USU_Segundo_Apellido,
            'correo' => $user->USU_Correo,
            'rol' => $role,
            'permisos' => $permisos,
        ];
    }
}
