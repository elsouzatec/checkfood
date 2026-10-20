<?php

namespace App\Http\Controllers\Client;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Services\Delivery\DeliveryException;
use App\Services\Delivery\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pedido para entrega — só para quem tem conta no restaurante.
 */
class ClientDeliveryController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    /**
     * GET /api/client/delivery
     *
     * Regras de entrega do restaurante, último endereço usado e a mesa aberta
     * (se houver) — o app usa isso para não deixar pedir entrega por engano
     * estando no restaurante.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->accountOrFail($request);

        $atTable = AppSession::atTable()
            ->where('user_id', $user->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->with('table:id,number')
            ->first();

        return response()->json([
            'restaurant'   => ['id' => $user->restaurant->id, 'name' => $user->restaurant->name],
            'delivery'     => $user->restaurant->deliveryConfig(),
            'last_address' => $user->last_delivery_address,
            'active_table' => $atTable ? ['number' => $atTable->table?->number] : null,
            'payment_methods' => DeliveryService::PAYMENT_METHODS,
        ]);
    }

    /**
     * POST /api/client/delivery-orders
     *
     * `fulfillment: "delivery"` é obrigatório: o app só envia depois de o
     * cliente confirmar o endereço — o pedido nunca vira entrega "por omissão".
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->accountOrFail($request);

        $data = $request->validate([
            'fulfillment'          => 'required|in:delivery',
            'items'                => 'required|array|min:1|max:50',
            'items.*.product_id'   => 'required|integer',
            'items.*.quantity'     => 'required|integer|min:1|max:99',
            'items.*.notes'        => 'nullable|string|max:255',
            'address'              => 'required|array',
            'address.street'       => 'required|string|max:150',
            'address.number'       => 'required|string|max:20',
            'address.complement'   => 'nullable|string|max:100',
            'address.district'     => 'required|string|max:100',
            'address.reference'    => 'nullable|string|max:150',
            'payment_method'       => 'required|in:' . implode(',', DeliveryService::PAYMENT_METHODS),
            'change_for'           => 'nullable|numeric|min:0|max:10000',
            'notes'                => 'nullable|string|max:255',
        ]);

        try {
            $session = $this->deliveries->place($user, $data);
        } catch (DeliveryException $e) {
            return response()->json($e->toResponse(), $e->status);
        }

        return response()->json([
            'message'  => 'Pedido de entrega enviado.',
            'delivery' => [
                'id'           => $session->id,
                'stage'        => $this->deliveries->stage($session),
                'address_line' => $session->deliveryAddressLine(),
            ],
        ], 201);
    }

    private function accountOrFail(Request $request)
    {
        $user = $request->user();

        abort_unless($user->hasAccount(), 403, 'Crie sua conta para pedir entrega.');

        return $user;
    }
}
