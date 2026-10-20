<?php

namespace App\Http\Controllers\Client;

use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\AppSession;
use App\Models\Payment;
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
     * Regras de entrega do restaurante (zonas por distância, PIX pelo app),
     * último endereço usado e a mesa aberta (se houver) — o app usa isso para
     * não deixar pedir entrega por engano estando no restaurante.
     */
    public function show(Request $request): JsonResponse
    {
        $user       = $this->accountOrFail($request);
        $restaurant = $user->restaurant;

        $atTable = AppSession::atTable()
            ->where('user_id', $user->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->with('table:id,number')
            ->first();

        $methods = array_values(array_filter(
            DeliveryService::PAYMENT_METHODS,
            fn ($m) => $m !== 'pix' || $restaurant->acceptsOnlinePix()
        ));

        return response()->json([
            'restaurant'      => ['id' => $restaurant->id, 'name' => $restaurant->name],
            'delivery'        => $restaurant->deliveryConfig(),
            'last_address'    => $user->last_delivery_address,
            'active_table'    => $atTable ? ['number' => $atTable->table?->number] : null,
            'payment_methods' => $methods,
        ]);
    }

    /**
     * POST /api/client/delivery/quote — taxa para o ponto marcado no mapa:
     * free | paid | out_of_range | flat (sem zonas) | unknown (sem ponto).
     */
    public function quote(Request $request): JsonResponse
    {
        $user = $this->accountOrFail($request);

        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        return response()->json([
            'quote' => $this->deliveries->quote($user->restaurant, (float) $data['lat'], (float) $data['lng'])->toArray(),
        ]);
    }

    /**
     * POST /api/client/delivery-orders
     *
     * `fulfillment: "delivery"` é obrigatório: o app só envia depois de o
     * cliente confirmar o endereço — o pedido nunca vira entrega "por omissão".
     * A taxa é recalculada aqui pelo ponto (`location`); o app não manda taxa.
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
            'location'             => 'nullable|array',
            'location.lat'         => 'required_with:location|numeric|between:-90,90',
            'location.lng'         => 'required_with:location|numeric|between:-180,180',
            'payment_method'       => 'required|in:' . implode(',', DeliveryService::PAYMENT_METHODS),
            'change_for'           => 'nullable|numeric|min:0|max:10000',
            'notes'                => 'nullable|string|max:255',
        ]);

        try {
            $placed = $this->deliveries->place($user, $data);
        } catch (DeliveryException $e) {
            return response()->json($e->toResponse(), $e->status);
        }

        $session = $placed['session'];

        return response()->json([
            'message'  => 'Pedido de entrega enviado.',
            'delivery' => [
                'id'           => $session->id,
                'stage'        => $this->deliveries->stage($session),
                'address_line' => $session->deliveryAddressLine(),
                'fee'          => (float) $session->delivery_fee,
                'distance_km'  => $session->delivery_distance_km,
            ],
            'payment' => $placed['payment'] ? self::paymentData($placed['payment']) : null,
        ], 201);
    }

    /** Mesmo formato de GET /payments/{id} — a tela de PIX do app reaproveita. */
    public static function paymentData(Payment $payment): array
    {
        return [
            'id'             => $payment->id,
            'amount'         => (float) $payment->amount,
            'method'         => $payment->method,
            'status'         => $payment->status,
            'qr_code'        => $payment->qr_code,
            'qr_code_base64' => $payment->qr_code_base64,
            'ticket_url'     => $payment->ticket_url,
            'expires_at'     => $payment->expires_at?->toIso8601String(),
            'paid_at'        => $payment->paid_at?->toIso8601String(),
        ];
    }

    private function accountOrFail(Request $request)
    {
        $user = $request->user();

        abort_unless($user->hasAccount(), 403, 'Crie sua conta para pedir entrega.');

        return $user;
    }
}
