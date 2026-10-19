<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Clientes do restaurante (contas e clientes anônimos da mesa).
 *
 * Sem e-mail/SMS configurados, "esqueci a senha" é resolvido aqui: o gerente
 * redefine o acesso e o cliente cria a conta de novo com o mesmo telefone.
 * Também desfaz cadastro feito com o telefone de outra pessoa.
 */
class AdminClientController extends Controller
{
    /**
     * GET /api/admin/clients?search=&only_accounts=1
     */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $digits = preg_replace('/[^0-9]/', '', $search);

        $clients = User::where('restaurant_id', $request->user()->restaurant_id)
            ->where('role', 'client')
            ->whereNotNull('phone')
            ->when($request->boolean('only_accounts'), fn ($q) => $q->whereNotNull('registered_at'))
            ->when($search !== '', function ($q) use ($search, $digits) {
                $q->where(function ($q) use ($search, $digits) {
                    $q->where('name', 'like', '%' . $search . '%');
                    if ($digits !== '') {
                        $q->orWhere('phone', 'like', '%' . $digits . '%');
                    }
                });
            })
            ->withCount('orders')
            ->withMax('sessions', 'created_at')
            ->orderByRaw('registered_at IS NULL')
            ->orderByDesc('sessions_max_created_at')
            ->paginate(30, ['id', 'name', 'phone', 'registered_at', 'created_at']);

        $clients->getCollection()->transform(fn (User $u) => [
            'id'            => $u->id,
            'name'          => $u->name,
            'phone'         => $u->phone,
            'has_account'   => $u->registered_at !== null,
            'registered_at' => $u->registered_at?->toIso8601String(),
            'orders_count'  => $u->orders_count,
            'last_visit_at' => $u->sessions_max_created_at
                ? Carbon::parse($u->sessions_max_created_at)->toIso8601String()
                : null,
        ]);

        return response()->json($clients);
    }

    /**
     * POST /api/admin/clients/{user}/reset-account
     *
     * Remove a senha e desconecta o cliente. O histórico de pedidos continua;
     * ao se cadastrar de novo, ele vê só os pedidos a partir dali.
     */
    public function resetAccount(Request $request, User $user): JsonResponse
    {
        if ($user->restaurant_id !== $request->user()->restaurant_id || !$user->isClient()) {
            abort(403, 'Acesso negado.');
        }

        if (!$user->hasAccount()) {
            return response()->json(['message' => 'Este cliente não tem conta.'], 422);
        }

        $user->update([
            'password'             => null,
            'registered_at'        => null,
            'terms_accepted_at'    => null,
            'terms_version'        => null,
            'history_visible_from' => null,
        ]);

        $user->tokens()->delete();

        return response()->json(['message' => 'Acesso redefinido. O cliente pode criar a conta de novo.']);
    }
}
