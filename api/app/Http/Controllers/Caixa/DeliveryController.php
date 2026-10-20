<?php

namespace App\Http\Controllers\Caixa;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Services\Delivery\DeliveryException;
use App\Services\Delivery\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel de entregas (app Caixa — cashier/manager).
 *
 * Aceitar → (cozinha prepara) → saiu para entrega → entregue. Ao marcar
 * "entregue" a sessão vai para o caixa (AT_CASHIER) e o pagamento é
 * confirmado pelo fluxo de sempre (/caixa/sessions/{id}/confirm-payment).
 */
class DeliveryController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    /**
     * GET /api/caixa/deliveries — em andamento + encerradas hoje.
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = AppSession::delivery()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where(function ($q) {
                $q->where('status', '!=', SessionStatus::CLOSED->value)
                  ->orWhere('closed_at', '>=', now()->startOfDay());
            })
            ->with(['user:id,name,phone', 'orders.items.product:id,name'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'deliveries' => $sessions->map(fn (AppSession $s) => $this->present($s))->values(),
        ]);
    }

    /** POST /api/caixa/deliveries/{session}/accept */
    public function accept(Request $request, int $session): JsonResponse
    {
        return $this->act($request, $session, fn (AppSession $s) => $this->deliveries->accept($s, $request->user()));
    }

    /** POST /api/caixa/deliveries/{session}/dispatch */
    public function dispatch(Request $request, int $session): JsonResponse
    {
        return $this->act($request, $session, fn (AppSession $s) => $this->deliveries->dispatch($s));
    }

    /** POST /api/caixa/deliveries/{session}/delivered */
    public function delivered(Request $request, int $session): JsonResponse
    {
        return $this->act($request, $session, fn (AppSession $s) => $this->deliveries->markDelivered($s));
    }

    /** POST /api/caixa/deliveries/{session}/cancel */
    public function cancel(Request $request, int $session): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:255']);

        return $this->act($request, $session, fn (AppSession $s) => $this->deliveries->cancel($s, $request->reason));
    }

    // ─── Helpers ────────────────────────────────────────────

    private function act(Request $request, int $sessionId, callable $action): JsonResponse
    {
        $session = AppSession::delivery()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->find($sessionId);

        if (!$session) {
            return response()->json(['message' => 'Entrega não encontrada.'], 404);
        }

        try {
            $action($session);
        } catch (DeliveryException $e) {
            return response()->json($e->toResponse(), $e->status);
        }

        $session->refresh()->load(['user:id,name,phone', 'orders.items.product:id,name']);

        return response()->json(['delivery' => $this->present($session)]);
    }

    private function present(AppSession $s): array
    {
        $billable = $s->orders->reject(fn ($o) => $o->status === OrderStatus::CANCELADO);
        $subtotal = (float) $billable->sum(fn ($o) => $o->items->sum(fn ($i) => $i->unit_price * $i->quantity));

        return [
            'id'             => $s->id,
            'status'         => $s->status->value,
            'stage'          => $this->deliveries->stage($s),
            'created_at'     => $s->created_at?->toIso8601String(),
            'accepted_at'    => $s->accepted_at?->toIso8601String(),
            'dispatched_at'  => $s->dispatched_at?->toIso8601String(),
            'delivered_at'   => $s->delivered_at?->toIso8601String(),
            'closed_at'      => $s->closed_at?->toIso8601String(),
            'customer'       => ['name' => $s->user?->name, 'phone' => $s->user?->phone],
            'address'        => $s->delivery_address,
            'address_line'   => $s->deliveryAddressLine(),
            'notes'          => $s->delivery_notes,
            'payment_method' => $s->payment_method_chosen,
            'paid'           => $this->deliveries->isPaid($s),
            'change_for'     => $s->change_for !== null ? (float) $s->change_for : null,
            'distance_km'    => $s->delivery_distance_km,
            'maps_url'       => $s->deliveryMapsUrl(),
            'subtotal'       => round($subtotal, 2),
            'fee'            => (float) $s->delivery_fee,
            'total'          => $subtotal > 0 ? round($subtotal + (float) $s->delivery_fee, 2) : 0.0,
            // Cozinha terminou tudo? (orienta o botão "saiu para entrega")
            'kitchen_done'   => $billable->isNotEmpty() && $billable->every(
                fn ($o) => in_array($o->status, [OrderStatus::PRONTO, OrderStatus::ENTREGUE, OrderStatus::PAGO], true)
            ),
            'orders' => $s->orders->map(fn ($o) => [
                'id'     => $o->id,
                'status' => $o->status->value,
                'items'  => $o->items->map(fn ($i) => [
                    'name'     => $i->product?->name ?? 'Item removido',
                    'quantity' => $i->quantity,
                    'notes'    => $i->notes,
                ])->values(),
            ])->values(),
        ];
    }
}
