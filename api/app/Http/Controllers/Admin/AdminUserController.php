<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    /**
     * Lista apenas staff (waiter, kitchen, manager) — nunca clientes.
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::where('restaurant_id', $request->user()->restaurant_id)
            ->whereIn('role', ['waiter', 'kitchen', 'manager', 'cashier'])
            ->orderByRaw("FIELD(role, 'manager', 'waiter', 'kitchen', 'cashier')")
            ->get(['id', 'name', 'apelido', 'phone', 'email', 'role', 'is_active', 'created_at']);

        return response()->json(['users' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'apelido'  => 'nullable|string|max:50',
            'phone'    => 'required|string|max:20',
            'email'    => 'nullable|email|max:150',
            'password' => 'required|string|min:4|max:100',
            'role'     => 'required|string|in:waiter,kitchen,manager,cashier',
        ]);

        $phoneDigits = preg_replace('/[^0-9]/', '', $data['phone']);

        // Telefone único entre staff em TODA a plataforma (permite login sem
        // código de restaurante — o tenant é derivado do usuário).
        $phoneExists = User::whereIn('role', ['waiter', 'kitchen', 'manager', 'cashier'])
            ->where(function ($q) use ($data, $phoneDigits) {
                $q->where('phone', $data['phone'])
                  ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phoneDigits]);
            })
            ->exists();

        if ($phoneExists) {
            return response()->json(['message' => 'Já existe um funcionário com este telefone na plataforma.'], 422);
        }

        // Apelido único POR RESTAURANTE (login agora exige restaurant_slug,
        // então apelidos podem se repetir entre tenants diferentes).
        if (!empty($data['apelido'])) {
            $apelidoExists = User::where('restaurant_id', $restaurantId)
                ->whereNotNull('apelido')
                ->whereRaw('LOWER(TRIM(apelido)) = ?', [strtolower(trim($data['apelido']))])
                ->exists();

            if ($apelidoExists) {
                return response()->json(['message' => 'Este apelido já está em uso.'], 422);
            }
        }

        $user = User::create([
            'restaurant_id' => $restaurantId,
            'name'          => $data['name'],
            'apelido'       => !empty($data['apelido']) ? trim($data['apelido']) : null,
            'phone'         => $phoneDigits,
            'email'         => $data['email'] ?? null,
            'password'      => Hash::make($data['password']),
            'role'          => $data['role'],
            'is_active'     => true,
        ]);

        return response()->json([
            'message' => 'Funcionário criado.',
            'user'    => $user->only(['id', 'name', 'apelido', 'phone', 'email', 'role', 'is_active']),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeRestaurant($user, $request);
        $this->ensureStaff($user);

        $data = $request->validate([
            'name'      => 'sometimes|string|max:100',
            'apelido'   => 'nullable|string|max:50',
            'phone'     => 'sometimes|string|max:20',
            'email'     => 'nullable|email|max:150',
            'password'  => 'nullable|string|min:4|max:100',
            'role'      => 'sometimes|string|in:waiter,kitchen,manager,cashier',
            'is_active' => 'boolean',
        ]);

        // Apelido único por restaurante (excluindo o próprio usuário)
        if (array_key_exists('apelido', $data) && !empty($data['apelido'])) {
            $apelidoExists = User::where('restaurant_id', $user->restaurant_id)
                ->whereNotNull('apelido')
                ->whereRaw('LOWER(TRIM(apelido)) = ?', [strtolower(trim($data['apelido']))])
                ->where('id', '!=', $user->id)
                ->exists();

            if ($apelidoExists) {
                return response()->json(['message' => 'Este apelido já está em uso.'], 422);
            }
            $data['apelido'] = trim($data['apelido']);
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (isset($data['phone'])) {
            $data['phone'] = preg_replace('/[^0-9]/', '', $data['phone']);

            // Telefone único entre staff em toda a plataforma (excluindo o próprio).
            $phoneTaken = User::whereIn('role', ['waiter', 'kitchen', 'manager', 'cashier'])
                ->where('id', '!=', $user->id)
                ->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$data['phone']])
                ->exists();

            if ($phoneTaken) {
                return response()->json(['message' => 'Já existe um funcionário com este telefone na plataforma.'], 422);
            }
        }

        $user->update($data);

        if (isset($data['is_active']) && !$data['is_active']) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Funcionário atualizado.',
            'user'    => $user->fresh()->only(['id', 'name', 'apelido', 'phone', 'email', 'role', 'is_active']),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeRestaurant($user, $request);
        $this->ensureStaff($user);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Não é possível excluir a si mesmo.'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Funcionário excluído.']);
    }

    private function authorizeRestaurant($model, Request $request): void
    {
        if ($model->restaurant_id !== $request->user()->restaurant_id) {
            abort(403, 'Acesso negado.');
        }
    }

    private function ensureStaff(User $user): void
    {
        if ($user->role === UserRole::CLIENT) {
            abort(422, 'Não é possível gerenciar clientes por esta rota.');
        }
    }
}
