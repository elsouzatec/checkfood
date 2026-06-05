<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de controle de role.
 *
 * Aceita um ou múltiplos roles separados por vírgula:
 *   role:manager           → apenas manager
 *   role:cashier,manager   → cashier OU manager
 *   role:waiter            → apenas waiter
 *
 * Uso em api.php:
 *   Route::middleware('role:cashier,manager')->prefix('caixa')->group(...)
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Usuário inativo.'], 403);
        }

        $userRole = $user->role instanceof \BackedEnum
            ? $user->role->value
            : (string) $user->role;

        if (!in_array($userRole, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Acesso negado para esta role.',
                'required' => $allowedRoles,
                'current'  => $userRole,
            ], 403);
        }

        return $next($request);
    }
}
