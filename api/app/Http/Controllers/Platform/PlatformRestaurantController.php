<?php

namespace App\Http\Controllers\Platform;

use App\Enums\DeploymentMode;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Provisionamento de restaurantes pelo super admin da plataforma.
 * Acesso restrito por middleware 'role:super_admin'. Opera cross-tenant:
 * Restaurant e User não usam o escopo de restaurante, então não há
 * filtragem implícita aqui.
 */
class PlatformRestaurantController extends Controller
{
    public function index(): JsonResponse
    {
        $restaurants = Restaurant::query()
            ->withCount('users')
            ->with('subscription')
            ->orderBy('name')
            ->get()
            ->map(fn (Restaurant $r) => $this->summary($r));

        return response()->json(['restaurants' => $restaurants]);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        $restaurant->loadCount('users');
        $restaurant->load('subscription');

        $managers = $restaurant->users()
            ->where('role', UserRole::MANAGER->value)
            ->get(['id', 'name', 'apelido', 'phone', 'email', 'is_active']);

        return response()->json([
            'restaurant' => $this->summary($restaurant),
            'managers'   => $managers,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:150',
            'slug'             => 'required|string|max:100|alpha_dash|unique:restaurants,slug',
            'address'          => 'nullable|string|max:255',
            'phone'            => 'nullable|string|max:20',
            'deployment_mode'  => ['nullable', Rule::enum(DeploymentMode::class)],
            'frontend_url'     => 'nullable|url|max:255',
            'manager_name'     => 'required|string|max:100',
            'manager_phone'    => 'required|string|max:20',
            'manager_email'    => 'nullable|email|max:150',
            'manager_password' => 'required|string|min:6|max:100',
        ]);

        if ($this->staffPhoneTaken(preg_replace('/[^0-9]/', '', $data['manager_phone']))) {
            return response()->json(['message' => 'Já existe um funcionário com este telefone na plataforma.'], 422);
        }

        $restaurant = DB::transaction(function () use ($data) {
            $restaurant = Restaurant::create([
                'name'            => $data['name'],
                'slug'            => strtolower($data['slug']),
                'address'         => $data['address'] ?? null,
                'phone'           => $data['phone'] ?? null,
                'deployment_mode' => $data['deployment_mode'] ?? DeploymentMode::DOMAIN->value,
                'frontend_url'    => $data['frontend_url'] ?? null,
                'is_active'       => true,
            ]);

            User::create([
                'restaurant_id' => $restaurant->id,
                'name'          => $data['manager_name'],
                'phone'         => $data['manager_phone'],
                'email'         => $data['manager_email'] ?? null,
                'password'      => Hash::make($data['manager_password']),
                'role'          => UserRole::MANAGER->value,
                'is_active'     => true,
            ]);

            return $restaurant;
        });

        return response()->json([
            'message'    => 'Restaurante criado.',
            'restaurant' => $this->summary($restaurant->loadCount('users')),
        ], 201);
    }

    public function update(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'sometimes|required|string|max:150',
            'slug'            => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('restaurants', 'slug')->ignore($restaurant->id)],
            'address'         => 'nullable|string|max:255',
            'phone'           => 'nullable|string|max:20',
            'deployment_mode' => ['sometimes', Rule::enum(DeploymentMode::class)],
            'frontend_url'    => 'nullable|url|max:255',
        ]);

        if (isset($data['slug'])) {
            $data['slug'] = strtolower($data['slug']);
        }

        $restaurant->update($data);

        return response()->json([
            'message'    => 'Restaurante atualizado.',
            'restaurant' => $this->summary($restaurant->loadCount('users')),
        ]);
    }

    public function toggleActive(Restaurant $restaurant): JsonResponse
    {
        $restaurant->update(['is_active' => !$restaurant->is_active]);

        return response()->json([
            'message'   => $restaurant->is_active ? 'Restaurante reativado.' : 'Restaurante suspenso.',
            'is_active' => $restaurant->is_active,
        ]);
    }

    /**
     * Cria um gerente adicional para o restaurante (ou reset de acesso).
     */
    public function storeManager(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'phone'    => 'required|string|max:20',
            'email'    => 'nullable|email|max:150',
            'password' => 'required|string|min:6|max:100',
        ]);

        $phoneDigits = preg_replace('/[^0-9]/', '', $data['phone']);

        if ($this->staffPhoneTaken($phoneDigits)) {
            return response()->json(['message' => 'Já existe um funcionário com este telefone na plataforma.'], 422);
        }

        $manager = User::create([
            'restaurant_id' => $restaurant->id,
            'name'          => $data['name'],
            'phone'         => $data['phone'],
            'email'         => $data['email'] ?? null,
            'password'      => Hash::make($data['password']),
            'role'          => UserRole::MANAGER->value,
            'is_active'     => true,
        ]);

        return response()->json([
            'message' => 'Gerente criado.',
            'manager' => $manager->only(['id', 'name', 'phone', 'email']),
        ], 201);
    }

    /** Telefone já usado por algum staff em qualquer restaurante da plataforma. */
    private function staffPhoneTaken(string $phoneDigits): bool
    {
        return User::whereIn('role', ['waiter', 'kitchen', 'manager', 'cashier'])
            ->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phoneDigits])
            ->exists();
    }

    private function summary(Restaurant $r): array
    {
        return [
            'id'              => $r->id,
            'name'            => $r->name,
            'slug'            => $r->slug,
            'address'         => $r->address,
            'phone'           => $r->phone,
            'logo_url'        => $r->logo_url,
            'deployment_mode' => $r->deployment_mode instanceof DeploymentMode ? $r->deployment_mode->value : $r->deployment_mode,
            'frontend_url'    => $r->frontend_url,
            'effective_url'   => $r->effectiveFrontendUrl(),
            'is_active'       => (bool) $r->is_active,
            'users_count'     => $r->users_count ?? $r->users()->count(),
            'created_at'      => $r->created_at,
            'billing'         => $r->relationLoaded('subscription') && $r->subscription ? [
                'monthly_amount' => $r->subscription->monthly_amount,
                'status'         => $r->subscription->status->value,
                'paid_until'     => optional($r->subscription->paid_until)->toDateString(),
                'is_overdue'     => $r->subscription->isOverdue(),
            ] : null,
        ];
    }
}
