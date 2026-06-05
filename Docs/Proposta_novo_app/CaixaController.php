<?php

namespace App\Http\Controllers\Caixa;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App Caixa — recebe sessões encaminhadas pelo garçom e processa pagamento.
 *
 * Fluxo:
 *  1. Garçom escolhe meio de pagamento e clica "Encaminhar ao caixa"
 *     → POST /api/garcom/sessions/{id}/forward-cashier (no WaiterSessionController)
 *     → sessão vai para AT_CASHIER, mesa libera
 *  2. Caixa abre o app, vê fila de sessões AT_CASHIER
 *     → GET /api/caixa/queue
 *  3. Cliente chega ao caixa com QR físico ou código da mesa
 *     → GET /api/caixa/sessions/{id} (detalhes completos)
 *  4. Caixa confirma o pagamento (pode dividir entre múltiplos meios)
 *     → POST /api/caixa/sessions/{id}/confirm-payment
 *     → sessão vai para CLOSED, registros em payments
 *
 * Acesso: roles cashier, manager (manager para contingência/auditoria).
 */
class CaixaController extends Controller
{
    /**
     * GET /api/caixa/queue
     *
     * Lista sessões aguardando pagamento no caixa, ordenadas por chegada
     * (mais antigas primeiro). Inclui flag `is_overdue` para destacar
     * mesas há mais de 30 min na fila.
     */
    public function queue(Request $request): JsonResponse
    {
        $user = $request->user();
        $restaurantId = $user->restaurant_id;

        $sessions = AppSession::where('restaurant_id', $restaurantId)
            ->where('status', SessionStatus::AT_CASHIER)
            ->with([
                'table:id,number,name',
                'waiter:id,name,apelido',
                'orders.items',
            ])
            ->orderBy('at_cashier_at', 'asc')
            ->get();

        $now = now();
        $overdueThreshold = 30; // minutos

        $payload = $sessions->map(function ($s) use ($now, $overdueThreshold) {
            $waitMin = $s->at_cashier_at
                ? $s->at_cashier_at->diffInMinutes($now)
                : 0;

            return [
                'id'                    => $s->id,
                'table' => [
                    'id'     => $s->table?->id,
                    'number' => $s->table?->number,
                    'name'   => $s->table?->name,
                ],
                'guest_name'            => $s->guest_name,
                'waiter' => [
                    'id'      => $s->waiter?->id,
                    'name'    => $s->waiter?->name,
                    'apelido' => $s->waiter?->apelido,
                ],
                'started_at'            => $s->created_at?->toIso8601String(),
                'at_cashier_at'         => $s->at_cashier_at?->toIso8601String(),
                'wait_minutes'          => $waitMin,
                'is_overdue'            => $waitMin > $overdueThreshold,
                'payment_method_chosen' => $s->payment_method_chosen,
                'total_amount'          => $this->calcSessionTotal($s),
                'order_count'           => $s->orders->count(),
                'item_count'            => $s->orders->sum(fn ($o) => $o->items->count()),
            ];
        });

        return response()->json([
            'queue' => $payload,
            'total' => $payload->count(),
            'overdue_count' => $payload->where('is_overdue', true)->count(),
        ]);
    }

    /**
     * GET /api/caixa/sessions/{session}
     *
     * Detalhes completos da sessão para o caixa processar:
     * - Pedidos com itens, quantidades, preços
     * - Total geral
     * - Histórico da mesa (sessões anteriores no mesmo dia, contexto)
     */
    public function show(Request $request, int $sessionId): JsonResponse
    {
        $user = $request->user();

        $session = AppSession::where('restaurant_id', $user->restaurant_id)
            ->with([
                'table:id,number,name',
                'waiter:id,name,apelido',
                'orders.items.product:id,name,unit',
            ])
            ->find($sessionId);

        if (!$session) {
            throw new NotFoundHttpException('Sessão não encontrada.');
        }

        // Só lista AT_CASHIER ou CLOSED (recém-pago para conferência)
        if (!in_array($session->status, [SessionStatus::AT_CASHIER, SessionStatus::CLOSED], true)) {
            throw new NotFoundHttpException('Esta sessão não está disponível ao caixa.');
        }

        $orders = $session->orders->map(function ($order) {
            return [
                'id'          => $order->id,
                'status'      => $order->status,
                'created_at'  => $order->created_at?->toIso8601String(),
                'items'       => $order->items->map(fn ($i) => [
                    'id'         => $i->id,
                    'product_id' => $i->product_id,
                    'name'       => $i->product?->name ?? 'Item removido',
                    'quantity'   => $i->quantity,
                    'unit_price' => $i->unit_price,
                    'subtotal'   => $i->unit_price * $i->quantity,
                    'notes'      => $i->notes,
                ])->values(),
                'total'       => $order->items->sum(fn ($i) => $i->unit_price * $i->quantity),
            ];
        });

        // Pagamentos já registrados (caso o caixa tenha feito pagamento parcial)
        $payments = Payment::where('session_id', $session->id)
            ->where('status', 'paid')
            ->get(['id', 'amount', 'method', 'created_at']);

        return response()->json([
            'id'                    => $session->id,
            'status'                => $session->status->value,
            'guest_name'            => $session->guest_name,
            'table' => [
                'id'     => $session->table?->id,
                'number' => $session->table?->number,
                'name'   => $session->table?->name,
            ],
            'waiter' => [
                'id'      => $session->waiter?->id,
                'name'    => $session->waiter?->name,
                'apelido' => $session->waiter?->apelido,
            ],
            'started_at'            => $session->created_at?->toIso8601String(),
            'at_cashier_at'         => $session->at_cashier_at?->toIso8601String(),
            'closed_at'             => $session->closed_at?->toIso8601String(),
            'payment_method_chosen' => $session->payment_method_chosen,
            'orders'                => $orders,
            'total_amount'          => $orders->sum('total'),
            'paid_amount'           => $payments->sum('amount'),
            'remaining_amount'      => $orders->sum('total') - $payments->sum('amount'),
            'payments_done'         => $payments,
        ]);
    }

