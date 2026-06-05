<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /** Papéis que fazem login de operação (não-cliente, não-plataforma). */
    private const STAFF_ROLES = ['waiter', 'kitchen', 'cashier', 'manager'];

    /**
     * POST /api/auth/login
     *
     * Aceita um dos três identificadores + senha:
     *   { phone, password }                      ← telefone é único entre staff na plataforma
     *   { email, password }                      ← global (super admin / manager corporativo)
     *   { apelido, password, restaurant_slug }   ← apelido só é único por restaurante
     *
     * Multi-tenant:
     * - phone é único entre staff em toda a plataforma → dispensa o código do
     *   restaurante; o tenant é derivado do próprio usuário. (slug ainda é aceito
     *   como desempate em caso de colisão legada.)
     * - apelido PODE colidir entre restaurantes → restaurant_slug obrigatório.
     * - email é global → slug opcional.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone'           => 'nullable|string',
            'email'           => 'nullable|email',
            'apelido'         => 'nullable|string',
            'restaurant_slug' => 'nullable|string|max:100',
            'password'        => 'required|string',
        ]);

        if (!$request->filled('phone') && !$request->filled('email') && !$request->filled('apelido')) {
            return response()->json([
                'message' => 'Informe telefone, e-mail ou apelido para fazer login.',
            ], 422);
        }

        // Apelido pode repetir entre restaurantes → exige o código. Telefone é
        // único entre staff, então dispensa.
        if ($request->filled('apelido') && !$request->filled('restaurant_slug')) {
            return response()->json([
                'message' => 'Para login por apelido, informe o código do restaurante.',
            ], 422);
        }

        $restaurant = null;
        if ($request->filled('restaurant_slug')) {
            $restaurant = Restaurant::where('slug', $request->restaurant_slug)->first();

            if (!$restaurant) {
                return response()->json(['message' => 'Restaurante não encontrado.'], 404);
            }

            if (!$restaurant->is_active) {
                return response()->json(['message' => 'Restaurante inativo.'], 403);
            }
        }

        $user = null;

        if ($request->filled('phone')) {
            $digits = preg_replace('/[^0-9]/', '', $request->phone);

            $query = User::whereIn('role', self::STAFF_ROLES)
                ->where(function ($q) use ($request, $digits) {
                    $q->where('phone', $request->phone)
                      ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$digits]);
                });

            // Sem slug → busca global entre staff (telefone é único).
            // Com slug → restringe ao restaurante (desempate de colisão legada).
            if ($restaurant) {
                $query->where('restaurant_id', $restaurant->id);
            }

            $matches = $query->limit(2)->get();

            if ($matches->count() > 1) {
                return response()->json([
                    'message'                  => 'Telefone em uso em mais de um restaurante. Informe o código do restaurante.',
                    'requires_restaurant_slug' => true,
                ], 409);
            }

            $user = $matches->first();

        } elseif ($request->filled('email')) {
            $query = User::where('email', $request->email);
            if ($restaurant) {
                $query->where('restaurant_id', $restaurant->id);
            }
            $user = $query->first();

        } elseif ($request->filled('apelido')) {
            $user = User::where('restaurant_id', $restaurant->id)
                ->whereNotNull('apelido')
                ->whereRaw('LOWER(TRIM(apelido)) = ?', [
                    strtolower(trim($request->apelido))
                ])
                ->first();
        }

        if (!$user || !$user->password || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Usuário inativo.'], 403);
        }

        $isSuperAdmin = $user->role === UserRole::SUPER_ADMIN;

        // Super admin é da plataforma e não pertence a nenhum tenant; demais
        // usuários só entram se o restaurante deles estiver ativo.
        if (!$isSuperAdmin && (!$user->restaurant || !$user->restaurant->is_active)) {
            return response()->json(['message' => 'Restaurante inativo.'], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken($user->role->value)->plainTextToken;

        $userRestaurant = $user->restaurant;

        return response()->json([
            'message' => 'Login realizado.',
            'token'   => $token,
            'user'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'apelido'       => $user->apelido,
                'phone'         => $user->phone,
                'role'          => $user->role->value,
                'restaurant_id' => $user->restaurant_id,
            ],
            'restaurant' => $userRestaurant ? [
                'id'       => $userRestaurant->id,
                'slug'     => $userRestaurant->slug,
                'name'     => $userRestaurant->name,
                'logo_url' => $userRestaurant->logo_url,
            ] : null,
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado.']);
    }
}
