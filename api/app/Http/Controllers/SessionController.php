<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\TableStatus;
use App\Models\AppSession;
use App\Models\Payment;
use App\Models\Table;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __construct(private PaymentService $paymentService) {}

    /**
     * POST /api/sessions
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'table_token' => 'required|uuid',
            'phone'       => 'required|string|min:8|max:20',
            'name'        => 'nullable|string|max:100',
        ]);

        $table = Table::where('qr_code_token', $request->table_token)->first();

        if (!$table) {
            return response()->json(['message' => 'Mesa não encontrada.'], 404);
        }

        $restaurantId = $table->restaurant_id;
        $phoneDigits  = preg_replace('/[^0-9]/', '', $request->phone);

        $activeSession = AppSession::where('table_id', $table->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->first();

        if ($activeSession) {
            // Sessão guest (aberta pelo garçom) — user_id é null.
            // Cliente não pode entrar; garçom gerencia pelo próprio app.
            if ($activeSession->user_id === null) {
                return response()->json(['message' => 'Mesa ocupada. Solicite assistência ao garçom.'], 409);
            }

            $sessionUser  = User::find($activeSession->user_id);
            $sessionPhone = preg_replace('/[^0-9]/', '', $sessionUser->phone ?? '');

            if ($sessionPhone === $phoneDigits) {
                $token   = $sessionUser->createToken('client')->plainTextToken;
                $payment = $activeSession->isClosing()
                    ? Payment::where('session_id', $activeSession->id)->latest()->first()
                    : null;

                $restaurant = $table->restaurant;

                return response()->json([
                    'message' => 'Sessão retomada.',
                    'session' => $activeSession,
                    'token'   => $token,
                    'table'   => $table,
                    'user'    => $sessionUser,
                    'restaurant' => [
                        'id'       => $restaurant->id,
                        'slug'     => $restaurant->slug,
                        'name'     => $restaurant->name,
                        'logo_url' => $restaurant->logo_url,
                    ],
                    'payment' => $payment ? ['id' => $payment->id, 'amount' => $payment->amount] : null,
                ]);
            }

            return response()->json(['message' => 'Mesa ocupada por outro cliente.'], 409);
        }

        $user = User::where('restaurant_id', $restaurantId)
            ->where(function ($q) use ($request, $phoneDigits) {
                $q->where('phone', $request->phone)
                  ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phoneDigits]);
            })->first();

        if (!$user) {
            $user = User::create([
                'restaurant_id' => $restaurantId,
                'name'          => $request->name ?? 'Cliente',
                'phone'         => $phoneDigits,
                'email'         => null,
                'password'      => null,
                'role'          => 'client',
            ]);
        }

        $session = AppSession::create([
            'restaurant_id' => $restaurantId,
            'table_id'      => $table->id,
            'user_id'       => $user->id,
            'session_token' => Str::uuid()->toString(),
            'device_id'     => $phoneDigits,
            'status'        => SessionStatus::ACTIVE,
        ]);

        $table->update(['status' => TableStatus::OCUPADA]);

        $user->tokens()->where('name', 'client')->delete();
        $token = $user->createToken('client')->plainTextToken;

        $restaurant = $table->restaurant;

        return response()->json([
            'message' => 'Sessão criada.',
            'session' => $session,
            'token'   => $token,
            'table'   => $table,
            'user'    => $user,
            'restaurant' => [
                'id'       => $restaurant->id,
                'slug'     => $restaurant->slug,
                'name'     => $restaurant->name,
                'logo_url' => $restaurant->logo_url,
            ],
            'payment' => null,
        ], 201);
    }

    /**
     * POST /api/sessions/{session}/close
     *
     * Inicia o processo de pagamento.
     *
     * Parâmetro opcional: ?force=true
     *   - false (padrão): verifica pedidos pendentes e retorna aviso se existirem
     *   - true: força entrega de todos os pedidos pendentes antes de fechar
     */
    public function close(AppSession $session, Request $request): JsonResponse
    {
        $user = $request->user();

        // Autorização
        if ($user->role->value === 'client') {
            if ($session->user_id !== $user->id) {
                return response()->json(['message' => 'Acesso negado.'], 403);
            }

            // Cliente só fecha a própria conta se o restaurante liberou pagamento pelo app.
            if (! $session->restaurant->allow_client_payment) {
                return response()->json(['message' => 'Peça a conta ao garçom.'], 403);
            }
        } else {
            if ($session->restaurant_id !== $user->restaurant_id) {
                return response()->json(['message' => 'Acesso negado.'], 403);
            }
        }

        if ($session->isClosed()) {
            return response()->json(['message' => 'Sessão já encerrada.'], 422);
        }

        // ── Verificar pedidos em estados não-terminais ──
        $pendingStatuses = [
            OrderStatus::PENDENTE_VALIDACAO->value,
            OrderStatus::CONFIRMADO->value,
            OrderStatus::PREPARANDO->value,
            OrderStatus::PRONTO->value,
        ];

        $pendingOrders = $session->orders()
            ->whereIn('status', $pendingStatuses)
            ->get();

        if ($pendingOrders->isNotEmpty() && !$request->boolean('force')) {
            return response()->json([
                'message'        => 'Existem pedidos ainda não entregues.',
                'pending_count'  => $pendingOrders->count(),
                'requires_force' => true,
            ], 422);
        }

        // force=true: marca como entregue todos os pedidos pendentes
        if ($pendingOrders->isNotEmpty()) {
            foreach ($pendingOrders as $order) {
                $order->update([
                    'status'       => OrderStatus::ENTREGUE->value,
                    'delivered_at' => now(),
                ]);
            }
        }

        $createdBy = $user->role->value === 'client' ? 'client' : 'waiter';

        // Taxa de serviço — apenas garçom/manager podem informar
        $tipAmount = 0.0;
        if ($createdBy === 'waiter') {
            $tipAmount = (float) $request->input('tip_amount', 0);
            if ($tipAmount < 0) $tipAmount = 0.0;
        }

        try {
            $payment = $this->paymentService->initiate($session, $createdBy);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Soma taxa de serviço ao amount sem alterar a assinatura do PaymentService
        if ($tipAmount > 0) {
            $payment->update(['amount' => $payment->amount + $tipAmount]);
            $payment->refresh();
        }

        return response()->json([
            'message' => 'Conta fechada. Selecione o método de pagamento.',
            'payment' => [
                'id'             => $payment->id,
                'amount'         => $payment->amount,
                'status'         => $payment->status,
                'method'         => $payment->method,
                'qr_code'        => $payment->qr_code,
                'qr_code_base64' => $payment->qr_code_base64,
                'expires_at'     => $payment->expires_at?->toIso8601String(),
            ],
            'tip_amount' => $tipAmount,
        ]);
    }

    /**
     * GET /api/sessions/check
     */
    public function check(Request $request): JsonResponse
    {
        $user    = $request->user();
        $session = AppSession::where('user_id', $user->id)->latest()->first();

        if (!$session) {
            return response()->json(['active' => false, 'message' => 'Nenhuma sessão encontrada.'], 410);
        }

        if ($session->isClosed()) {
            return response()->json([
                'active'         => false,
                'session_id'     => $session->id,
                'session_status' => 'closed',
                'message'        => 'Sessão encerrada.',
            ], 410);
        }

        $baseResponse = [
            'active'         => true,
            'session_id'     => $session->id,
            'session_status' => $session->status->value,
            'table_id'       => $session->table_id,
            'has_waiter'     => $session->waiter_id !== null,
        ];

        if ($session->isClosing()) {
            $payment = Payment::where('session_id', $session->id)
                ->where('status', 'pending')
                ->latest()
                ->first();

            $baseResponse['payment_id'] = $payment?->id;
        }

        return response()->json($baseResponse);
    }
}