    /**
     * POST /api/caixa/sessions/{session}/confirm-payment
     *
     * Confirma um pagamento (parcial ou total). Suporta divisão:
     * o caixa pode chamar o endpoint múltiplas vezes para a mesma sessão
     * com meios diferentes. A sessão só vai para CLOSED quando o total
     * de pagamentos cobrir o total da conta.
     *
     * Body:
     *   amount: float (obrigatório)
     *   method: pix|dinheiro|cartao|maquininha (obrigatório)
     *   notes:  string (opcional)
     */
    public function confirmPayment(Request $request, int $sessionId): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|in:pix,dinheiro,cartao,maquininha',
            'notes'  => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        $session = AppSession::where('restaurant_id', $user->restaurant_id)
            ->with('orders.items')
            ->find($sessionId);

        if (!$session) {
            throw new NotFoundHttpException('Sessão não encontrada.');
        }

        if ($session->status !== SessionStatus::AT_CASHIER) {
            return response()->json([
                'message' => 'Esta sessão não está aguardando pagamento no caixa.',
                'current_status' => $session->status->value,
            ], 422);
        }

        return DB::transaction(function () use ($session, $request, $user) {
            $totalAmount = $session->orders->sum(
                fn ($o) => $o->items->sum(fn ($i) => $i->unit_price * $i->quantity)
            );
            $alreadyPaid = Payment::where('session_id', $session->id)
                ->where('status', 'paid')
                ->sum('amount');
            $remaining = $totalAmount - $alreadyPaid;

            $amount = (float) $request->input('amount');

            if ($amount > $remaining + 0.01) {
                return response()->json([
                    'message' => 'Valor informado maior que o restante.',
                    'remaining' => round($remaining, 2),
                ], 422);
            }

            // Registra o pagamento
            $payment = Payment::create([
                'restaurant_id' => $session->restaurant_id,
                'session_id'    => $session->id,
                'amount'        => $amount,
                'method'        => $request->input('method'),
                'status'        => 'paid',
                'created_by'    => $user->id,
                'paid_at'       => now(),
            ]);

            // Recalcula se cobriu o total
            $newPaid = $alreadyPaid + $amount;
            $isFullyPaid = ($newPaid + 0.01) >= $totalAmount;

            if ($isFullyPaid) {
                $session->markAsClosed();
            }

            return response()->json([
                'payment' => [
                    'id'      => $payment->id,
                    'amount'  => $payment->amount,
                    'method'  => $payment->method,
                    'paid_at' => $payment->paid_at->toIso8601String(),
                ],
                'session' => [
                    'id'                => $session->id,
                    'status'            => $session->fresh()->status->value,
                    'total_amount'      => round($totalAmount, 2),
                    'paid_amount'       => round($newPaid, 2),
                    'remaining_amount'  => round($totalAmount - $newPaid, 2),
                    'fully_paid'        => $isFullyPaid,
                ],
            ]);
        });
    }

    /**
     * Calcula total de uma sessão somando todos os order_items não-cancelados.
     */
    private function calcSessionTotal(AppSession $session): float
    {
        return (float) $session->orders->sum(
            fn ($o) => $o->items->sum(fn ($i) => $i->unit_price * $i->quantity)
        );
    }
}
