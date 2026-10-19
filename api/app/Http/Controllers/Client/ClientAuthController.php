<?php

namespace App\Http\Controllers\Client;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Conta do cliente — POR RESTAURANTE.
 *
 * A conta é o próprio cliente da mesa (users.role=client daquele restaurante)
 * com senha. O mesmo telefone pode ter contas independentes em restaurantes
 * diferentes; cada uma vê só o próprio restaurante (e, no futuro, a carteira
 * daquele restaurante).
 */
class ClientAuthController extends Controller
{
    /** Versão dos termos aceitos no cadastro — trocar quando o texto mudar. */
    public const TERMS_VERSION = '2026-10-19';

    /** Validade do token da conta (o da mesa é renovado a cada sessão). */
    private const TOKEN_DAYS = 90;

    /**
     * POST /api/client/register
     *
     * O restaurante vem do link dele (restaurant_slug) ou do QR da mesa
     * (table_token). Se o telefone já passou pela mesa sem conta, o cadastro
     * existente é aproveitado — mas o histórico anterior fica oculto até o
     * telefone ser verificado (history_visible_from).
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'restaurant_slug' => 'required_without:table_token|nullable|string|max:100',
            'table_token'     => 'required_without:restaurant_slug|nullable|uuid',
            'name'            => 'required|string|min:2|max:100',
            'phone'           => 'required|string|max:20',
            'password'        => 'required|string|min:8|max:100',
            'accept_terms'    => 'accepted',
        ]);

        $restaurant = $this->resolveRestaurant($data['restaurant_slug'] ?? null, $data['table_token'] ?? null);

        if (!$restaurant) {
            return response()->json(['message' => 'Restaurante não encontrado.'], 404);
        }

        $phoneDigits = $this->phoneDigits($data['phone']);

        if (!$phoneDigits) {
            return response()->json(['message' => 'Informe um telefone válido com DDD.'], 422);
        }

        $user = DB::transaction(function () use ($restaurant, $phoneDigits, $data) {
            $user = $this->findClient($restaurant->id, $phoneDigits);

            if ($user?->hasAccount()) {
                return null;
            }

            $account = [
                'name'                 => trim($data['name']),
                'password'             => $data['password'],
                'registered_at'        => now(),
                'terms_accepted_at'    => now(),
                'terms_version'        => self::TERMS_VERSION,
                'history_visible_from' => now(),
            ];

            if ($user) {
                $user->update($account);

                return $user;
            }

            return User::create($account + [
                'restaurant_id' => $restaurant->id,
                'phone'         => $phoneDigits,
                'role'          => 'client',
            ]);
        });

        if (!$user) {
            return response()->json([
                'message'        => 'Este telefone já tem conta neste restaurante. Entre com sua senha.',
                'account_exists' => true,
            ], 422);
        }

        return response()->json($this->authenticated($user, $restaurant), 201);
    }

    /**
     * POST /api/client/login
     *
     * Telefone + senha. Sem restaurante informado, procura a conta em todos;
     * se a senha confere em mais de um, devolve a lista para o cliente escolher.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'           => 'required|string|max:20',
            'password'        => 'required|string',
            'restaurant_slug' => 'nullable|string|max:100',
            'table_token'     => 'nullable|uuid',
        ]);

        $phoneDigits = $this->phoneDigits($data['phone']);
        $restaurant  = null;

        if (!empty($data['restaurant_slug']) || !empty($data['table_token'])) {
            $restaurant = $this->resolveRestaurant($data['restaurant_slug'] ?? null, $data['table_token'] ?? null);

            if (!$restaurant) {
                return response()->json(['message' => 'Restaurante não encontrado.'], 404);
            }
        }

        $candidates = $phoneDigits
            ? User::where('role', 'client')
                ->whereNotNull('registered_at')
                ->whereNotNull('password')
                ->where('is_active', true)
                ->when($restaurant, fn ($q) => $q->where('restaurant_id', $restaurant->id))
                ->where(function ($q) use ($phoneDigits) {
                    $q->where('phone', $phoneDigits)
                      ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phoneDigits]);
                })
                ->with('restaurant')
                ->limit(20)
                ->get()
            : collect();

        $matches = $candidates
            ->filter(fn (User $u) => $u->restaurant?->is_active && Hash::check($data['password'], $u->password))
            ->values();

        if ($matches->isEmpty()) {
            return response()->json(['message' => 'Telefone ou senha inválidos.'], 401);
        }

        if ($matches->count() > 1) {
            return response()->json([
                'message'             => 'Você tem conta em mais de um restaurante. Escolha qual acessar.',
                'requires_restaurant' => true,
                'restaurants'         => $matches->map(fn (User $u) => $this->restaurantData($u->restaurant))->all(),
            ], 409);
        }

        $user = $matches->first();

        return response()->json($this->authenticated($user, $user->restaurant));
    }

    /**
     * POST /api/client/account
     *
     * Cliente que já está na mesa (token da sessão, sem conta) cria a senha.
     * Os pedidos desta visita entram no histórico; os de visitas anteriores
     * ficam ocultos até o telefone ser verificado.
     */
    public function upgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'nullable|string|min:2|max:100',
            'password'     => 'required|string|min:8|max:100',
            'accept_terms' => 'accepted',
        ]);

        $user = $request->user();

        if ($user->hasAccount()) {
            return response()->json(['message' => 'Você já tem conta neste restaurante.'], 422);
        }

        $currentSession = AppSession::atTable()->where('user_id', $user->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->latest()
            ->first();

        $user->update(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
        ]) + [
            'password'             => $data['password'],
            'registered_at'        => now(),
            'terms_accepted_at'    => now(),
            'terms_version'        => self::TERMS_VERSION,
            'history_visible_from' => $currentSession?->created_at ?? now(),
        ]);

        return response()->json($this->authenticated($user, $user->restaurant), 201);
    }

    // ─── Helpers ────────────────────────────────────────────

    private function authenticated(User $user, Restaurant $restaurant): array
    {
        $token = $user->createToken('client-account', ['*'], now()->addDays(self::TOKEN_DAYS))->plainTextToken;

        return [
            'token'      => $token,
            'user'       => ClientAccountController::accountData($user),
            'restaurant' => $this->restaurantData($restaurant),
        ];
    }

    private function restaurantData(Restaurant $restaurant): array
    {
        return [
            'id'       => $restaurant->id,
            'slug'     => $restaurant->slug,
            'name'     => $restaurant->name,
            'logo_url' => $restaurant->logo_url,
        ];
    }

    /** Restaurante ativo pelo slug do link ou pelo QR da mesa. */
    private function resolveRestaurant(?string $slug, ?string $tableToken): ?Restaurant
    {
        $restaurant = $slug
            ? Restaurant::where('slug', $slug)->first()
            : Table::where('qr_code_token', $tableToken)->first()?->restaurant;

        return $restaurant?->is_active ? $restaurant : null;
    }

    private function findClient(int $restaurantId, string $phoneDigits): ?User
    {
        return User::where('restaurant_id', $restaurantId)
            ->where('role', 'client')
            ->where(function ($q) use ($phoneDigits) {
                $q->where('phone', $phoneDigits)
                  ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phoneDigits]);
            })
            ->lockForUpdate()
            ->first();
    }

    /** Só dígitos; null se não parece um telefone brasileiro com DDD. */
    private function phoneDigits(string $phone): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        return strlen($digits) >= 10 && strlen($digits) <= 13 ? $digits : null;
    }
}
