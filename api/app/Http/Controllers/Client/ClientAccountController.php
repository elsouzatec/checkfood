<?php

namespace App\Http\Controllers\Client;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;
use App\Services\Delivery\DeliveryService;
use App\Services\Session\TableSessionService;
use App\Services\Session\TableUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Área logada do cliente (conta por restaurante). Rotas sob role:client;
 * o token da mesa de um cliente SEM conta não abre nada aqui além de /me —
 * senão o histórico ficaria exposto a quem só digitou o telefone na mesa.
 */
class ClientAccountController extends Controller
{
    public function __construct(
        private TableSessionService $tableSessions,
        private DeliveryService $deliveries
    ) {}

    /**
     * GET /api/client/me
     */
    public function show(Request $request): JsonResponse
    {
        $user    = $request->user();
        $session = AppSession::atTable()->where('user_id', $user->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->with('table:id,number')
            ->latest()
            ->first();

        $restaurant = $user->restaurant;

        return response()->json([
            'user'       => self::accountData($user),
            'restaurant' => [
                'id'       => $restaurant->id,
                'slug'     => $restaurant->slug,
                'name'     => $restaurant->name,
                'logo_url' => $restaurant->logo_url,
                'delivery' => $restaurant->deliveryConfig(),
            ],
            'active_session' => $session ? [
                'id'           => $session->id,
                'status'       => $session->status->value,
                'table_number' => $session->table?->number,
            ] : null,
        ]);
    }

    /**
     * PUT /api/client/me — nome e/ou senha (troca exige a senha atual).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->accountOrFail($request);

        $data = $request->validate([
            'name'             => 'sometimes|required|string|min:2|max:100',
            'current_password' => 'required_with:password|string',
            'password'         => 'sometimes|required|string|min:8|max:100',
        ]);

        if (isset($data['password'])) {
            if (!Hash::check($data['current_password'], $user->password)) {
                return response()->json(['message' => 'Senha atual incorreta.'], 422);
            }

            // Derruba os outros aparelhos; o atual continua logado.
            $current = $user->currentAccessToken();
            $user->tokens()
                ->where('name', 'client-account')
                ->when($current instanceof PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))
                ->delete();
        }

        $user->update(array_intersect_key($data, array_flip(['name', 'password'])));

        return response()->json(['user' => self::accountData($user->fresh())]);
    }

    /**
     * DELETE /api/client/me — exclusão da conta (LGPD).
     *
     * Os pedidos continuam no histórico do restaurante, mas sem nome, telefone
     * ou senha: o cadastro vira um "Cliente" anônimo.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->accountOrFail($request);

        $request->validate(['password' => 'required|string']);

        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 422);
        }

        $openSession = AppSession::where('user_id', $user->id)
            ->where('status', '!=', SessionStatus::CLOSED->value)
            ->exists();

        if ($openSession) {
            return response()->json(['message' => 'Feche a conta da mesa antes de excluir seu cadastro.'], 409);
        }

        DB::transaction(function () use ($user) {
            AppSession::where('user_id', $user->id)->update(['device_id' => null]);

            $user->update([
                'name'                 => 'Cliente',
                'phone'                => null,
                'password'             => null,
                'registered_at'        => null,
                'terms_accepted_at'    => null,
                'terms_version'        => null,
                'history_visible_from' => null,
            ]);

            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Conta excluída.']);
    }

    /**
     * GET /api/client/orders — histórico da conta neste restaurante.
     */
    public function orders(Request $request): JsonResponse
    {
        $user = $this->accountOrFail($request);

        $page = Order::where('user_id', $user->id)
            ->when($user->history_visible_from, fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->with(['items.product:id,name', 'table:id,number', 'session'])
            ->orderByDesc('created_at')
            ->paginate(20);

        // Uma entrega pode virar vários pedidos (um por setor) — o app agrupa
        // pela sessão e mostra etapa, endereço e taxa uma vez só.
        $deliveries = $page->getCollection()
            ->map->session
            ->filter(fn ($s) => $s?->isDelivery())
            ->unique('id')
            ->mapWithKeys(fn (AppSession $s) => [$s->id => [
                'stage'          => $this->deliveries->stage($s),
                'address_line'   => $s->deliveryAddressLine(),
                'fee'            => (float) $s->delivery_fee,
                'payment_method' => $s->payment_method_chosen,
                'change_for'     => $s->change_for !== null ? (float) $s->change_for : null,
            ]]);

        $page->getCollection()->transform(fn (Order $order) => [
            'id'           => $order->id,
            'session_id'   => $order->session_id,
            'type'         => $order->session?->isDelivery() ? 'entrega' : 'mesa',
            'status'       => $order->status->value,
            'created_at'   => $order->created_at?->toIso8601String(),
            'table_number' => $order->table?->number,
            'total'        => $order->total(),
            'items'        => $order->items->map(fn ($item) => [
                'name'       => $item->product?->name,
                'quantity'   => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'notes'      => $item->notes,
            ])->all(),
        ]);

        return response()->json(array_merge($page->toArray(), ['deliveries' => (object) $deliveries->all()]));
    }

    /**
     * POST /api/client/tables/link — entrar na mesa já identificado pela conta.
     * Mesmo corpo de resposta de POST /sessions (token da sessão da mesa).
     */
    public function linkTable(Request $request): JsonResponse
    {
        $user = $this->accountOrFail($request);

        $request->validate(['table_token' => 'required|uuid']);

        // Sem o escopo: a mesa de outro restaurante precisa ser distinguida de
        // "mesa não existe" para o app oferecer a entrada pelo telefone.
        $table = Table::withoutRestaurantScope()
            ->where('qr_code_token', $request->table_token)
            ->first();

        if (!$table) {
            return response()->json(['message' => 'Mesa não encontrada.'], 404);
        }

        if ($table->restaurant_id !== $user->restaurant_id) {
            return response()->json([
                'message'          => 'Esta mesa é de outro restaurante. Sua conta vale só onde você se cadastrou.',
                'other_restaurant' => true,
            ], 409);
        }

        try {
            $opened = $this->tableSessions->open($table, $user);
        } catch (TableUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(
            $this->tableSessions->payload($table, $user, $opened),
            $opened['created'] ? 201 : 200
        );
    }

    // ─── Helpers ────────────────────────────────────────────

    /** Dados da conta expostos ao app — nunca senha nem campos internos. */
    public static function accountData(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'phone'         => $user->phone,
            'has_account'   => $user->hasAccount(),
            'registered_at' => $user->registered_at?->toIso8601String(),
        ];
    }

    private function accountOrFail(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->hasAccount(), 403, 'Crie sua conta para acessar.');

        return $user;
    }
}
