<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\AppSession;
use App\Models\Payment;
use App\Models\Table;
use App\Models\User;
use App\Services\Payment\PaymentService;
use App\Services\Session\TableSessionService;
use App\Services\Session\TableUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private TableSessionService $tableSessions
    ) {}

    /**
     * POST /api/sessions
     *
     * Entrada anônima pelo QR: o cliente se identifica só pelo telefone.
     * Telefone que já tem conta exige login (409 requires_login) — senão
     * qualquer um entraria como o dono da conta digitando o número.
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

        $phoneDigits = preg_replace('/[^0-9]/', '', $request->phone);

        // Só clientes: o telefone de um funcionário digitado na mesa não pode
        // render um token com o papel dele (gerente → /api/admin).
        $user = User::where('restaurant_id', $table->restaurant_id)
            ->where('role', 'client')
            ->where(function ($q) use ($request, $phoneDigits) {
                $q->where('phone', $request->phone)
                  ->orWhereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') = ?", [$phoneDigits]);
            })->first();

        if ($user?->hasAccount()) {
            return response()->json([
                'message'        => 'Este telefone tem conta. Entre com sua senha.',
                'requires_login' => true,
            ], 409);
        }

        try {
            if (!$user) {
                // Mesa ocupada não deve criar cadastro para o telefone digitado.
                $this->tableSessions->ensureFree($table);

                $user = User::create([
                    'restaurant_id' => $table->restaurant_id,
                    'name'          => $request->name ?? 'Cliente',
                    'phone'         => $phoneDigits,
                    'email'         => null,
                    'password'      => null,
                    'role'          => 'client',
                ]);
            }

            $opened = $this->tableSessions->open($table, $user);
        } catch (TableUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(
            $this->tableSessions->payload($table, $user, $opened),
            $opened['created'] ? 201 : 200
        );
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
